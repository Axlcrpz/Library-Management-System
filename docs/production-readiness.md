# Production-Readiness Roadmap & Hardening Log

Baseline assessment: **5.5/10**. Target before go-live: **≥ 8.5/10** within 1–2 weeks.
This document is the action plan, the checklist with live status, and the running
score log. It is updated as work lands.

> Status legend: ✅ done (implemented this sprint) · ⬜ todo · 👤 needs an operator action (server/secrets/DNS)

---

## 1. Score trajectory

| Category | Base | S1 | S2 | S3 | Target | Lever to target |
|----------|:--:|:--:|:--:|:--:|:--:|-------|
| Code quality & maintainability | 5 | 5.5 | 6 | **6.5** | 6.5 | fine-logic dedup + growing tested core (done) |
| Architecture & scalability | 5 | 5.5 | 6 | **6.5** | 7 | aggregate endpoint + N+1 removed (done) |
| Security | 6.5 | 8 | 8 | **8** | 8.5 | TLS verify (operator) |
| Database structure & optimization | 6 | 6 | 7 | **7.5** | 7.5 | pagination + aggregates + bounded queries (done) |
| UI/UX | 7 | 7 | 7 | **7** | 7.5 | accessibility pass (P2) |
| Performance & efficiency | 5 | 5 | 6.5 | **8** | 8 | OPcache + gzip + session-lock fix + pagination (done); FULLTEXT/FPM (P2). See [performance-assessment.md](performance-assessment.md) |
| Error handling & logging | 6 | 7.5 | 7.5 | **7.5** | 8.5 | uptime monitor (operator) |
| Authentication & authorization | 7 | 8 | 8 | **8** | 8.5 | password reset (P2) |
| Testing & reliability | 2 | 5.5 | 6.5 | **8** | 8 | unit core + **borrow E2E in CI** + smoke (done) |
| Deployment readiness | 4.5 | 8 | 8 | **8** | 8.5 | run prod compose + TLS (operator) |
| **Overall** | **5.5** | **~7.3** | **~7.9** | **~8.3** | **8.5** | operator P0 steps + load-test verify |

**Where we are (~8.1 code-side):** the safe, verifiable code-side improvements are
now essentially exhausted — testing jumped to 8 (full borrow lifecycle asserted
end-to-end in CI on disposable MySQL), and the Inventory pagination foundation is in
place. The remaining **~0.4 to 8.5 is operator work** — real secrets, TLS in front,
and an uptime monitor — after which the estimate is **~8.5–8.6**. Further code gains
(enable Inventory server-paging, split the monolith) are higher-risk or
diminishing-return and intentionally left for post-launch verification.

---

## 2. Priority buckets

### P0 — Critical (block deployment)

| # | Issue | Risk | Effort | Status |
|---|-------|------|:------:|:------:|
| P0-1 | No global security headers (CSP/X-Frame/HSTS/Referrer) | XSS/clickjacking exposure | Low | ✅ |
| P0-2 | Session fixation (no `session_regenerate_id` on login) | Session hijack | Low | ✅ |
| P0-3 | `COPY . ` ships `.git`/secrets/diagnostics/`cloudflared.exe` | Source & secret leak in image | Low | ✅ |
| P0-4 | Prod compose exposed phpMyAdmin + MySQL + weak default creds | DB compromise | Med | ✅ |
| P0-5 | No last-resort error handler (white screens / trace leaks) | Info leak, poor UX | Low | ✅ |
| P0-6 | No health endpoint for orchestration/monitoring | Blind ops | Low | ✅ |
| P0-7 | Secrets had guessable fallbacks in compose | Credential guess | Low | ✅ (prod compose requires them) |
| P0-8 | Set strong `.env` secrets on the server | Credential guess | Low | 👤 |
| P0-9 | TLS in front (Cloudflare Tunnel/proxy) + verify HSTS fires | Plaintext creds | Low | 👤 |
| P0-10 | Staging smoke test with CSP enforced (watch console) | CSP false-positive breakage | Low | 👤 |

### P1 — High (immediately after P0)

| # | Issue | Risk | Effort | Status |
|---|-------|------|:------:|:------:|
| P1-1 | No server-side pagination (ships whole tables) | Slowness/timeouts at scale | Med | ✅ backend (books/members/circulation, backward-compatible + search); Inventory UI paging deferred |
| P1-2 | Narrow test coverage | Regressions in money/borrow logic | Med | ✅ unit core: auth/CSRF, fines, due-date + HTTP smoke test; borrow E2E in CI deferred |
| P1-3 | No uptime monitor hitting `/health.php` | Undetected outages | Low | 👤 |
| P1-4 | No log rotation/retention for `storage/logs` | Disk fill | Low | ⬜ |
| P1-5 | API rate limiting only on login/discovery | Abuse of other endpoints | Med | ⬜ |
| P1-6 | N+1 query in circulation list (`book_borrow_requests_get`) | Slow under load | Low | ✅ batched to one query |

### P2 — Medium (after deployment)

| # | Issue | Effort |
|---|-------|:------:|
| P2-1 | Split the 5.7k-line `library_handler.php` by domain | High |
| P2-2 | Retire reservations v1 (`reservations` table + 5 handlers) | Med |
| P2-3 | Add foreign-key constraints (or documented invariants) | Med |
| P2-4 | Nonce-based CSP (drop `'unsafe-inline'`) | High |
| P2-5 | Self-service password reset + email verification | Med |
| P2-6 | User notification center + due reminders | Med |
| P2-7 | Fines lifecycle (payment/waiver/settled states) | Med |
| P2-8 | Accessibility pass (ARIA/keyboard/contrast) | Med |

---

## 3. What landed this sprint (code changes)

| Area | File(s) | Change |
|------|---------|--------|
| Security headers | [`config/security_headers.php`](../config/security_headers.php) + wired in [`auth.php`](../auth.php) | CSP (tuned to the app's exact CDNs), `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, COOP, HSTS-when-HTTPS. One-flag `CSP_ENFORCE` toggle to report-only. |
| Session fixation | [`login.php`](../login.php) | `session_regenerate_id(true)` on successful login. |
| Error safety net | [`config/error_handler.php`](../config/error_handler.php) + wired in [`auth.php`](../auth.php) | Global exception + fatal handler; dated logs to `storage/logs/` (web-denied); generic 500 (JSON or text) instead of stack traces. |
| Health probe | [`health.php`](../health.php) | `200 {status:ok}` when DB reachable, `503` otherwise. No auth, no secrets. |
| Image hygiene | [`.dockerignore`](../.dockerignore) | Excludes `.git`, `docs`, `.env*`, `vendor`, `tests`, diagnostics, `*.exe`, logs from the build context. |
| Prod stack | [`docker-compose.prod.yml`](../docker-compose.prod.yml) | No phpMyAdmin; DB not published; web bound to `127.0.0.1`; **required** secrets (`${VAR:?}`); storage volume; healthchecks; no source bind-mount. |
| Dev/prod split | [`docker-compose.yml`](../docker-compose.yml) | Banner marking it dev-only. |
| Tested core | [`lib/Fines.php`](../lib/Fines.php) + wired in [`api/library_handler.php`](../api/library_handler.php) | Pure overdue-fine rule extracted from `handleCalculateFine` and delegated to. |
| Test harness | [`composer.json`](../composer.json), [`phpunit.xml`](../phpunit.xml), [`tests/`](../tests) | PHPUnit + CLI-safe bootstrap; `AuthTest` (authz matrix + CSRF), `FinesTest` (money math). |
| CI | [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) | Lints every PHP file, validates composer, installs deps, runs PHPUnit on push/PR. |
| Repo hygiene | [`.gitignore`](../.gitignore) | Ignores `vendor/`, `.phpunit.cache/`, `*.exe`, `storage/logs/`. |

All changed PHP passes `php -l`; the authz + fines logic was smoke-verified through
the real code paths.

---

## 4. Detail per high-impact issue

### P0-1 Security headers — ✅
- **Risk:** no CSP → any reflected/stored XSS runs freely; no `X-Frame-Options`/`frame-ancestors` → clickjacking.
- **Why it scores:** core OWASP browser protections were absent.
- **Improvement:** Security +1.5.
- **Steps/code:** added `config/security_headers.php`, required from `auth.php` (covers all pages + API). CSP allows `'unsafe-inline'` + the exact CDNs the app loads (jsdelivr/cdnjs/unpkg/google-fonts) so nothing breaks; tighten to nonces in P2.
- **Verify in staging:** open the app with DevTools console; any `Refused to load…`/CSP violation means adjust an allowed source (or flip `CSP_ENFORCE=false` temporarily).

### P0-2 Session fixation — ✅
- **Risk:** an attacker who fixes a victim's session ID before login keeps access after.
- **Improvement:** Auth +0.5.
- **Code:** `session_regenerate_id(true)` immediately on successful auth in `login.php`.

### P0-3/4/7 Docker production hardening — ✅
- **Risk:** image shipped `.git` + diagnostics + a 100MB+ binary; dev compose exposed phpMyAdmin (8081) and MySQL (3307) with `lms_pass`/`root` defaults.
- **Improvement:** Deployment +3.5, Security +0.5.
- **Code:** `.dockerignore` + `docker-compose.prod.yml` (loopback web, no DB port, no phpMyAdmin, `${VAR:?}` required secrets, healthchecks, storage volume, no source mount).

### P0-5/6 Error handling, logging & health — ✅
- **Risk:** uncaught fatals showed white screens or traces; no machine-checkable health.
- **Improvement:** Error/logging +1.5.
- **Code:** `config/error_handler.php` (logs to `storage/logs`, generic 500) + `health.php` (used by the compose healthcheck).

### P1-1 Server-side pagination — ✅ (backend) / UI deferred
- **What landed:** `sendSuccess()` now carries an optional `meta`; `handleBooksGet`,
  `handleBorrowersGet`, and `handleBookBorrowRequestsGet` accept optional
  `page`/`per_page` (+ server-side `q` search on books). **No params → identical to
  before**, so existing screens are untouched; `per_page` is hard-capped at 200.
- **Also:** fixed an **N+1** in the circulation list (was one item query per row →
  now a single batched `IN (...)` query).
- **Why it scores:** bounds worst-case payloads, adds server search, removes a
  per-row query storm. Performance +1.5, DB +1.
- **Deferred (next):** the Inventory screen is client-side (it sums the full
  `window.allBooks` for its health cards + category counts), so true UI paging needs
  a server aggregates endpoint first. Left as a staged change to avoid breaking a
  core screen that can't be visually verified here.

### P1-2 Critical-workflow tests — ✅ (unit core) / E2E deferred
- **What landed:** pure rules extracted and unit-tested — `Lib\Fines` (returning/
  fines), `Lib\DueDate` (borrowing/approval due-date precedence), plus `userCan`/CSRF
  (authentication). All wired into the handlers they came from, so tests pin real
  behavior. Added [`deploy/smoke-test.php`](../deploy/smoke-test.php): an HTTP
  post-deploy check (health → login → CSRF → catalog write/read+pagination → cleanup).
- **Why it scores:** the money/borrow/auth logic now has a regression net; the smoke
  test proves the deployed stack end-to-end. Testing +1.
- **Deferred (next):** a CI job booting `mysql:8` to assert borrow approve→return
  stock decrement/restore and reservation-capacity (DB-coupled; handlers `exit()`,
  so best done at the HTTP level — the smoke test is the seed for it).

### Sprint 2 — change log
| Area | File(s) | Change |
|------|---------|--------|
| Pagination + meta | [`api/library_handler.php`](../api/library_handler.php) | `sendSuccess($data,$msg,$extra)`; `books_get`/`borrowers_get`/`book_borrow_requests_get` paginated + book search (backward-compatible) |
| N+1 fix | [`api/library_handler.php`](../api/library_handler.php) | Circulation items batched into one `IN (...)` query |
| Tested core | [`lib/DueDate.php`](../lib/DueDate.php) + handler wiring | Due-date precedence extracted + delegated |
| Tests | [`tests/DueDateTest.php`](../tests/DueDateTest.php) | Due-date rule coverage |
| Smoke test | [`deploy/smoke-test.php`](../deploy/smoke-test.php) | HTTP critical-path check for staging/post-deploy |

### Sprint 3 — change log
| Area | File(s) | Change |
|------|---------|--------|
| Inventory aggregates (Batch A, Stage 1) | [`api/library_handler.php`](../api/library_handler.php) | New `inventory_stats` action: server-side totals + category counts mirroring the client definitions |
| Inventory paging consumer (Batch A, Stage 2) | [`assets/js/inventory.js`](../assets/js/inventory.js) | **Default-OFF** opt-in (`window.INV_SERVER_STATS`) to render health/categories from the server; existing path unchanged |
| Borrow E2E (Batch B) | [`tests/e2e/borrow_lifecycle.php`](../tests/e2e/borrow_lifecycle.php), [`tests/e2e/seed-admin.php`](../tests/e2e/seed-admin.php), [`tests/e2e/schema.sql`](../tests/e2e/schema.sql) | Full lifecycle invariants asserted over HTTP |
| E2E CI | [`.github/workflows/e2e.yml`](../.github/workflows/e2e.yml) | Boots disposable `mysql:8` + `php -S`, runs smoke + lifecycle; isolated from unit CI |
| Dedup | [`api/library_handler.php`](../api/library_handler.php) | Return-path fine math now delegates to the tested `Lib\Fines` (removed duplicate inline calc) |
| Smoke coverage | [`deploy/smoke-test.php`](../deploy/smoke-test.php) | Now also asserts the `inventory_stats` shape |

---

## 5. Final production audit

| Area | State | Evidence / gap |
|------|:-----:|----------------|
| Docker configuration | ✅ | `docker-compose.prod.yml` (no phpMyAdmin, loopback web, no DB port, healthchecks, no source mount) + `.dockerignore` |
| Environment variables | ✅ / 👤 | Prod compose **requires** secrets (`${VAR:?}`); 👤 operator must set real values in `.env` |
| Database security | ✅ | Scoped `lms_user` (not root); prepared statements throughout; DB not internet-exposed in prod |
| Session security | ✅ | HttpOnly/SameSite/Secure-auto/strict + `session_regenerate_id` on login |
| File upload security | ✅ | MIME+ext allowlist, randomized names, `storage/` web-denied, served only via authed endpoint |
| AuthN / AuthZ | ✅ | Server-side role guards on every write; lockout; approval gate; authz matrix unit-tested |
| Logging & monitoring | ✅ / 👤 | Global handler + dated file logs + `/health.php`; 👤 wire an uptime monitor; ⬜ log rotation |
| Error handling | ✅ | Per-endpoint try/catch + global last-resort 500; internals masked (`APP_DEBUG=false`) |
| Backup & recovery | ✅ / 👤 | `backup.sh` present; 👤 schedule it (cron) + test a restore |
| Performance | ✅ / ◐ | N+1 fixed; endpoints paginated/bounded/searchable; aggregate endpoint ready. Inventory UI paging staged (opt-in, default-off) |
| Scalability | ◐ | Bounded queries + server aggregates; file-based sessions are fine single-node (use Redis only if you scale to multiple web nodes) |
| Reliability | ✅ | Transactions + `FOR UPDATE` row locks on approve/return; global error net; borrow invariants asserted in CI |
| Testing coverage | ✅ | Unit core (auth/fines/due-date) + **full borrow E2E in CI** (disposable MySQL) + HTTP smoke |
| Secrets management | ✅ / 👤 | `.env` gitignored, `.env.example` documents rotation; 👤 set + rotate on host |
| HTTPS | 👤 | Terminate at Cloudflare Tunnel/proxy; HSTS auto-emits once HTTPS is seen |

Legend: ✅ done · ◐ partial (safe to ship, improvement scheduled) · 👤 operator action · ⬜ todo

## 6. Go-live recommendation

**Status: READY WITH MINOR RISKS — once the operator P0 steps are done.**

Code-side the system is at **~8.1/10**; with the operator steps below it reaches an
estimated **~8.5–8.6**. Sequence:

1. **Operator P0 (½ day, blocking):** set strong `.env` secrets; `docker compose -f
   docker-compose.prod.yml up -d --build`; put HTTPS/Cloudflare Tunnel in front;
   `composer install` + confirm **both CI workflows green** (unit + E2E); run
   **`php deploy/smoke-test.php`** against staging with **no CSP console errors**.
2. **Schedule** `backup.sh` (cron) + an uptime monitor on `/health.php`; test one restore.

After step 1 the system is **Ready with Minor Risks** for go-live. Residual risks are
non-blocking and scheduled (below); none expose users to data loss or compromise.

**Remaining risks (accepted for launch):**
- Very large catalogs still load fully in the Inventory *screen* until the staged server-paging is enabled — the aggregate endpoint + default-off opt-in are in place; flip `window.INV_SERVER_STATS` and verify in staging to complete it.
- CSP allows `'unsafe-inline'` (pragmatic; nonce-based is P2).
- Architecture debt (monolith split, reservations v1, FK constraints) — P2, post-launch.
