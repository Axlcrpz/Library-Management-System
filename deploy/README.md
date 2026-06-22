# Deployment (generic)

A sanitized, public deployment guide. Office-internal runbooks that reference the
real domain and Cloudflare tunnel are kept **private** (gitignored) — this file
contains no environment-specific hostnames or secrets.

## Prerequisites
- Docker + Docker Compose on the host.
- A TLS terminator in front of the app (reverse proxy or Cloudflare Tunnel). The
  app binds to `127.0.0.1:8080` in production — never expose it directly.

## First run
```bash
# 1. Secrets
cp .env.example .env
#    edit .env → set strong DB_PASS and MYSQL_ROOT_PASSWORD

# 2. Database bootstrap (schema-only template; no default admin)
cp deploy/seed.sql.example deploy/seed.sql

# 3. Build + start the hardened production stack
docker compose -f docker-compose.prod.yml up -d --build

# 4. Create the first admin (no plaintext password in any file)
docker compose exec web php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
#    then insert one row into `users` with that hash (see deploy/seed.sql.example)

# 5. Verify
curl -fsS http://127.0.0.1:8080/health.php        # {"status":"ok"}
php deploy/smoke-test.php http://127.0.0.1:8080 admin@your-office.example 'YOUR_STRONG_PASSWORD'
```

## After deployment
- Schedule `backup.sh` (cron) and test a restore.
- Point an uptime monitor at `/health.php`.
- Configure SMS gateway + templates from the in-app **Settings** page (stored in the
  database, never in the repo).
- Confirm HTTPS + HSTS at the edge; confirm no CSP console errors in staging.

See [`docs/`](../docs) for architecture, the production-readiness roadmap, and the
performance assessment; see [`SECURITY.md`](../SECURITY.md) before publishing.
