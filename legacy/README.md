# legacy/ — archived code (NOT part of the running system)

Files here are retained for reference and recovery only. They are:
- **excluded from the Docker image** (`.dockerignore` ignores `/legacy/`),
- **denied all web access** (`legacy/.htaccess` → `Require all denied`),
- **not referenced** by any running code (no `require`/`include`, no `fetch`, no
  form action, no route, no Docker/Apache/cron reference).

## tnf_handler.php
An earlier, standalone version of the document-management API. It was fully
superseded by `api/library_handler.php`, which handles the same
`get / add / update / delete / trash / archive / borrow / return` document actions
(and everything else) with the current security posture (global CSRF gate, headers,
scoped guards). `tnf_handler.php` had its **own** router, CSRF list, access check
(`apiRequireTnfAccess`), and schema (`ensureTnfSchema`) — an unmonitored parallel
surface that did **not** inherit later security fixes.

Verified unused across all vectors (PHP includes, AJAX/fetch, JS, form actions,
routes, dynamic includes, Docker/Apache config, cron, case-insensitive token scan)
before archiving. Moved here with `git mv` (history preserved) instead of deleting,
pending a short monitoring period on staging.

- **Permanently remove later:** `git rm legacy/tnf_handler.php && git commit`
- **Restore (not recommended):** `git mv legacy/tnf_handler.php api/tnf_handler.php`
