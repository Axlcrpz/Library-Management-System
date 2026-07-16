# Staging Deployment & Stress-Test Guide

Goal: get the LMS onto a **public HTTPS URL** quickly and safely for stress testing,
without changing application behavior.

## 1. Hosting approach

The app is already Docker-based (`php:8.2-apache` + `mysql:8`) and ships a hardened,
OPcache+gzip-tuned image. So the right model is **"run the existing image on a Docker
host and put TLS in front."** No app changes are needed.

What's new in this guide:
- [`docker-compose.staging.yml`](../docker-compose.staging.yml) — a self-contained
  **caddy + web + db** stack that yields a public HTTPS URL in one command.
- [`deploy/Caddyfile`](../deploy/Caddyfile) — automatic Let's Encrypt TLS.

It uses **named volumes** (not bind mounts) so uploads inherit the image's `www-data`
ownership — avoiding the classic "container can't write to the mounted host folder"
upload failure.

## 2. Platform comparison (for stress testing specifically)

| Platform | Public HTTPS | Docker | MySQL | Cost | Stress-test fidelity | Verdict |
|----------|:---:|:---:|:---:|------|----------------------|---------|
| **Small VPS** (Hetzner CX22 / DigitalOcean / Linode) | ✅ Caddy auto | ✅ runs the compose as-is | ✅ container | ~€4–6/mo, **destroy after** | **Dedicated 2 vCPU / 4 GB, no throttling → honest numbers** | ⭐ **Recommended** |
| **Railway** | ✅ `*.up.railway.app` | ✅ Dockerfile | ✅ managed plugin | ~$5 trial → usage | Shared but decent; near-zero server admin | Good low-ops alt |
| **Fly.io** | ✅ `*.fly.dev` | ✅ | ⚠️ separate app/external | pay-as-you-go | Good, global; MySQL needs extra setup | OK, more config |
| **Render free** | ✅ | ✅ | ❌ Postgres only (no free MySQL) | free but **sleeps + throttled CPU** | Poor — sleeps mid-test, weak CPU | ✗ not for load |
| **Cloudflare Tunnel** + local/VPS Docker (you already have `cloudflared`) | ✅ tunnel URL | ✅ | ✅ | **free** | Limited by your machine; CF may bot-challenge load tools | Instant *functional* staging, **not** honest load numbers |

### Recommendation
- **Stress testing → a small VPS** running `docker-compose.staging.yml`. It gives
  dedicated CPU/RAM (matching the measured 2–4 vCPU / 4 GB capacity target), no
  platform CPU throttling or request-rate limiting to corrupt your k6 numbers, real
  TLS, and it runs the exact image you'll ship. Spin it up, test for a day or two,
  destroy it (~€1).
- **Zero server admin → Railway** (Docker image + managed MySQL + a volume).
- **Instant free functional URL → Cloudflare Tunnel** over a locally-running stack —
  great for clicking through the app, *not* for measuring load (your laptop + CF in
  the path will skew results).

---

## 3. Recommended path — VPS + staging compose (step by step)

```bash
# 1) Create an Ubuntu 22.04 VPS (2 vCPU / 4 GB). Note its public IP.

# 2) Install Docker
curl -fsSL https://get.docker.com | sh

# 3) Get the code onto the box
git clone <your-repo-url> lms && cd lms
#    (or: scp -r ./library_sys root@<vps-ip>:/root/lms)

# 4) Configure secrets + public address
cp .env.example .env
#    edit .env →  DB_PASS, MYSQL_ROOT_PASSWORD (strong),
#                 SITE_ADDRESS=<vps-public-ip>.sslip.io     # no domain needed
#                 (or SITE_ADDRESS=staging.your-office.example if you have DNS)

# 5) Database bootstrap (schema-only template; no default admin)
cp deploy/seed.sql.example deploy/seed.sql

# 6) Launch — Caddy fetches a real TLS cert automatically
docker compose -f docker-compose.staging.yml up -d --build

# 7) Create the first admin (no plaintext password baked anywhere)
docker compose -f docker-compose.staging.yml exec web \
  php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
docker compose -f docker-compose.staging.yml exec db \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" library_sys -e \
  "INSERT INTO users (username,password,full_name,role,status,is_active)
   VALUES ('admin@staging.example','<paste-hash>','Staging Admin','admin','approved',1);"

# 8) Visit  https://<SITE_ADDRESS>  → log in.
```

> For **realistic** stress numbers, load a representative dataset (e.g. a few
> thousand books) before testing, so pagination and search are exercised against
> real volume rather than an empty catalog.

### Alternative A — Cloudflare Tunnel (instant, free, functional only)
```bash
docker compose -f docker-compose.prod.yml up -d --build     # app on 127.0.0.1:8080
cloudflared tunnel --url http://127.0.0.1:8080              # prints a public https URL
```
Good for a quick public click-through. Don't trust load numbers from this path.

### Alternative B — Railway (managed, low-ops)
1. New project → **Deploy from Repo** (Railway auto-detects the `Dockerfile`).
2. Add a **MySQL** plugin; copy its connection vars.
3. Set service variables: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (from the plugin).
4. Add a **Volume** mounted at `/var/www/html/storage`.
5. Deploy → Railway gives `https://<app>.up.railway.app`. Create the admin as in step 7.

---

## 4. Required environment variables

| Var | Used by | Notes |
|-----|---------|-------|
| `DB_HOST` | app | `db` on the compose network (or the managed host on Railway) |
| `DB_NAME` | app / MySQL | default `library_sys` |
| `DB_USER` | app / MySQL | scoped account (not root) |
| `DB_PASS` | app / MySQL | **required**, strong |
| `MYSQL_ROOT_PASSWORD` | MySQL init / backups | **required**, strong |
| `SITE_ADDRESS` | Caddy | your domain, or `<vps-ip>.sslip.io` for no-DNS HTTPS |

App-level secrets (SMS gateway key, etc.) are **not** env vars — they live in the
database `library_settings` and are set from the in-app Settings page.

---

## 5. Will it still work after deployment? (per the checklist)

| Feature | Status behind this stack | Why |
|---------|--------------------------|-----|
| **User authentication** | ✅ | Caddy forwards `X-Forwarded-Proto=https`, so cookies are marked `Secure`, HSTS is emitted, and `session_regenerate_id()` runs on login. |
| **Avatar / profile images** | ✅ | Served by the `user_avatar` endpoint from `users` + the `storage` volume; cached 1 day; initials fallback never blanks. |
| **File uploads** | ✅ | Stored in the `storage_data` **named** volume (correct `www-data` perms); 20 MB PHP limits aligned in the image; served only via the authed `file_serve` endpoint; Apache denies direct `/storage`. |
| **Session management** | ✅ (single web container) | PHP file sessions are consistent on one instance. They reset if the web container is recreated (re-login) — fine for staging. Multi-instance scale-out would need Redis sessions (out of scope). |
| **Database operations** | ✅ | MySQL in `db_data` volume; `ensureLibrarySchema()` creates all tables on first request; the seed mount bootstraps `users`. |
| **Internal API communication** | ✅ | Same-origin `api/library_handler.php`; CSRF token from the `<meta>` tag; unaffected by the proxy. |
| **Email notifications** | n/a | The app sends **no email** (verified — no `mail()`/SMTP). Notifications are in-app + an SMS queue configured in Settings; nothing to set up. |

---

## 6. Post-deployment verification checklist

```bash
# Health (expect {"status":"ok"})
curl -fsS https://<SITE_ADDRESS>/health.php

# Automated critical-path smoke test (auth → CSRF → catalog → pagination → cleanup)
php deploy/smoke-test.php https://<SITE_ADDRESS> admin@staging.example 'YOUR_STRONG_PASSWORD'
```
In a browser:
- [ ] HTTPS padlock is valid (real cert).
- [ ] Login works; DevTools **Console** shows **no CSP violations**.
- [ ] Sidebar avatar renders; **Members → View** shows the member avatar (initials if none).
- [ ] Upload a document, then download it via the app (served through `file_serve`).
- [ ] Direct hit to an upload path (`/storage/...`) returns **403/denied**.
- [ ] Logout returns to the login page.

---

## 7. Stress-testing procedure

Use the scripts already in [`tests/load/`](../tests/load) (full runbook:
[`tests/load/README.md`](../tests/load/README.md)). **Run them from a separate
machine/region**, not the VPS itself, so the load generator doesn't compete with the
app for CPU.

```bash
# Blended workload, ramps 0→50→100 VUs, with pass/fail thresholds
k6 run -e BASE_URL=https://<SITE_ADDRESS> \
       -e LOAD_USER=admin@staging.example -e LOAD_PASS='YOUR_STRONG_PASSWORD' \
       tests/load/k6-mixed.js

# Per-endpoint ceiling
./tests/load/ab.sh https://<SITE_ADDRESS> admin@staging.example 'YOUR_STRONG_PASSWORD'
```
Pass criteria: `http_req_failed` < 1%, `p95` < 800 ms, `p99` < 2 s.

**Watch on the VPS while testing** (`ssh` in a second terminal):
```bash
docker stats                                   # CPU/RAM per container (web is the usual ceiling)
docker compose -f docker-compose.staging.yml logs -f web
docker compose -f docker-compose.staging.yml exec db mysqladmin -uroot -p processlist
```
If `p95` degrades early: the most likely causes (per the performance assessment) are an
unpaginated Inventory screen on a huge catalog or `LIKE` searches — see
[`docs/performance-assessment.md`](performance-assessment.md).

**Teardown** (and reclaim the cost):
```bash
docker compose -f docker-compose.staging.yml down -v   # -v also drops the volumes
```
