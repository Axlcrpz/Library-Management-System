# System Flow — Login to Logout

This is the end-to-end user journey with the major modules, user actions, system
responses, **database interactions**, and **decision points**. A rendered visual
version of this flow accompanies these docs; the text below is the authoritative,
code-referenced description.

## Legend

- **◇ Decision point** — server-side branch
- **▣ DB** — database read/write
- **→ Response** — what the server returns / what the user sees next

---

## Stage 0 — Entry & authentication gate

Every page begins by including [`auth.php`](../auth.php), which starts a hardened
session (HttpOnly, `SameSite=Lax`, `Secure` auto-on under HTTPS, strict mode).

- `index.php`, `reports.php`, and every API call run **`require_login()`** /
  **`apiRequireLogin()`**.
- ◇ **Is there a valid `$_SESSION['user_id']`?**
  - No → `Location: login.php` (pages) or `401` JSON (API).
  - Yes → continue.
- `config/database.php` opens the PDO connection. ◇ **DB reachable?** No → HTTP
  `503` "Service temporarily unavailable" (host/user details are *never* leaked).

## Stage 1 — Login

File: [`login.php`](../login.php). If already logged in, it redirects straight to
`index.php`.

```
User submits email + password (mode=login)
        │
        ▼
◇ recent failures ≥ 5 for (username, IP) in 15 min?     ▣ SELECT COUNT(*) FROM login_attempts
        │ yes → "Too many failed attempts…" (locked ~15 min)
        │ no
        ▼
▣ SELECT * FROM users WHERE username = ?
        │
        ▼
◇ account exists?            no  → "No account found"
◇ is_active = 1?             no  → "Account is inactive"
◇ status = 'approved'?       pending → "pending admin approval"; other → status message
◇ password_verify() ok?      no  → "Incorrect password"
        │ all yes
        ▼
▣ DELETE FROM login_attempts WHERE username=? AND ip=?   (clear the throttle)
Set $_SESSION[user_id, username, full_name, role] + generateCSRFToken()
→ Location: index.php
```

Every **failed** attempt does `▣ INSERT INTO login_attempts (username, ip)`. The
lockout is scoped **per (username, IP)** so an attacker cannot lock a victim out
from a different machine. (Old rows are pruned daily by `cron.php`.)

### Stage 1-alt — Self-registration (the path that creates `pending` users)

`mode=register` in `login.php`. Validates full name/email/password (≥8 chars,
confirmed). ◇ **Is it a DepEd/gov/edu email?** (`isDepEdEmail()`):

- **DepEd path** → requires a valid **12-digit LRN** + school name; may run an
  OTP/transaction flow; `▣ INSERT INTO users` with `classification` and
  `status='pending'`.
- **Regular path** → `▣ INSERT INTO users (status='pending')`.

A pending user **cannot log in** until an admin approves them (Stage 2-admin).

## Stage 2 — Session established → the SPA shell loads

File: [`index.php`](../index.php). On load it computes:

- `$isAdmin`, `$isStaff` from `role`; `▣ SELECT classification FROM users` → `$uiTier`
  (`admin`/`child`/`teen`/`adult`).
- `$navItems` — the module list, each flagged `admin => true/false`. Admin-only
  tabs are not even rendered for non-admins.
- Avatar resolution: photo > system avatar > initials (drives `av-fit-*`).

The shell renders the sidebar + **every tab template at once** (hidden panes);
JavaScript just toggles which `.tab-content` is visible. The page also exposes
`window.isAdmin/isStaff/currentRole/currentUser` and the CSRF `<meta>` tag.

## Stage 3 — The module interaction loop

This is where the user spends ~all their time. Pattern for every module:

```
User clicks a sidebar tab ──► app.js switchTab() shows that pane, lazy-loads data
        │
        ▼
READ:  fetch('api/library_handler.php?action=<x>_get', {credentials:'same-origin'})   ▣ SELECT …
        → JSON {success,data}; JS renders cards/tables
        │
        ▼
WRITE: fetch(API, {method:'POST', headers:{'X-CSRF-Token': <meta>}, body: form})      ▣ INSERT/UPDATE/DELETE
        → server re-checks role (apiRequireStaff/Admin) + CSRF, mutates, logAudit(), returns JSON
        → app.js shows a toast and refreshes the affected view
```

◇ **Role decision on every write**: handlers call `apiRequireStaff()` /
`apiRequireAdmin()` / `apiRequireLogin()` first. A `viewer` POSTing a staff action
gets `403` regardless of what the UI showed.

The modules reachable in this loop (see [`modules.md`](modules.md) for each):
**Dashboard, Documents, Inventory (Books), Borrowing, Reservations, Members,
Delivery Log, Archive, Trash, Account Requests, Audit Logs, Settings, My Account.**

### Representative end-to-end flows

These show how data flows across modules and where the decision points are.

#### A) Book borrowing (the multi-copy request lifecycle)

```
Borrower/Staff builds a request (1+ books, qty, dates)
   → action=book_borrow_request_add
       ◇ staff? borrow for a walk-in (resolve/create library_borrowers)
         regular user? borrowerForUser(session)  — client identity ignored
       ▣ INSERT book_borrow_records (status='pending') + book_borrow_items
       → "Borrow request submitted" (NO stock change yet)

Staff reviews in Borrowing ▸ Pending
   → action=book_borrow_approve
       ▣ SELECT … FOR UPDATE (lock the record + each book row)
       ◇ enough quantity_available for every item?            no → reject w/ message
       ◇ resMaxForRange(): capacity holds vs confirmed reservations over the window?  no → conflict message
       compute due: explicit due ▸ requested_due ▸ time_allowed_minutes
       ▣ UPDATE books SET quantity_available -= qty   (stock leaves the shelf here)
       ▣ UPDATE book_borrow_records SET status='borrowed', due_at=…
       logAudit + createAdminNotification
   (or action=book_borrow_reject → status='rejected', no stock change)

Return
   → action=book_borrow_return
       ▣ restore quantity_available (+ damaged/missing accounting on book_borrow_items)
       compute fine vs due_at + borrowing_policies; status='returned'
```

#### B) Adding a book — three doors, analyzed in depth in `architecture-analysis.md`

```
Manual  : modal form → action=books_add            ▣ INSERT books (basic columns)
ISBN     : action=isbn_lookup (Open Library, READ-ONLY) → prefurnish the SAME manual form → action=books_add
Discovery: action=discovery_search (multi-provider, cached, scored)
           → action=book_add_from_discovery
               server re-fetches authoritative metadata
               ◇ duplicate? isbn13 → isbn10 → fuzzy(title, year)
                   exists → LINK: quantity += qty, COALESCE-backfill missing metadata
                   new    → INSERT enriched row + authors/categories/external_ids + enqueue enrichment
```

#### C) Document borrowing (the legacy, single-copy system — different tables!)

```
Staff marks a document borrowed → action=borrow
   ◇ activeBorrow(doc)? already out → error
   ▣ INSERT library_borrowing_records + UPDATE library_documents SET is_borrowed=1 + saveVersion('borrowed')
Return → action=return
   ▣ UPDATE library_borrowing_records SET returned_at=NOW() + library_documents.is_borrowed=0 + saveVersion('returned')
```

## Stage 4 — Background processes (no user present)

`cron.php` runs every 5 minutes (CLI). It includes the handler purely for its
functions (the CLI guard prevents the HTTP router from firing), then runs:

| Job | Effect | Tables |
|-----|--------|--------|
| `ensureLibrarySchema` | idempotent migration | `library_settings` |
| `purgeExpiredTrash` | hard-delete expired soft-deletes | `library_documents` |
| `resMaintenanceSweep` | expire holds, time out ready offers, mark no-shows | `book_reservations`, `book_waitlist` |
| `discoveryEnrichDrain(10)` | build related-book intelligence | `book_enrich_queue`, `book_relations` |
| `discoveryBackfillBooks(8)` | fill missing metadata on existing books | `books`, `api_cache` |
| login prune | delete `login_attempts` older than 1 day | `login_attempts` |

## Stage 5 — Logout

The Logout button (sidebar + mobile) opens a **confirmation modal**
(`#logoutConfirmModal`, see [`index.php`](../index.php)); only the explicit
**Log out** button proceeds to [`logout.php`](../logout.php), which:

```
$_SESSION = [];  → clear session cookie (expire it)  → session_destroy()  → Location: login.php
```

No DB write is involved; logout is purely session teardown. (Active-session rows
in `user_sessions`, used by *My Account ▸ Security*, are a separate feature and
are revoked via `user_sessions_revoke_all`.)

---

## Decision points, consolidated

| # | Where | Decision | Outcomes |
|---|-------|----------|----------|
| 1 | every page/API | logged in? | continue / redirect / 401 |
| 2 | `database.php` | DB reachable? | continue / 503 |
| 3 | login | recent failures ≥ 5? | locked / proceed |
| 4 | login | active + approved + password ok? | sign in / specific error |
| 5 | register | DepEd email? | LRN/school required / regular |
| 6 | index | role + classification | admin / child / teen / adult UI |
| 7 | every write | role sufficient? | mutate / 403 |
| 8 | every write | CSRF valid? | mutate / 403 |
| 9 | borrow approve | stock available? | approve / error |
| 10 | borrow approve | reservation capacity holds? | approve / conflict |
| 11 | discovery add | duplicate (isbn13/isbn10/fuzzy)? | link+copies / insert new |
| 12 | document borrow | already borrowed? | error / mark borrowed |
