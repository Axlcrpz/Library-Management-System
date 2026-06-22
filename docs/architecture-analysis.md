# Architecture Analysis — Overlaps, Relationships & Refactoring

This doc answers three questions with evidence from the code:

1. Where do features **overlap or duplicate**, and why? (the three "add book" doors, dual circulation, triple reservations)
2. How do modules **connect** to each other? (feature relationship map)
3. What should be **simplified or refactored**, and how?

---

## Part 1 — Overlapping & duplicate functionality

### 1A. The three "Add Book" doors

The single most-asked question. Here is what each door **actually** does in code.

| Door | Entry action | Persist action | What it writes | Dedup? | Enrichment? |
|------|--------------|----------------|----------------|--------|-------------|
| **Manual input** | modal form | `books_add` | basic `books` row (title, author, isbn, qty, condition) | ❌ none | ❌ none |
| **ISBN Search** | `isbn_lookup` (Open Library, **read-only prefill**) | `books_add` (**same as manual**) | basic `books` row, fields pre-filled | ❌ none | ❌ none |
| **Discovery Add** | `discovery_search` (multi-provider, cached, scored) | `book_add_from_discovery` | **enriched** `books` row + `authors`/`categories`/`book_external_ids` + enqueues `book_enrich_queue` | ✅ isbn13 → isbn10 → fuzzy(title,year) | ✅ async relations |

**The key realization:** *ISBN Search is not a separate save path.* `handleIsbnLookup`
([library_handler.php:4610](../api/library_handler.php)) only **fetches and returns
metadata** to pre-fill the manual form — the actual insert still goes through
`handleBooksAdd`. So functionally there are **two persistence paths**, not three:

- **`books_add`** — thin insert, no dedup, no metadata enrichment, no provenance.
- **`book_add_from_discovery`** — rich insert/link with duplicate prevention,
  dimension tables, external-id provenance, and enrichment.

#### Why do they exist separately?

Layered history, visible in the schema versions: Manual CRUD came first; ISBN
lookup was bolted on as a quick Open Library prefill; the full Discovery
subsystem (Google → Open Library → LoC → Internet Archive, caching, rate limits,
scoring, dedup, relations) came last (schema "Book Discovery v1"). **Each new door
solved an incremental need, but the earlier ones were never retired.**

#### Do they serve different business purposes?

Partially. The genuinely distinct needs are only **two**:

| Real need | Best door |
|-----------|-----------|
| Book exists in an external catalog (has ISBN **or** is searchable by title/author) | **Discovery** — it already handles ISBN *and* keyword, with covers + clean metadata + dedup |
| Book is **not** in any external catalog (local/Filipiniana pubs, old textbooks, internal records) | **Manual** — must be hand-entered |

The standalone **ISBN Search door does not add a distinct capability** — Discovery's
`isbn` mode already does ISBN lookup, and does it *better* (cached via `api_cache`,
rate-limited via `api_rate_buckets`, multi-provider fallback, dedup on insert).

#### Concrete redundancies & design concerns

1. **Two code paths to Open Library for ISBN.** `isbn_lookup` calls
   `file_get_contents` directly and **bypasses** the `api_cache` + rate-limit
   infrastructure that `discoveryFetch()` uses. Duplicate logic, no caching, and a
   second place to maintain provider quirks.
2. **Two insert paths → inconsistent catalog quality.** The same physical book
   added via Manual/ISBN gets a thin row (no `isbn13`, `cover_url`, `source`,
   author/category dimensions); added via Discovery it gets a rich, deduped row.
   Your catalog ends up with two tiers of data quality for identical content.
3. **Duplicate risk.** `book_add_from_discovery` dedups on isbn13/isbn10/fuzzy;
   `books_add` does **not**. Adding the same title twice through the manual/ISBN
   door silently creates duplicate catalog entries (and the `uq_books_isbn13`
   unique index will instead throw a raw error if isbn13 ever gets populated).

#### Recommendation — collapse three doors into one surface, two outcomes

```
        ┌──────────────────────────────────────────────┐
        │   Add Book  (single modal)                    │
        │   ┌────────────────────────────────────────┐  │
        │   │ 🔍 Search by title, author, or ISBN …  │  │  ← one box → discovery_search
        │   └────────────────────────────────────────┘  │     (auto-detects ISBN vs keyword)
        │   results (covers, "already in library" tag)  │  → book_add_from_discovery
        │                                                │
        │   Can't find it?  ▸ Enter manually            │  ← fallback only
        └──────────────────────────────────────────────┘
```

- **Retire the separate ISBN-Search door.** Either delete `isbn_lookup` or make it
  a thin wrapper over `discoveryFetch($pdo,'isbn',…)` so it shares cache + rate
  limiting. One ISBN code path.
- **Route every "matched" add through `book_add_from_discovery`** so dedup +
  enrichment always run.
- **Keep Manual strictly as the no-match fallback**, and harden it: add isbn13
  dedup to `books_add` and (optionally) enqueue it into `book_enrich_queue` so even
  hand-entered books get backfilled by cron.

**Net effect:** one consistent door, one quality tier, no duplicate rows, half the
provider-integration code to maintain — without losing the ability to enter books
that aren't in any external catalog.

---

### 1B. Dual circulation systems (Documents vs Books)

There are **two complete borrow/return implementations**:

| | Documents | Books |
|--|-----------|-------|
| Object | `library_documents` (files/records) | `books` (catalog titles) |
| Tables | `library_borrowing_records` | `book_borrow_records` + `book_borrow_items` |
| Model | **single copy** (`is_borrowed` flag) | **multi-copy** (quantity accounting) |
| Workflow | mark borrowed → mark returned | request → approve → borrow → return |
| Fines/policies | none | `borrowing_policies`, `due_at`, `fine_amount` |
| Reservations | none | reservation-capacity aware |
| Actions | `borrow`, `return`, `borrow_history` | `book_borrow_*` (6 actions), `calculate_fine` |
| UI | Documents tab | Borrowing tab |

**Is this duplication?** *Conceptually yes, structurally no.* They manage **different
entities** with **different semantics** (a document is a one-off file with version
history; a book is stock with copies). Forcing them into one table would be wrong.

**But** they duplicate the *concept* of lending and split it across two UIs,
two history endpoints, and two audit trails — and they **share** the
`library_borrowers` registry, which blurs the line.

**Recommendation (moderate, not a rewrite):**
- **Keep the two data models** — the entities genuinely differ.
- **Unify the patron layer** (one borrower registry, already mostly shared) and the
  **reporting/history layer** (a single "loans" view that unions both, tagged by
  type), so staff have one place to see who has what.
- **Label clearly in the UI** ("Document loan" vs "Book loan") to end the confusion.
- Long-term, the single-copy document loan could be expressed as the book model
  with `quantity=1`, but only worth it if document/file semantics (versioning) are
  preserved. Low priority.

---

### 1C. Triple reservation tables (the clearest redundancy)

Three tables model "holds":

| Table | Generation | Status model | Still used by |
|-------|-----------|--------------|---------------|
| `reservations` | **v1** | queue_position + waiting/ready | `get_reservations`, `create_reservation`, `cancel_reservation`, `notify_next_in_queue`, `expire_reservations` |
| `book_reservations` | **v2** | 8-state enum (pending…no_show), date-ranged, quantity | `reservation_calendar`, `reservation_convert`, sweep |
| `book_waitlist` | **v2** | offers (waiting/offered/converted…) | `waitlist_join`, `waitlist_respond` |

The migration block at [library_handler.php:650](../api/library_handler.php) does a
**one-time copy of `reservations` → `book_waitlist`** (guarded by a
`res_v2_migrated` flag). That is a strong signal that **v1 `reservations` is
superseded** by the v2 pair — yet the v1 handlers still exist and are still wired.

**Recommendation:**
- Confirm which set [`templates/reservations.php`](../templates/reservations.php)
  actually binds to (the v2 calendar/convert flow is the richer, intended one).
- **Retire v1 `reservations`** + its five handlers once the UI is confirmed on v2.
  Keep `book_reservations` (advance, date-ranged bookings) + `book_waitlist`
  (queue/offers) as the single holds system.
- This removes ~5 endpoints and a whole table with no loss of capability.

---

## Part 2 — Feature relationship map

How the modules actually interact (data + control flow). A rendered diagram
accompanies this doc; the text version:

```
                         ┌─────────────┐
                         │    Auth     │  login/register/approve/logout
                         │ users·CSRF  │
                         └──────┬──────┘
                                │ session + role
        ┌───────────────────────┼─────────────────────────────┐
        ▼                       ▼                              ▼
  ┌───────────┐          ┌─────────────┐                ┌────────────┐
  │ Dashboard │◄────────►│  Inventory  │◄──────────────►│ Discovery  │
  │ stats +   │  reads   │   (books)   │  enrich/dedup  │ providers  │
  │ announce. │          └──────┬──────┘                │ +api_cache │
  └───────────┘                 │ stock                 └────────────┘
        ▲                       │
        │ notices               ├───────────────┐
        │                       ▼               ▼
  ┌───────────┐          ┌─────────────┐  ┌──────────────┐
  │  Members  │◄────────►│  Borrowing  │◄►│ Reservations │  capacity check
  │ borrowers │ patron   │ book_borrow │  │ book_res/wl  │  (resMaxForRange)
  └───────────┘          └──────┬──────┘  └──────────────┘
        ▲                       │ fines           │
        │ patron                ▼                 ▼
  ┌───────────┐          ┌─────────────┐   ┌──────────────┐
  │ Delivery  │──stock──►│  Policies   │   │ Notifications│
  │ Log       │          │ (Settings)  │   │ +SMS queue   │
  └───────────┘          └─────────────┘   └──────────────┘

  Documents ──► library_borrowing_records (own loan path) ──► Archive / Trash
  Everything state-mutating ──► Audit Logs ;  cron.php sweeps Reservations/Discovery/Trash
```

**Hub modules** (most connected): **Inventory (books)** and **Borrowing**. Almost
everything either feeds stock into Inventory (Delivery, Discovery) or consumes it
(Borrowing, Reservations). **Settings/Policies** is the silent governor of
Borrowing & Reservations. **Audit Logs** is a universal sink.

---

## Part 3 — Optimization & refactoring opportunities

Ranked by value-to-effort. Each is grounded in the code above.

### High value

1. **Unify the Add-Book doors** (Part 1A). Retire standalone ISBN search; route all
   external adds through discovery; add isbn13 dedup to manual. *Removes duplicate
   provider code, ends catalog inconsistency and duplicate rows.*

2. **Retire reservations v1** (Part 1C). Consolidate onto `book_reservations` +
   `book_waitlist`. *Deletes a table + ~5 endpoints; one holds model.*

3. **Server-side pagination/search for Inventory.** The list endpoints appear to
   return whole tables; the `ft_books` FULLTEXT index already exists to support
   this. *Critical as the catalog grows; today it ships every row to the browser.*
   (Matches backlog item #8.)

### Medium value

4. **Split the 5,712-line `library_handler.php`.** It's one file with ~115 actions,
   the schema migration, and all business logic. Extract domain modules
   (`books.php`, `circulation.php`, `reservations.php`, `discovery.php`,
   `users.php`, `admin.php`) included by a thin router. *Improves navigability,
   reviewability, and makes unit testing possible.* Do this **after** adding tests,
   not before.

5. **Unify the loan/history/reporting layer** across Documents + Books (Part 1B).
   One "who has what" view, tagged by type. *Ends the two-places-to-look problem
   without merging the data models.*

6. **A user-facing notification center + due reminders.** Today `admin_notifications`
   is admin-only and due reminders exist only as SMS templates. Regular borrowers
   have no in-app notice of approvals/due dates. (Backlog #9.)

7. **Fines lifecycle.** `fine_amount` is computed but there's no payment/waiver/
   settled state. Add a small fines ledger + statuses. (Backlog #10.)

### Lower value / structural

8. **Consider foreign-key constraints** (or document the invariants). The schema is
   index-only; cross-table integrity rests entirely on handler discipline. FKs (or
   at least a documented invariant list) would prevent orphan rows.

9. **Collapse the two "borrower" notions** where possible. `users` vs
   `library_borrowers` is intentional (walk-ins), but the `user_id` link is
   under-used; a clear rule ("every logged-in borrow resolves to exactly one
   patron row") enforced in one helper (`borrowerForUser`) already exists — make it
   the *only* path and document it.

### Explicitly NOT recommended

- **Don't merge Documents and Books circulation into one table.** They model
  different entities; the shared concept is best handled at the UI/reporting layer,
  not the storage layer.
- **Don't add a build step / framework** just to "modernize." The no-build, flat-PHP
  approach is a deliberate fit for this deployment (XAMPP/Docker, small team). The
  wins above are all achievable within it.

---

## Quick reference — redundancy scorecard

| Area | Verdict | Action |
|------|---------|--------|
| ISBN Search door | **Redundant** (subset of Discovery, thinner persistence) | Retire / wrap over discovery |
| Manual add | **Keep** (offline fallback) | Harden: dedup + optional enrich |
| Discovery add | **Keep** (the good path) | Make it the default |
| Documents vs Books circulation | **Not duplicate** (different entities) | Unify patron + reporting layers only |
| reservations v1 vs book_reservations/waitlist v2 | **Redundant** (v1 superseded) | Retire v1 |
| 5.7k-line handler | **Tech debt** | Split by domain (after tests) |
