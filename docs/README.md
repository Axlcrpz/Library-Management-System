# SDO Quirino Library System — Developer Documentation

> Onboarding guide for a developer who needs to understand the architecture and
> business logic end-to-end. Everything here was derived by reading the actual
> codebase (`api/library_handler.php`, `index.php`, `login.php`, `auth.php`, the
> `templates/`, and `assets/js/`), not from assumptions.

## What this system is

A **library management system** for the Schools Division Office (SDO) of Quirino.
It manages two different kinds of holdings — **administrative documents/files**
(PDFs, memos, reports) and a **book catalog/inventory** — plus the people who
borrow them, deliveries of new stock, reservations, announcements, and the
administrative tooling around all of it (members, audit logs, settings, trash).

## Tech stack (no build step)

| Layer | Choice | Notes |
|-------|--------|-------|
| Language | **PHP 8.2** | Uses `match`, enums-as-strings, `str_ends_with`, first-class callable syntax |
| Database | **MySQL 8 / InnoDB** | `utf8mb4`; accessed via **PDO**, prepared statements, `ERRMODE_EXCEPTION` |
| Front-end | **Vanilla JS + Bootstrap 5** | No framework, no bundler — scripts loaded directly via `<script>` |
| Charts/extras | Chart.js, Quill (rich text), html5-qrcode, qrcodejs, SheetJS (xlsx) | All from CDN |
| Packaging | **Docker** (`php:8.2-apache` + `mysql:8` + phpMyAdmin) | Local dev also runs on **XAMPP** |
| Scheduler | `cron.php` via Task Scheduler / crontab | Every 5 minutes |

There is **no Composer, no npm, no MVC framework**. The app is intentionally a
"flat" PHP application: a few entry-point PHP pages, one large API handler, and
server-rendered HTML templates progressively enhanced with JavaScript.

## The 10,000-foot architecture

```
Browser (SPA shell)                       Server (PHP)                      MySQL
─────────────────────                     ─────────────                     ─────
index.php  ── renders ──► tab templates    auth.php ........ session+roles+CSRF
   │                                       config/database.php  PDO conn/ 503 guard
   │  fetch() GET (reads)                   │
   │  fetch() POST + X-CSRF-Token (writes)  ▼
   └───────────────────────────►  api/library_handler.php  ───────────────► 38 tables
        assets/js/app.js                     • apiRequireLogin()
        assets/js/discovery.js               • ensureLibrarySchema()  (idempotent migration)
        assets/js/inventory.js               • purgeExpiredTrash()
        assets/js/theme.js                   • CSRF gate (POST/JSON)
                                             • switch($action) → ~115 handlers
                                             • JSON envelope {success,message,data}

cron.php (CLI, every 5 min) ──► reuses library_handler.php functions:
        reservation sweep · trash purge · discovery enrich · metadata backfill · login_attempts prune
```

### Request lifecycle (every API call)

Defined at the bottom of [`api/library_handler.php`](../api/library_handler.php) (~line 1029):

1. **`if (PHP_SAPI === 'cli') return;`** — when included by `cron.php`, only the
   function definitions load; the HTTP router never runs.
2. **`apiRequireLogin()`** — no valid session ⇒ `401`. Every endpoint is authenticated.
3. **`ensureLibrarySchema($pdo)`** — idempotent migration. Short-circuits when
   `library_settings.schema_version = '13'`, so it's cheap on the hot path.
4. **`purgeExpiredTrash($pdo)`** — opportunistic cleanup of soft-deleted rows past TTL.
5. **CSRF gate** — any `POST` or JSON-body request must pass `apiRequireValidCsrf()`
   (token from the `<meta name="csrf-token">` tag, sent as `X-CSRF-Token`). GET reads are exempt.
6. **`switch ($action)`** — dispatch to one of ~115 `handle*()` functions.
7. Handler responds with `sendSuccess($data,$msg)` / `sendError($msg,$status)` —
   always the JSON envelope `{ success, message, data }`.

### Roles and UI tiers

Two independent axes drive what a user sees:

- **Role** (`users.role`): `admin` ⊃ `staff` ⊃ `viewer`. Enforced server-side by
  `require_admin()`/`require_staff()`/`userCan()` in [`auth.php`](../auth.php) and the
  `apiRequire*` guards in the handler. **Never trust the client** — the UI hides
  things, but the server re-checks every action.
- **UI tier** (derived in [`index.php`](../index.php) from role + `users.classification`):
  `admin` · `child` · `teen` · `adult`. This only changes presentation
  (theme CSS, gamification for `child`), not permissions.

## Repository map

| Path | Responsibility |
|------|----------------|
| `index.php` | Authenticated SPA shell: sidebar, nav, and all tab templates |
| `login.php` | Login + self-registration (incl. DepEd/LRN path), brute-force lockout |
| `logout.php` | Destroys session, clears cookie, redirects to login |
| `auth.php` | Session hardening, `require_*` guards, CSRF helpers, `userCan()` |
| `account_action.php` | Admin approve/reject of pending registrations (POST + CSRF) |
| `config/database.php` | Env-driven PDO connection; non-leaking `503` on failure |
| `api/library_handler.php` | **The controller** — ~115 actions, schema migration, all business logic |
| `cron.php` | Background maintenance worker (CLI) |
| `reports.php` | Standalone printable reports page |
| `templates/*.php` | Server-rendered module UIs injected into the shell as tab panes |
| `assets/js/app.js` | Core front-end: API helper, tab routing, toasts, most module logic |
| `assets/js/discovery.js` | Multi-source book discovery UI |
| `assets/js/inventory.js` | Inventory (books) management UI |
| `assets/js/theme.js` | Light/dark/system theme toggle |
| `assets/js/gamification.js` | `child` UI-tier engagement layer |
| `storage/` | Uploaded files (attachments, deliveries, announcements, avatars, profiles) |

## How to read the rest of these docs

| File | Covers | Maps to your task |
|------|--------|-------------------|
| [`system-flow.md`](system-flow.md) | The complete Login → Logout journey, decision points, per-stage DB I/O | Task 1 |
| [`modules.md`](modules.md) | One sheet per module: purpose, UX, backend, DB ops, dependencies, why | Task 2 |
| [`data-model.md`](data-model.md) | All 38 tables grouped by domain, keys, and relationships | Supporting reference |
| [`architecture-analysis.md`](architecture-analysis.md) | Overlapping features (3 add-book paths, dual circulation, triple reservations), the feature relationship map, and prioritized refactoring proposals | Tasks 3, 4, 5 |

> **A note on honesty:** this system has grown in layers, and that history is
> visible in the schema. There are **two circulation systems** and **three
> reservation/hold tables** that overlap. Rather than hide that, the analysis doc
> explains exactly why each exists and what to do about it. See
> [`architecture-analysis.md`](architecture-analysis.md).
