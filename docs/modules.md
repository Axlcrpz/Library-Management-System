# Module Reference

One sheet per major module. Each follows the same template:

- **Purpose** — what problem it solves in the system
- **User interaction** — how someone uses it (and which roles can)
- **Backend** — what happens server-side (key `handle*` actions)
- **Database** — tables read/written
- **Dependencies** — what it relies on / what relies on it
- **Why it exists** — the business justification

Front-end tab id ↔ template ↔ JS is noted per module. All write actions pass the
role guard + CSRF gate described in [`system-flow.md`](system-flow.md).

---

## 1. Dashboard

| Facet | Detail |
|-------|--------|
| **Purpose** | At-a-glance landing page: key counts, recent activity, and the **announcements** reader. |
| **User interaction** | All roles. Read-only summary cards + announcement list; staff/admin also post announcements here. UI: tab `dashboard` → [`templates/dashboard.php`](../templates/dashboard.php). |
| **Backend** | `book_stats`, `book_category_stats`, `announcements_get`, `announcement_view`, `announcement_mark_read`; staff: `announcements_add`/`_delete`, `announcement_image_upload`. |
| **Database** | reads `books`, `book_borrow_records`; `announcements` (+`announcement_attachments`, `announcement_reads`). |
| **Dependencies** | Pulls aggregates from Inventory & Borrowing; announcements use Quill (rich text, staff only) + the file-serve endpoint for images/attachments. |
| **Why it exists** | A single home screen reduces navigation and surfaces the library-wide signal (overdue, new stock, notices) the moment a user logs in. |

### Announcements sub-system
Rich-text notices with **priority, category, scheduling (`publish_at`/`expire_at`),
pinning/featuring, attachments, and per-user read tracking**
(`announcement_reads`). Staff author via Quill; readers get a sectioned reader and
a read receipt. Images/files are streamed through `announcement_image`/
`announcement_file` (never served from a public directory).

---

## 2. Documents

| Facet | Detail |
|-------|--------|
| **Purpose** | Manage administrative **files/records** (PDFs, memos, reports) — upload, classify, version, borrow, archive, soft-delete. |
| **User interaction** | All roles view/search; staff/admin add/edit/upload. Tab `documents` → [`templates/tables.php`](../templates/tables.php). |
| **Backend** | `get`, `add`, `update`, `delete`, `archive`, `delete_file`, `history` (version timeline), `borrow`/`return` (single-copy), `borrow_history`, `file_serve`. |
| **Database** | `library_documents`, `library_document_versions` (every change snapshots a version), `library_borrowing_records`, `library_borrowers`. |
| **Dependencies** | Uses the secure **file-serve** endpoint + `storage/attachments/`. Shares `library_borrowers` with the legacy borrow flow. Feeds **Archive** and **Trash**. |
| **Why it exists** | The office must retain and track official documents with an audit trail (versions) and controlled lending — distinct from the public book catalog. |

> ⚠️ Documents have their **own single-copy borrow/return** (`borrow`/`return` on
> `library_documents.is_borrowed`), separate from the book circulation in §4. See
> [`architecture-analysis.md`](architecture-analysis.md) §Dual Circulation.

---

## 3. Inventory (Books)

| Facet | Detail |
|-------|--------|
| **Purpose** | The **book catalog** with per-title stock accounting (total / available / damaged / missing) and condition. |
| **User interaction** | All roles browse/search/sort; staff/admin add (3 ways — see §3a), edit, add copies, mark condition, archive, delete. Tab `books` → [`templates/books.php`](../templates/books.php) + [`assets/js/inventory.js`](../assets/js/inventory.js). |
| **Backend** | `books_get`, `books_add`, `books_update`, `book_add_copies`, `book_mark_condition`, `book_archive`, `books_delete`, `book_stats`, `book_category_stats`, `book_reports`, `category_stats`. |
| **Database** | `books` (rich metadata: isbn13/10, cover, description, lcc/ddc, source, metadata_score…), dimension tables `authors`/`book_authors`, `categories`/`book_categories`, `book_external_ids`. |
| **Dependencies** | Source of truth for **Borrowing**, **Reservations**, **Delivery Log** (deliveries add stock). Enriched by **Book Discovery** (§3a) and the cron backfill/relations worker. |
| **Why it exists** | Core to a library: know what titles exist, how many copies are lendable, and their physical condition/location. |

### 3a. Book Discovery (multi-source) — a subsystem inside Inventory

| Facet | Detail |
|-------|--------|
| **Purpose** | Find books from **external catalogs** and add them with rich, authoritative metadata + covers, instead of typing everything by hand. |
| **User interaction** | Staff/admin. A search box queries multiple providers; results show covers, "already in library" tags, and a one-click add. UI: [`assets/js/discovery.js`](../assets/js/discovery.js). |
| **Backend** | `discovery_search` (provider chain **Google Books → Open Library → Library of Congress → Internet Archive**, with `api_cache` + per-provider rate limiting + a metadata **score**), `book_add_from_discovery` (server re-fetches metadata, dedups, inserts/links), `book_similar`, `enrich_drain`, `book_backfill`, `favorite_toggle`, `favorites_get`. |
| **Database** | `books` + dimensions; `api_cache`, `api_rate_buckets`, `book_enrich_queue`, `book_relations`, `book_external_ids`, `favorites`. |
| **Dependencies** | Writes into the same `books` table as manual/ISBN add; cron drains `book_enrich_queue` to build `book_relations` ("similar books"). |
| **Why it exists** | Cataloging by hand is slow and error-prone; pulling clean metadata + covers from authoritative sources dramatically speeds onboarding of stock and improves the catalog's quality. |

---

## 4. Borrowing (Book circulation)

| Facet | Detail |
|-------|--------|
| **Purpose** | The **request → approve → borrow → return** lifecycle for **books** (multi-copy, due dates, fines, damage). |
| **User interaction** | Users submit borrow requests (as themselves); staff/admin review, approve/reject, and process returns. Tab `borrowing` → [`templates/book_borrow.php`](../templates/book_borrow.php). |
| **Backend** | `book_borrow_requests_get` (scopes: mine/pending/active), `book_borrow_request_add`, `book_borrow_approve`, `book_borrow_reject`, `book_borrow_cancel`, `book_borrow_return`, `calculate_fine`. |
| **Database** | `book_borrow_records` (status, due_at, fine_amount, reservation_id…), `book_borrow_items` (per-book qty + returned/damaged/missing), `books` (stock), `borrowing_policies`, `library_borrowers`. |
| **Dependencies** | Decrements **Inventory** stock on approve; respects **Reservations** capacity (`resMaxForRange`); fines use **Settings ▸ borrowing_policies**; emits **admin notifications** + **audit logs**. |
| **Why it exists** | Lending is the library's primary transaction; the request/approval gate gives staff control and the quantity/fine logic keeps stock and accountability correct. |

**Key rule:** stock is only deducted **on approval**, not on request — so pending
requests never starve the shelf, and approval is the integrity checkpoint
(locks rows `FOR UPDATE`, validates stock *and* reservation capacity).

---

## 5. Reservations

| Facet | Detail |
|-------|--------|
| **Purpose** | Let patrons **hold** a book — either join a queue for an unavailable title or book a date range in advance. |
| **User interaction** | Users create/cancel reservations and join waitlists; staff manage the queue and convert holds into borrows. Tab `reservations` → [`templates/reservations.php`](../templates/reservations.php). |
| **Backend** | `get_reservations`, `create_reservation`, `cancel_reservation`, `reservation_calendar`, `waitlist_join`, `waitlist_respond`, `reservation_convert`, `notify_next_in_queue`, `expire_reservations`. |
| **Database** | **Three** overlapping tables: `reservations` (v1 queue), `book_reservations` (v2 date-ranged, quantity-based, ENUM lifecycle), `book_waitlist` (v2 offers). |
| **Dependencies** | Ties into **Inventory** capacity and **Borrowing** (`reservation_convert` → a borrow); cron's `resMaintenanceSweep` expires holds and times out offers. |
| **Why it exists** | High-demand titles need fair allocation; advance booking serves classrooms/institutions that need stock on a specific date. |

> ⚠️ The three reservation tables are a clear consolidation target — see
> [`architecture-analysis.md`](architecture-analysis.md) §Triple Reservations.

---

## 6. Members *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | The **patron registry** — the people/institutions who borrow, with classification (child/teen/individual/school/deped/…). |
| **User interaction** | Admin browses, searches, adds, edits member profiles and sees their borrowing history. Tab `members` → [`templates/borrowers.php`](../templates/borrowers.php). |
| **Backend** | `borrowers_get`, `borrowers_search`, `borrowers_add`, `borrower_profile`, `borrowers_update`. |
| **Database** | `library_borrowers` (extended: `user_id`, `lrn`, `classification`, `email`, `address`, …; unique sparse index on `lrn`). |
| **Dependencies** | Linked to **Borrowing** (every borrow record references a borrower) and optionally to a `users` account via `user_id`; `classification` selects the **borrowing_policy**. |
| **Why it exists** | Lending requires knowing *who* holds an item; classification drives loan limits, fines, and reservation rules. |

---

## 7. Delivery Log *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | Record **incoming book shipments** and reconcile received/damaged/missing quantities into stock. |
| **User interaction** | Admin logs a delivery (date, source, PO/ref number), adds per-book line items, and attaches receiving documents. Tab `delivery-log` → [`templates/delivery_log.php`](../templates/delivery_log.php). |
| **Backend** | `delivery_add`, `delivery_get`, `delivery_update`, `delivery_delete`, `delivery_attach_doc`, `delivery_get_docs`, `delivery_delete_doc`; bulk path `books_bulk_import`. |
| **Database** | `deliveries` (+status/po_number/ref_number/received_by), `delivery_items` (book_id, qty received/damaged/missing), `delivery_documents`, `import_logs`; updates `books` stock. |
| **Dependencies** | Feeds **Inventory** (adds copies); attachments via the secure file-serve endpoint; bulk import writes `import_logs`. |
| **Why it exists** | Acquisition accountability — a paper trail from "shipment arrived" to "copies on the shelf," including discrepancies. |

---

## 8. Archive

| Facet | Detail |
|-------|--------|
| **Purpose** | Keep inactive-but-retained items (documents and books) out of the active lists without deleting them. |
| **User interaction** | View archived items; staff archive/unarchive. Tab `archive` → [`templates/archive.php`](../templates/archive.php). |
| **Backend** | `archive` / `book_archive` (set `is_archived=1`); archived rows are filtered out of normal `get`/`books_get`. |
| **Database** | `library_documents.is_archived`, `books.is_archived`. |
| **Dependencies** | A view/filter over **Documents** and **Inventory** — not a separate store. |
| **Why it exists** | Records management: retain history while keeping working lists clean. Distinct from Trash (which is deletion). |

---

## 9. Trash *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | **Soft-delete** safety net with restore and a TTL before permanent removal. |
| **User interaction** | Admin views deleted items, restores, or permanently deletes (one/all). Tab `trash` → [`templates/trash.php`](../templates/trash.php). |
| **Backend** | `trash`, `restore_deleted`, `permanent_delete`, `permanent_delete_all`, `db_purge_trash`; auto-purge via `purgeExpiredTrash()` on every request + cron. |
| **Database** | `library_documents.is_deleted` + `deleted_at` (TTL anchor). |
| **Dependencies** | Counterpart to **Documents**; the auto-purge runs in the request bootstrap and the cron sweep. |
| **Why it exists** | Prevents accidental data loss while still allowing eventual cleanup and storage reclamation. |

---

## 10. Account Requests *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | Approve or reject **self-registered** accounts before they can log in. |
| **User interaction** | Admin sees pending registrations and approves (assigning a role) or rejects. Tab `account-requests` → [`templates/account_requests.php`](../templates/account_requests.php); action posts to [`account_action.php`](../account_action.php). |
| **Backend** | `account_action.php` (POST + CSRF): `approve` → `status='approved', is_active=1, role=?`; `reject` → `status='rejected', is_active=0`. |
| **Database** | `users` (status/is_active/role). |
| **Dependencies** | Gates **Login** (Stage 1 rejects non-approved users); pairs with self-registration in `login.php`. |
| **Why it exists** | Open self-registration needs a human gate so only legitimate patrons/staff gain access, and roles are assigned deliberately. |

---

## 11. Audit Logs *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | An immutable trail of who did what, when. |
| **User interaction** | Admin browses/filters the log. Tab `audit-logs` → [`templates/audit_logs.php`](../templates/audit_logs.php). |
| **Backend** | `audit_logs_get`; written by `logAudit()` from across the handler (book added, borrow approved, etc.). |
| **Database** | `audit_logs` (user_id, action, module, target_type/id, description, ip, created_at). |
| **Dependencies** | Cross-cutting — most state-mutating handlers call `logAudit()`. |
| **Why it exists** | Accountability and incident investigation in a multi-user, multi-role system. |

---

## 12. Settings *(admin)*

| Facet | Detail |
|-------|--------|
| **Purpose** | The admin control panel: library identity, loan/fine policies, user management, SMS, maintenance mode, data export. |
| **User interaction** | Admin only. Tab `settings` → [`templates/settings.php`](../templates/settings.php). |
| **Backend** | `settings_get`/`settings_save`, `policies_get`/`policies_save`, `users_list`/`_create`/`_update`/`_toggle_status`/`_delete`, `maintenance_toggle`, `db_export_csv`, `db_purge_trash`, `notifications_get`/`_mark_read`. |
| **Database** | `library_settings` (key/value), `borrowing_policies` (per-classification limits/fines), `users`, `admin_notifications`, `sms_queue`. |
| **Dependencies** | Policies feed **Borrowing/Reservations**; SMS settings feed the `sms_queue`; user management overlaps with **Account Requests** (both mutate `users`). |
| **Why it exists** | Centralizes the levers that govern system behavior so they're not hard-coded. |

---

## 13. My Account

| Facet | Detail |
|-------|--------|
| **Purpose** | Self-service profile, avatar, password, notification preferences, activity, and active sessions. |
| **User interaction** | All roles manage their own account. Tab `my-account` → [`templates/user_settings.php`](../templates/user_settings.php). |
| **Backend** | `user_profile_get`/`_update`, `user_avatar`/`avatar_library`/`user_avatar_upload`/`_select`/`_remove`, `user_password_change`, `user_notif_prefs_get`/`_save`, `user_activity_get`, `user_sessions_get`, `user_sessions_revoke_all`. |
| **Database** | `users` (profile/avatar), `user_preferences`, `user_notification_prefs`, `user_sessions`, `audit_logs` (activity). |
| **Dependencies** | Avatars served via the file-serve endpoint (photo > system avatar > initials); avatar uploads throttled (30-day cooldown). Sessions list is *separate* from logout. |
| **Why it exists** | Users need to manage their own identity/security without admin involvement. |

---

## Cross-cutting subsystems (not nav tabs)

| Subsystem | Actions | Tables | Purpose |
|-----------|---------|--------|---------|
| **Authentication & accounts** | login/register (`login.php`), `account_action.php`, `logout.php` | `users`, `login_attempts` | Identity, registration gate, brute-force lockout, session teardown |
| **Admin notifications** | `notifications_get`, `notifications_mark_read` | `admin_notifications` | Push-style deep-linked alerts (borrow/delivery/reservation events) |
| **SMS queue** | enqueued by handlers; drained externally | `sms_queue`, `library_settings` (sms_*) | Outbound borrow/overdue texts (templated messages) |
| **Background worker** | `cron.php` (CLI) | many | Reservation sweep, trash purge, discovery enrich/backfill, login prune |
| **File serving** | `file_serve`, `announcement_file/image`, `user_avatar` | (filesystem `storage/`) | Stream uploads through PHP with auth — never expose `storage/` directly |
