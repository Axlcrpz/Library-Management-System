# Production Readiness Report & Deployment Handover
### SDO Quirino Library Management System — for delivery to the Schools Division Office

You are the sole developer and maintainer. This is the single authoritative document
for shipping this system safely and maintaining it afterward. It cross-references the
deeper docs where useful:
- Security: [`SECURITY.md`](../SECURITY.md)
- Hardening scorecard: [`docs/production-readiness.md`](production-readiness.md)
- Performance/capacity: [`docs/performance-assessment.md`](performance-assessment.md)
- Public staging/HTTPS: [`docs/staging-deployment.md`](staging-deployment.md)

> **Reassurance:** the audit below found the codebase already clean of debug functions
> (`var_dump`/`print_r`/`dd`: none), with `APP_DEBUG=false`, and **no secrets or PII in
> the 138 git-tracked files**. The delivery artifact is already safe; the items below
> are the finishing touches.

---

# PART 1 — FINAL REPORT

## 1.1 Project inventory

```
library_sys/
├─ index.php               SPA shell (renders all tab templates, role-gated nav)
├─ login.php  logout.php   authentication + self-registration + brute-force lockout
├─ account_action.php      admin approve/reject of pending registrations
├─ reports.php             printable reports
├─ health.php              liveness/readiness probe (DB check) — no auth
├─ cron.php                5-minute maintenance sweep (reservations, enrich, purge)
├─ auth.php                sessions, role guards, CSRF, userCan()
├─ config/
│   ├─ database.php         env-driven PDO (scoped user, non-leaking 503)
│   ├─ security_headers.php CSP / X-Frame / HSTS / Referrer-Policy (global)
│   └─ error_handler.php    global exception/fatal net → dated logs, generic 500
├─ api/
│   └─ library_handler.php  THE controller (~115 actions, schema migration, logic)
├─ legacy/                  archived, non-shipping code (tnf_handler.php — see §1.2)
├─ lib/  Fines.php  DueDate.php    pure, unit-tested business rules
├─ templates/*.php          module views (dashboard, books, borrowing, members, …)
├─ assets/js/*.js           app.js (core), discovery.js, inventory.js, theme.js, …
├─ assets/css/*.css         style.css + role themes; assets/js/vendor/ (xlsx)
├─ storage/                 uploads (attachments, avatars, deliveries, profiles, logs)
├─ deploy/                  Caddyfile, seed.sql.example, README, backup docs
├─ tests/                   PHPUnit (unit + e2e) + load/ (k6, ab)
├─ .github/workflows/       ci.yml, e2e.yml, secret-scan.yml
├─ Dockerfile              docker-compose.{yml(dev), prod, staging}.yml
├─ .env.example  .dockerignore  .gitignore  backup.sh  SECURITY.md  docs/
└─ (local only, gitignored) login_debug.php, reset_admin.php, cloudflared.exe,
                            deploy/seed.sql, deploy/tunnel.log, .claude/
```

## 1.2 SAFE TO REMOVE

| Item | Confidence | Status / action |
|------|:----------:|-----------------|
| **`api/tnf_handler.php` → archived to `legacy/`** ✅ | **High** | A superseded document API that **does** have its own live router (`switch($action)` document CRUD, own CSRF/`apiRequireTnfAccess`/`ensureTnfSchema`) — a functional but **completely unreferenced** shadow surface (verified across includes, AJAX/fetch, forms, routes, dynamic includes, Docker/Apache/cron, and a case-insensitive token scan; the only `tnf` hit is an unrelated CSS class). It never inherited the newer security fixes. **Done:** `git mv`'d to `legacy/` (history preserved), web-denied via `legacy/.htaccess`, and excluded from the image via `.dockerignore`. After a short staging monitoring period, permanently remove with `git rm legacy/tnf_handler.php`. |
| `login_debug.php`, `reset_admin.php`, `_locdbg.php` | High | CLI-only dev/ops tools. **Already excluded** from git & the Docker image (`.gitignore`/`.dockerignore`). They won't ship. Delete locally if you like; `reset_admin.php` is handy to keep on your dev PC only. |
| `cloudflared.exe`, `deploy/tunnel.log` | High | Dev tunnel binary + log. Already gitignored/dockerignored. |
| `deploy/seed.sql` | High | **Real DB dump with PII + a live SMS key.** Already gitignored — must NEVER ship. Keep it out of any delivery bundle. |
| `.claude/` | High | Editor/agent state. Already gitignored. |

> I did **not** auto-delete `api/tnf_handler.php` because it is a tracked 846-line file
> and you asked me not to make destructive changes without confirmation. Say the word
> and I'll remove it in one commit.

## 1.3 REVIEW MANUALLY (do NOT remove blindly)

| Item | Why it's here | Recommendation |
|------|---------------|----------------|
| ~23 `alert()` calls in `assets/js/*.js` | The audit flagged `alert` as "debug", but here they are **user-facing validation** ("Return Date cannot be before Borrow Date", "Image exceeds 20 MB", …). | **Keep.** Removing them breaks error feedback. Optional future polish: convert to `showToast()`. |
| `docker-compose.yml` (dev) | Exposes phpMyAdmin + the DB port — fine locally, unsafe in prod. | **Keep for local dev; never run in production.** Use `docker-compose.prod.yml` or `.staging.yml`. |
| Reservations v1 vs v2, dual circulation models | Overlapping subsystems (see architecture-analysis). | Not a delivery blocker; schedule a post-launch consolidation. |
| `assets/js/vendor/xlsx.full.min.js` | Legit vendored spreadsheet library. | Keep. |

## 1.4 Security issues

Most were **already remediated this session** (see `production-readiness.md` / `SECURITY.md`):

| Status | Item |
|:------:|------|
| ✅ Fixed | Global security headers + CSP; session-fixation (`session_regenerate_id` on login); global CSRF gate; scoped DB user (not root); error masking (`APP_DEBUG=false`, internals logged not shown); authenticated `file_serve` + Apache `storage` deny; upload MIME+extension allowlist; login lockout; secrets→env; no phpMyAdmin in prod compose |
| ✅ **Resolved (this audit)** | **`api/tnf_handler.php`** — a functional but unreferenced **shadow document API** (its own router/CSRF/access-check that did not inherit later security fixes). **Archived** to `legacy/` (web-denied + image-excluded); safe because nothing calls it. |
| ⏳ Operator (before go-live) | **Rotate the SMS API key** (it existed in the plaintext dump); set strong prod `.env` secrets; put HTTPS in front (Caddy/tunnel); enable the `gitleaks` CI gate |
| 📋 Deferred (P2, not blockers) | Nonce-based CSP (currently allows `'unsafe-inline'`); self-service password reset; DB foreign-key constraints |

SQLi / XSS / CSRF / IDOR posture: **prepared statements throughout**, output escaped in
templates, **global CSRF** on all writes, role guards on every mutation, and file access
via a DB-allowlisted authenticated endpoint. No hardcoded credentials in shipped files.

## 1.5 Production risks

1. **Running the dev compose in prod** (phpMyAdmin/DB exposed) → always use the prod/staging compose.
2. **Bind-mount permissions** on the prod compose's `./storage` (Linux) can block uploads → the staging compose uses a **named volume** (correct `www-data` perms); prefer that, or `chown -R 33:33 storage` on the host.
3. **Sessions reset on container recreation** (file-based, single node) → acceptable for one web instance; use Redis sessions only if you scale out.
4. **Large catalog** → the Inventory *screen* loads all rows until server-side paging is enabled (backend is ready; flip `INV_SERVER_STATS`).
5. **Accidentally shipping `deploy/seed.sql`** → mitigated by `.gitignore`; verify with the checklist.

## 1.6 Database cleanup — deliver a FRESH database

**Do NOT deliver your development database** (`deploy/seed.sql`) — it holds your test
accounts, dummy books/borrows/announcements, audit records, and a live SMS key.

**What to KEEP in the production DB:**
- One **real administrator** account (created fresh, strong password).
- The **seeded system defaults**: `library_settings` (fine rates, loan limits, library
  name…) and `borrowing_policies` (per-classification rules) — these are created
  automatically by `ensureLibrarySchema()` and are configuration, not test data.
- The static **avatar library** (`assets/img/avatars/`) — code assets, not DB data.

**What to REMOVE (start empty — the client populates real data):**
`users` (except the new admin), `library_borrowers`, `books` + dimensions, all
`book_borrow_*` / `reservations` / `book_reservations` / `book_waitlist`, `deliveries`,
`announcements`, `audit_logs`, `admin_notifications`, `sms_queue`, `library_documents`.

**How:** don't clean your dev dump — **start from an empty schema** instead
(`deploy/seed.sql.example` creates the `users` table; `ensureLibrarySchema()` creates
everything else empty on first request). Then create the one admin. See Part 2-A.4.

## 1.7 Pre-deployment checklist

- [x] `api/tnf_handler.php` archived to `legacy/` (verified unused; web-denied + image-excluded). Optionally `git rm legacy/tnf_handler.php` after a staging monitoring period.
- [ ] Confirm no secrets tracked: `git ls-files | grep -iE 'seed\.sql|\.env$|reset_admin|login_debug|cloudflared|\.pem|\.key'` → empty.
- [ ] `composer install` then **CI green** (unit + e2e + secret-scan workflows).
- [ ] Strong values set in the production `.env` (never the dev defaults).
- [ ] **SMS API key rotated** at the provider; set the new one via the in-app Settings page.
- [ ] Fresh empty DB + one real admin (Part 2-A).
- [ ] HTTPS working (Caddy/tunnel), HSTS present, **no CSP errors** in the browser console.
- [ ] `php deploy/smoke-test.php https://<url> admin@… 'pass'` passes.
- [ ] `backup.sh` scheduled (cron) and a **restore tested once**.
- [ ] Uptime monitor pointed at `/health.php`.

---

# PART 2 — DEPLOYMENT & HANDOVER GUIDE (beginner-friendly)

There are two realistic client environments. Pick one:
- **Path D (recommended): Docker** on a Linux server/VPS — uses everything already built, cleanest and most repeatable.
- **Path T: traditional PHP/MySQL hosting** (cPanel / a Windows+XAMPP server) — classic file-copy + SQL-import.

## A. Pre-deployment preparation

**A.1 Make a clean production copy (separate from your dev work)**
```bash
git clone <your-repo-url> lms-prod && cd lms-prod   # a fresh checkout = only tracked, clean files
git rm api/tnf_handler.php && git commit -m "Remove dead legacy handler"
```
A fresh clone contains none of your local dev artifacts (they're gitignored) — this *is*
your production copy.

**A.2 Separate dev from prod**
- Dev PC: keep using `docker-compose.yml` (with phpMyAdmin) and your local `.env`.
- Production: only ever use `docker-compose.prod.yml`/`.staging.yml` and the client's `.env`.
- Never copy your dev `.env` or `deploy/seed.sql` to production.

**A.3 Create a fresh production database**
- Docker: `cp deploy/seed.sql.example deploy/seed.sql` (schema-only users table; the app
  auto-creates the rest). MySQL initializes it on first `up`.
- Traditional: create an empty database `library_sys`, import `deploy/seed.sql.example`.

**A.4 Remove all test/audit records → just create the admin on the empty DB**
Because you start from an empty schema, there is nothing to scrub. Create exactly one admin:
```bash
# generate a bcrypt hash (never store plaintext):
php -r "echo password_hash('STRONG_ADMIN_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
# then run once against the production DB:
INSERT INTO users (username,password,full_name,role,status,is_active)
VALUES ('admin@sdo-quirino.example','<paste-hash>','SDO Administrator','admin','approved',1);
```

**A.5 Create the initial administrator** — done in A.4. First login → open **Settings** and
configure library name/contact and the SMS gateway key (stored in the DB, not the repo).

**A.6 What to back up before deploying**
- The production `.env` (secure, offline — it has the secrets).
- The empty seeded DB (a baseline dump).
- The whole `lms-prod` folder (a zip snapshot of the exact code you shipped).

## B. Client deployment procedure

### Path D — Docker (recommended)
```bash
# on the client's Ubuntu server (2 vCPU / 4 GB):
curl -fsSL https://get.docker.com | sh
git clone <repo> lms && cd lms && git rm api/tnf_handler.php
cp .env.example .env         # 1) set DB_PASS, MYSQL_ROOT_PASSWORD, SITE_ADDRESS
cp deploy/seed.sql.example deploy/seed.sql   # 2) fresh schema
docker compose -f docker-compose.staging.yml up -d --build   # 3) build + start + auto-HTTPS
# 4) create the admin (A.4)  → 5) verify (Part C)
```
Public HTTPS URL appears at `https://<SITE_ADDRESS>`. (Full detail: `staging-deployment.md`.)

### Path T — traditional PHP/MySQL hosting (cPanel / XAMPP server)
1. **Prepare code:** zip the fresh `lms-prod` clone (exclude `.git`, `tests/`, `docs/`, `deploy/seed.sql`).
2. **Prepare DB:** in the client's MySQL, create database `library_sys` + a **scoped user**
   (not root) with rights on it only.
3. **Export baseline:** `mysqldump` your empty seeded DB → `baseline.sql` (or just import `seed.sql.example`).
4. **Back up everything** (A.6) before touching the client server.
5. **Transfer files:** upload the zip to the web root via SFTP; extract.
6. **Import DB:** import `seed.sql.example` (or `baseline.sql`) via phpMyAdmin/CLI.
7. **Configure env:** create `.env` in the project root with `DB_HOST/DB_NAME/DB_USER/DB_PASS`.
   Ensure PHP `upload_max_filesize=20M`, `post_max_size=24M` (the Docker image sets these;
   on shared hosting set them in `.htaccess`/`php.ini`). Confirm PHP 8.1+ with `pdo_mysql`.
8. **Storage & permissions:** ensure `storage/` and its subfolders are **writable by the web
   user** and **not directly browsable** (the `.htaccess` deny files are included; verify
   `https://<site>/storage/…` returns 403).
9. **Setup script:** none needed — `ensureLibrarySchema()` creates all tables on the first
   request. Just hit the site once, then create the admin (A.4).
10. **Verify:** run Part C.

## C. Post-deployment validation checklist

- [ ] `https://<site>/health.php` → `{"status":"ok"}`
- [ ] **Login** works (the admin from A.4); wrong password is rejected; 5 bad tries locks out.
- [ ] **Registration** creates a *pending* account; **admin approval** (Account Requests) activates it; roles behave (admin/staff/viewer).
- [ ] **File upload** (add a document with an attachment) succeeds; download via the app works; direct `/storage/...` URL is **denied (403)**.
- [ ] **Avatars** show (sidebar + Members → View); initials fallback for accounts without a photo.
- [ ] **Books:** add (manual + Discovery), edit, add copies; **Borrow → Approve → Return** decrements/restores stock; overdue **fine** computes.
- [ ] **Reports** render; **audit logs** record actions.
- [ ] **Notifications** (in-app) appear; **SMS queue** entries created (email: n/a — the system sends no email).
- [ ] **Backup:** `bash backup.sh` writes a `.sql.gz`; **restore it into a scratch DB** once.
- [ ] **Security restrictions:** a non-admin cannot reach admin actions (returns 403); CSRF-less POST is rejected.

## D. Future update & maintenance workflow

**Golden rule:** *Dev PC → test → back up production → deploy → verify → (rollback if needed).*

**D.1 Add a feature safely**
```bash
git checkout -b feature/x        # never work on main directly
# ... edit code on your DEV PC (XAMPP or dev compose) ...
composer test                    # run unit tests; add tests for new logic
git commit && git push           # CI (unit+e2e+secret-scan) must pass
```

**D.2 Deploy an update without breaking client data**
```bash
# ON THE SERVER, always in this order:
bash backup.sh                                   # 1) BACK UP THE DB FIRST
docker compose -f docker-compose.staging.yml exec web tar czf /tmp/storage.tgz -C /var/www/html storage  # 2) back up uploads
git pull                                         # 3) get the new code
docker compose -f docker-compose.staging.yml up -d --build   # 4) rebuild (schema self-migrates)
php deploy/smoke-test.php https://<site> admin@… 'pass'       # 5) verify
```
Schema changes are additive via `ensureLibrarySchema()` (it only adds tables/columns,
never drops) — so pulling new code is data-safe. Bump the `schema_version` constant when
you add migrations so they run once.

**D.3 Rollback if an update fails**
```bash
git checkout <previous-good-commit>              # revert code
docker compose -f docker-compose.staging.yml up -d --build
# if a data issue: restore the pre-update dump
gunzip < backups/library_sys_<ts>.sql.gz | docker compose exec -T db mysql -uroot -p"$MYSQL_ROOT_PASSWORD" library_sys
```

**D.4 Files that must NEVER be edited directly in production**
- `.env` (rotate via a controlled change, not ad-hoc edits) · `vendor/` · anything under
  `storage/` by hand · the DB via phpMyAdmin on a whim. **Edit code on your dev PC, commit,
  then deploy** — never hot-edit PHP on the client server (you'll lose it on the next `git pull`).

**D.5 Long-term best practices**
- Everything in **Git**; tag each release (`git tag v1.1 && git push --tags`).
- Keep `main` deployable; use feature branches + PRs (even solo — CI catches regressions).
- Keep `deploy/seed.sql` and `.env` **out of Git** (already enforced).
- Schedule `backup.sh` daily and **test a restore monthly**.
- Watch `/health.php` with a free uptime monitor.

## E. Disaster recovery plan

Keep **off-server copies** of: the latest `*.sql.gz` backup, the `.env`, and a code zip/tag.

| Scenario | Recovery |
|----------|----------|
| **Server crashes** | Provision a new host → install Docker → `git clone` (same tag) → restore `.env` → `cp` the latest `seed`/restore the dump → `docker compose -f docker-compose.staging.yml up -d --build` → restore `storage.tgz`. |
| **Database corrupted** | Stop the app → restore the most recent good dump into a fresh DB: `gunzip < backups/<latest>.sql.gz | docker compose exec -T db mysql -uroot -p… library_sys` → restart → verify with Part C. |
| **Files accidentally deleted** | Re-`git clone` the release tag (code is disposable/reproducible); restore `storage/` from the `storage.tgz` backup for uploads. |
| **A deployment update fails** | Follow D.3 (revert code to the previous tag; restore the pre-update DB dump). Because you backed up in D.2 step 1–2, you lose nothing. |
| **Client reports a critical bug** | Reproduce on your **dev PC** (never debug on prod), fix on a branch, run tests, then deploy via D.2. If it's urgent and data-safe, roll back to the last good tag first (D.3), then fix calmly. |

**Recovery time is minutes, not hours**, if you keep three things off-server: a **DB dump**,
the **`.env`**, and a **git tag**. Everything else is rebuildable.
