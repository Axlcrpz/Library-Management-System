# Performance & Load Assessment

Target: **50–100 concurrent users** doing common actions. This assessment is
**evidence-based from code inspection** — the actual hot paths in
`api/library_handler.php`, the Apache/PHP config in the `Dockerfile`, the session
model in `auth.php`, and the front-end in `assets/js/`. Live numbers come from the
load scripts in [`tests/load/`](../tests/load); they could not be executed in the
analysis environment (no running stack), so capacity figures below are reasoned
estimates clearly labelled as such — **run the k6 script against staging to confirm**.

## Methodology

1. Inspected every endpoint on the concurrent hot path (login, dashboard stats,
   inventory browse/search, borrow request/approve/return, my-borrows, avatar, file
   serve) and the request bootstrap.
2. Inspected the runtime config (mod_php/prefork, OPcache, gzip, sessions, upload
   limits) and the schema indexes.
3. Built runnable load tests (k6 blended + ApacheBench per-endpoint).
4. Implemented only the safe, high-gain optimizations; deferred anything with
   meaningful regression risk to "recommended".

---

## Bottleneck register

Severity = impact on the 50–100-user target. **Status: ✅ fixed this pass · ◐ partly addressed · 📋 recommended (deferred).**

| # | Bottleneck | Sev | Impact @50–100 users | Root cause | Optimization | Est. gain | Regression risk | Status |
|---|-----------|:---:|----------------------|-----------|-------------|:---------:|:---------------:|:------:|
| 1 | **No OPcache** | 🔴 Critical | #1 CPU limiter — every request reparses/recompiles the 5.7k-line handler | `php:8.2-apache` shipped with no opcache config | Enable + tune OPcache (`Dockerfile`) | **2–3× PHP throughput** | Very low | ✅ |
| 2 | **Session-lock serialization** | 🟠 High | A user's parallel GETs (dashboard/inventory fire several at once) run one-at-a-time | `session_start()` holds a per-user file lock for the whole request; never released | `session_write_close()` for GET reads (verified no GET writes session) | Large p95/p99 drop on multi-fetch screens | Low | ✅ |
| 3 | **Unbounded list + full client render** | 🟠 High | Large catalogs ship every row as JSON and render every card to the DOM | `books_get` default returns all rows; `inventory.js` renders all | Backward-compat pagination + server search + `inventory_stats` aggregate + staged UI opt-in | Bounded payload/DOM when enabled | Low (default unchanged) | ◐ |
| 4 | **No gzip compression** | 🟠 High | Big JSON (`books_get`) + JS/CSS sent uncompressed → slow TTFB, bandwidth | No `mod_deflate` | Enable `mod_deflate` for text/JSON (`Dockerfile`) | **60–80% smaller** text responses | Very low (BREACH noted, behind TLS) | ✅ |
| 5 | **`LIKE '%q%'` search = full scan** | 🟡 Medium | Each search scans the whole `books` table; compounds under concurrent search | Leading-wildcard `LIKE` can't use an index | Switch to the existing `ft_books` FULLTEXT (`MATCH … AGAINST`) | Large on big catalogs | Moderate (match semantics change) | 📋 |
| 6 | **Per-request trash purge** | 🟡 Medium | A maintenance query on every API call | `purgeExpiredTrash()` ran unconditionally in the bootstrap | Sample 1/50 + rely on cron | Removes a query/request | Low | ✅ |
| 7 | **Prefork + mod_php, no limits** | 🟡 Medium | Memory-bound concurrency; a runaway can starve the host | Default MPM, no `MaxRequestWorkers`/container limits tuning | OPcache (done) cuts per-worker CPU; **size workers + cpu/mem limits to the host** | Predictable ceiling | Low (sizing, not forced) | 📋 |
| 8 | **`readfile` file serving blocks a worker** | 🟡 Medium | Many concurrent large-doc downloads exhaust prefork workers | Auth requires serving through PHP | `mod_xsendfile` (X-Sendfile) to hand the transfer to Apache | Frees workers during transfers | Low–moderate (module/config) | 📋 |
| 9 | **Login bcrypt CPU** | 🟢 Low–Med | A start-of-day login burst spikes CPU | `password_verify` cost-10 is intentionally heavy (~50–100 ms) | Keep (security); cost is one-time per session, bounded by lockout | n/a | n/a | Accept |
| 10 | **Synchronous external API in discovery** | 🟢 Low | Staff-only, cache-missed search blocks a worker on Google/OpenLibrary/LoC/IA | External HTTP on cache miss | Already cached (7 d/24 h) + per-provider rate-limited | n/a | n/a | Accept |
| 11 | **Report aggregates** | 🟢 Low–Med | Admin-only, infrequent; not in the concurrent hot path | Date-range GROUP BYs in `reports.php` | Verify with the slow-query log; add covering indexes if flagged | situational | Low | 📋 |
| 12 | **Schema-version check / request** | 🟢 Low | One indexed `SELECT` per request | Idempotent migration guard | Necessary; already short-circuits | negligible | n/a | Accept |

### Notably NOT a problem (verified)
- **N+1 in the circulation list** — already fixed (batched `IN (...)`).
- **Per-book circulation maps in `books_get`** — already aggregate maps, not per-row queries.
- **Avatars** — already send `Cache-Control: max-age=86400`, version-busted, so not a per-page hit after first load.
- **Members list** — already bounded (`LIMIT`, now paginated).

---

## Optimizations applied this pass

| Change | File | Effect | Risk |
|--------|------|--------|------|
| OPcache enabled + tuned | [`Dockerfile`](../Dockerfile) | Compile-once; biggest throughput win | Very low |
| gzip (`mod_deflate`) for text/JSON | [`Dockerfile`](../Dockerfile) | 60–80% smaller text responses | Very low |
| Static-asset cache headers (`mod_expires`, CSS/JS) | [`Dockerfile`](../Dockerfile) | Fewer asset refetches | Very low |
| PHP prod ini (`memory_limit=256M`, realpath cache) | [`Dockerfile`](../Dockerfile) | Headroom + less stat churn | Very low |
| **Upload limits aligned to 20 MB** (`upload_max_filesize`/`post_max_size`) | [`Dockerfile`](../Dockerfile) | Fixes silently-rejected large uploads (PHP defaults were 2 M/8 M) | Very low |
| `session_write_close()` on GET | [`api/library_handler.php`](../api/library_handler.php) | Unblocks a user's concurrent reads | Low |
| Trash purge sampled 1/50 | [`api/library_handler.php`](../api/library_handler.php) | Removes a per-request maintenance query | Low |

(Prior sprints already delivered the N+1 fix, pagination, server search, and the
`inventory_stats` aggregate that underpins #3.)

---

## Capacity estimate (reasoned — confirm with the load test)

> These are engineering estimates from the code + the chosen runtime, **not measured
> numbers**. Run `tests/load/k6-mixed.js` against staging for the real ceiling.

Assuming the recommended **2–4 vCPU / 4 GB** host running `docker-compose.prod.yml`:

| Metric | Before this pass | After (opcache+gzip+session+paging) |
|--------|------------------|-------------------------------------|
| Sustained concurrent users (read-heavy, with think time) | ~30–50 before p95 degrades | **80–120** within p95 < 800 ms |
| Light read RPS (`book_stats`/`inventory_stats`) | tens/s (recompile-bound) | **hundreds/s** |
| Heavy `books_get` (large catalog, **unpaginated**) | slowest path; degrades early | bounded once paginated — enable server paging |
| Borrow write RPS (txn + row locks) | dozens/s | dozens/s (lock-bound, unchanged — correct) |

**Likely breaking points (in order):**
1. **Unpaginated inventory load on a large catalog** — payload + DOM. *Mitigation:* enable `INV_SERVER_STATS` + paged `books_get`.
2. **Concurrent `LIKE '%q%'` searches on a large `books` table** — table scans. *Mitigation:* FULLTEXT (#5).
3. **PHP worker memory ceiling** under prefork — *Mitigation:* size `MaxRequestWorkers` + container limits (#7).
4. **Login bursts** — bcrypt CPU; transient, acceptable.

**Resource projection (peak ~100 users):** PHP/Apache is the primary CPU consumer;
prefork workers ~30–40 MB each (≈1.5–2 GB for ~50 busy workers); MySQL buffer pool
128 MB default + per-connection overhead. **Floor: 2 vCPU / 2 GB. Comfortable: 4
vCPU / 4 GB.** Set container `mem_limit`/`cpus` to ~75% of host and cap
`MaxRequestWorkers` so `workers × ~40 MB` stays under the web container's memory.

---

## Scores & recommendation

| | Before | After |
|--|:--:|:--:|
| **Performance score** | ~5 / 10 | **~7.5 / 10** |
| Production-readiness (overall) | ~8.1 | **~8.3** |

**Performance ~7.5/10:** the top three bottlenecks (no OPcache, session-lock
serialization, uncompressed payloads) are fixed with low-risk changes; lists are
paginated and aggregates are server-side. The remaining items (FULLTEXT search,
prefork→FPM or worker sizing, X-Sendfile) are deferred as either moderate-risk or
host-specific — none block the 50–100-user target.

**Recommendation: performance-ready for 50–100 users — conditional on one verification.**
Before go-live, run `tests/load/k6-mixed.js` against the staging prod-compose stack
and confirm the thresholds pass (p95 < 800 ms, errors < 1%). If your catalog is
large (10k+ titles), also enable the staged Inventory server-paging and re-run.
With those confirmed, the system meets the load target with headroom.
