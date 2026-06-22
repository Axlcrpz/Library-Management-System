# Data Model

~38 tables, all InnoDB/`utf8mb4`. Created/migrated at runtime by
`ensureLibrarySchema()` in [`api/library_handler.php`](../api/library_handler.php)
(gated by `library_settings.schema_version = '13'`). `login.php` additionally
creates `login_attempts` and migrates identity columns onto `users`.

> **Important integrity note:** the schema uses **indexes, not foreign-key
> constraints**. Relationships below are *by convention* — enforced in
> application code and transactions, not by the database. This keeps migrations
> simple and tolerant, but means orphan rows are possible if a handler misbehaves;
> referential integrity is the handlers' responsibility (most mutations that span
> tables run inside `beginTransaction()`/`commit()`).
>
> `users` is the one table **not created here** — it's assumed to pre-exist
> (initial setup) and is only *altered* (adds `classification`, `status`,
> `is_active`, `profile_image`, `avatar_id`, …).

## Domains

### Identity & access
| Table | Key columns | Role |
|-------|-------------|------|
| `users` | id, username(email), password(bcrypt), full_name, role, status, is_active, classification, profile_image, avatar_id | The account of record |
| `login_attempts` | username, ip, attempted_at | Brute-force throttle (per username+IP, 15-min window) |
| `user_sessions` | id(PK varchar), user_id, ip, user_agent, last_active | "Active devices" for *My Account ▸ Security* |
| `user_preferences` | user_id(PK), theme, items_per_page | Per-user UI prefs |
| `user_notification_prefs` | user_id(PK), notify_* flags | Opt-in/out of SMS/announcement notices |

### Documents domain (legacy circulation)
| Table | Key columns | Role |
|-------|-------------|------|
| `library_documents` | id, title, document_type, file_*, is_archived, is_deleted+deleted_at, **is_borrowed** | File/record catalog |
| `library_document_versions` | document_id→, version_number, change_type, changed_by | Version history snapshots |
| `library_borrowers` | id, name(unique), contact, user_id, lrn(unique sparse), classification, … | Patron registry (shared with §Members) |
| `library_borrowing_records` | document_id→, borrower_id→, borrowed_at, returned_at | Single-copy document loans |

### Books / catalog
| Table | Key columns | Role |
|-------|-------------|------|
| `books` | id, title, author, isbn, **isbn13/isbn10**(unique), quantity_total/available/damaged/missing, condition_status, cover_url, description, lcc, ddc, source, metadata_score, is_archived | Catalog + stock + enriched metadata; FULLTEXT(title,author,description) |
| `authors` / `book_authors` | name_norm(unique) / (book_id,author_id,ord) | Normalized author dimension (M:N) |
| `categories` / `book_categories` | slug(unique) / (book_id,category_id) | Normalized category dimension (M:N) |
| `book_external_ids` | book_id→, source, external_id(unique w/ source), raw_json | Provenance from discovery providers |
| `book_relations` | book_id→, relation_type(enum), related_isbn13, score | "Similar/related books" graph (built by cron) |
| `favorites` | (user_id, isbn13)(PK), book_id | User wishlists/favorites |

### Discovery infrastructure
| Table | Key columns | Role |
|-------|-------------|------|
| `api_cache` | cache_key(PK sha256), provider, payload, expires_at | Caches provider responses (ISBN 7d / keyword 24h) |
| `api_rate_buckets` | (provider, window_start)(PK), cnt | Per-provider, per-minute rate limiting |
| `book_enrich_queue` | book_id(PK), status(enum), attempts | Work queue drained by cron to build relations |

### Book circulation
| Table | Key columns | Role |
|-------|-------------|------|
| `book_borrow_records` | id, borrower_id→, status, borrowed_at, **due_at**, returned_at, **fine_amount**, reservation_id, requested_start/due, time_allowed_minutes | The borrow request/loan header |
| `book_borrow_items` | borrow_id→, book_id→, quantity, returned_quantity/damaged/missing | Per-book lines of a borrow |

### Reservations / holds (three overlapping tables)
| Table | Key columns | Role |
|-------|-------------|------|
| `reservations` | user_id, book_id, queue_position, status(waiting/ready) | **v1** simple hold queue (legacy) |
| `book_reservations` | book_id, user_id, quantity, start_date, end_date, status(enum 8-state), borrow_id, pickup_deadline | **v2** date-ranged, quantity-based bookings |
| `book_waitlist` | book_id, user_id, quantity, status(enum), offer_qty, offer_expires_at | **v2** waitlist + offers when stock frees up |

### Acquisition
| Table | Key columns | Role |
|-------|-------------|------|
| `deliveries` | id, delivery_date, source, status, po_number, ref_number, received_by | Incoming shipment header |
| `delivery_items` | delivery_id→, book_id→, quantity_received/damaged/missing | Per-book received lines |
| `delivery_documents` | delivery_id→, file_* | Attached receiving paperwork |
| `import_logs` | imported_by, totals (imported/skipped/duplicate/error), summary(JSON) | Bulk-import audit |

### Communication
| Table | Key columns | Role |
|-------|-------------|------|
| `announcements` | id, title, body, body_format, priority, category, publish_at, expire_at, is_pinned, is_featured | Notices (rich text) |
| `announcement_attachments` | announcement_id→, file_* | Attached files |
| `announcement_reads` | (announcement_id, user_id)(PK), read_at | Per-user read receipts |
| `admin_notifications` | type, title, body, module, target_id, is_read | Push-style deep-linked alerts |
| `sms_queue` | phone, message, status, related_type/id | Outbound SMS spool |

### Governance / config
| Table | Key columns | Role |
|-------|-------------|------|
| `library_settings` | setting_key(unique), setting_value | Global key/value config + `schema_version` flag |
| `borrowing_policies` | classification(unique), max_borrow_days, max_books_per_borrow, fine_per_day, reservation_expiry_days, grace_period_days | Per-classification loan rules (seeded for child/teen/individual/deped/school/professional/private_institution) |
| `audit_logs` | user_id, action, module, target_type/id, ip, created_at | Immutable activity trail |

## Relationship map (by convention)

```
users ──1:1── user_preferences / user_notification_prefs
users ──1:N── user_sessions / audit_logs / favorites
users ──0:1── library_borrowers (via user_id)            (a login MAY map to a patron)

library_borrowers ──1:N── library_borrowing_records ──N:1── library_documents ──1:N── library_document_versions
library_borrowers ──1:N── book_borrow_records ──1:N── book_borrow_items ──N:1── books

books ──M:N── authors (book_authors)
books ──M:N── categories (book_categories)
books ──1:N── book_external_ids / book_relations / favorites / book_enrich_queue
books ──1:N── delivery_items ──N:1── deliveries ──1:N── delivery_documents

books ──1:N── reservations            (v1)
books ──1:N── book_reservations ──0:1── book_borrow_records (via borrow_id / reservation_id)
books ──1:N── book_waitlist           (v2)

announcements ──1:N── announcement_attachments / announcement_reads
```

### The two "borrower" notions
There are **two** borrower concepts that overlap:
- `users` — people with **login accounts** (authn/authz).
- `library_borrowers` — the **patron registry** used by circulation (includes
  walk-ins with no account). A `library_borrowers.user_id` *optionally* links the
  two. `borrowerForUser()` maps a logged-in user to their patron row on demand.

This duality is intentional (walk-ins must be borrowable without an account) but
is a frequent source of confusion — see
[`architecture-analysis.md`](architecture-analysis.md).
