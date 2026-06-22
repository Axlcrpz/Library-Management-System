# Security & Safe-Publication Guide

This repository belongs to a **government office**; treat every change as a
production change. This document is the pre-publication checklist, the secret
rotation list, the "no secrets remain" verification commands, and the safe-push
steps. **No real secret values appear in this repo or this file.**

## Reporting a vulnerability

Email the maintainer privately (do **not** open a public issue for security bugs).
Replace this line with your office's security contact before publishing.

---

## 1. Files that must NEVER be committed

All of these are excluded by [`.gitignore`](.gitignore) (verified). They stay on
your machine as local/private files — they are not deleted, just never published.

| Item | Why |
|------|-----|
| `.env`, `.env.*` (except `.env.example`) | DB credentials, root password |
| `deploy/seed.sql` | **Real DB dump** — emails, phone numbers, LRNs, password hashes, **a live SMS API key** |
| `deploy/RUNBOOK.md`, `deploy/STEP-BY-STEP.md` | Production domain + Cloudflare tunnel hostnames (infra disclosure) |
| `deploy/tunnel.log`, `*.log` | Tunnel/runtime logs (hostnames, internal detail) |
| `cloudflared.exe`, `.cloudflared/`, `cert.pem`, `*credentials*.json` | Tunnel binary + credentials |
| `login_debug.php`, `reset_admin.php`, `_locdbg.php` | CLI tools carrying a default admin password literal |
| `*.pem *.key *.crt *.p12 *.pfx id_rsa*` | Private keys / certificates |
| `/storage/attachments/**`, `/storage/profiles/**` | Uploaded PII (photos, documents, delivery scans) |
| `/backups/`, `*.sql.gz` | Database backups (data) |
| `/.claude/`, `/.vscode/`, `/.idea/` | Editor/agent state, local paths |

Safe templates that **are** published: [`.env.example`](.env.example),
[`deploy/seed.sql.example`](deploy/seed.sql.example).

## 2. Secrets to ROTATE before / right after deployment

> Git history was checked: **2 commits, none containing secrets** — so nothing has
> leaked *via this repo*. Rotation below is precautionary because these values have
> existed in plaintext files on disk (`deploy/seed.sql`, tunnel logs).

- [ ] **SMS gateway API key** — a live 32-char key sits in `deploy/seed.sql`. Rotate
      it at the SMS provider and set the new value only via the in-app Settings page
      (it is stored in `library_settings`, never in the repo).
- [ ] **MySQL `root` password** and **`lms_user` password** — set fresh, strong
      values in the production `.env` (the compose defaults `root`/`lms_pass` are
      dev-only and must never reach production).
- [ ] **Cloudflare tunnel token/credentials** — if `deploy/tunnel.log` or the
      runbooks were ever shared, rotate the tunnel.
- [ ] **Admin account passwords** — if the real `deploy/seed.sql` was ever shared
      outside the office, force-reset those accounts (hashes are bcrypt, but treat
      as compromised if the dump left your control).

## 3. Verify NO secrets remain (run before every push)

```bash
# (a) Nothing sensitive is tracked or would be staged:
git ls-files | grep -iE 'seed\.sql$|\.env$|tunnel\.log|cloudflared|reset_admin|login_debug|\.(pem|key|crt|p12|pfx|sql\.gz)$' \
  && echo "FAIL: sensitive file tracked" || echo "OK: nothing sensitive tracked"

git add -A --dry-run | grep -iE 'seed\.sql$|\.env$|cloudflared|RUNBOOK|STEP-BY-STEP|reset_admin|login_debug|/\.claude/' \
  && echo "FAIL: sensitive file would be staged" || echo "OK: clean staging"

# (b) Scan the whole repo + history with a dedicated tool (recommended):
#   gitleaks detect --source . --redact
#   trufflehog git file://. --only-verified
```
Install one of [gitleaks](https://github.com/gitleaks/gitleaks) or
[trufflehog](https://github.com/trufflesecurity/trufflehog) and make it a CI gate.

## 4. Safe publication steps

```bash
# 1. Confirm the working tree is clean of secrets (section 3 above).
# 2. If you EVER ran `git add` before hardening .gitignore, un-stage anything sensitive:
git rm --cached --ignore-unmatch deploy/seed.sql login_debug.php reset_admin.php
git rm -r --cached --ignore-unmatch .claude deploy/RUNBOOK.md deploy/STEP-BY-STEP.md

# 3. Stage + commit (the hardened .gitignore now protects you):
git add -A
git status                       # eyeball the list — nothing sensitive should appear
git commit -m "Prepare for public release: harden ignores, sanitize templates"

# 4. Create the GitHub repo as PRIVATE first, push, then review on GitHub:
git remote add origin git@github.com:<you>/<repo>.git
git push -u origin main

# 5. Only flip to Public after a human review of the file list on GitHub.
```

## 5. If a secret is EVER committed (history scrub — NOT needed today)

History is currently clean. If a secret slips in later, removing the file is **not**
enough — it stays in history. Use [git-filter-repo](https://github.com/newren/git-filter-repo):

```bash
# Purge a file from ALL history, then force-push and rotate the secret:
git filter-repo --invert-paths --path deploy/seed.sql
git push --force --all
git push --force --tags
```
Then **rotate the exposed credential immediately** — assume it is compromised the
moment it touches a remote. Notify collaborators to re-clone (history was rewritten).

## 6. Standing rules

- App-level secrets (SMS key, etc.) live in the database `library_settings`, set via
  the admin UI — never in code, `.env`, or the repo.
- `config/database.php` reads all DB creds from the environment; its `lms_user`/
  `lms_pass` fallbacks are **dev-only** and must be overridden in production.
- Keep the production `docker-compose.prod.yml` (no phpMyAdmin, no exposed DB port).
- Add `gitleaks`/`trufflehog` as a required CI check so this can't regress.
