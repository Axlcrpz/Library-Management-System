# Load & stress testing

Realistic load tooling for the ~50–100 concurrent-user target. Run these against
**staging** (never production first), ideally the actual `docker-compose.prod.yml`
stack so you measure the real OPcache + gzip configuration.

## Prerequisites

- A running app + DB (use the prod compose, or `php -S` + a MySQL).
- A known login. The CI E2E seeds `admin@e2e.test` / `adminadmin!` — reuse that,
  or pass your own.
- Tools: [k6](https://k6.io/docs/get-started/installation/) for the blended test;
  `apache2-utils` (ApacheBench) for per-endpoint probes.

## 1. Blended workload (primary) — k6

Models each user logging in, then looping dashboard / inventory browse / search /
stats / my-borrows reads with ~20% borrow-request writes. Ramps 0→50→100 VUs.

```bash
k6 run \
  -e BASE_URL=https://staging.example.org \
  -e LOAD_USER=admin@e2e.test \
  -e LOAD_PASS='adminadmin!' \
  tests/load/k6-mixed.js
```

**Pass criteria (thresholds baked into the script):**
- `http_req_failed` &lt; 1% — transport errors
- `http_req_duration p95` &lt; 800 ms, `p99` &lt; 2 s
- `app_errors` &lt; 2% — app-level `{success:false}`

If thresholds fail, k6 exits non-zero and prints which metric blew out.

## 2. Per-endpoint probe — ApacheBench

Isolate a single endpoint's ceiling (RPS, p95/p99, failures) at concurrency 50:

```bash
./tests/load/ab.sh https://staging.example.org admin@e2e.test 'adminadmin!'
# tune volume/concurrency:  N=2000 C=100 ./tests/load/ab.sh ...
```

## Scenarios covered

| Scenario | Endpoint(s) | Type |
|----------|-------------|------|
| Simultaneous logins | `POST /login.php` | write + bcrypt |
| Dashboard access | `book_stats`, `announcements_get` | read (aggregate) |
| Inventory browse | `books_get?page&per_page` | read (paged) |
| Concurrent search | `books_get?q=&per_page` | read (LIKE scan) |
| Inventory stats | `inventory_stats` | read (aggregate) |
| Borrow submission | `book_borrow_request_add` | write (txn) |
| Borrow approve / return | `book_borrow_approve` / `_return` | write (txn + row locks) |
| My borrows | `book_borrow_requests_get?scope=mine` | read |
| File upload | `add` (multipart) | write + disk I/O |

> The borrow **approve/return** writes take row locks (`SELECT … FOR UPDATE`); to
> stress lock contention, point many VUs at approvals of the *same* book. The
> mixed script keeps writes light to model normal usage.

## What to watch on the host while testing

- **CPU**: `docker stats` — PHP (Apache) CPU is the usual first ceiling.
- **RAM**: PHP prefork workers (~30–40 MB each) + MySQL buffer pool.
- **DB**: `SHOW PROCESSLIST`, `SHOW ENGINE INNODB STATUS` for lock waits; enable the
  slow query log (`long_query_time=0.5`) to catch the `LIKE '%q%'` scans.
- **Apache**: `server-status` (if enabled) for busy workers vs `MaxRequestWorkers`.

See [`docs/performance-assessment.md`](../../docs/performance-assessment.md) for the
bottleneck analysis, capacity estimates, and the optimizations already applied.
