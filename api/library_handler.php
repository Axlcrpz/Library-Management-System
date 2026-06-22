<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/Fines.php';
require_once __DIR__ . '/../lib/DueDate.php';

// Flip to true only for local debugging. In production, internal error details
// (DB messages, paths) are never sent to the client — they'd leak system internals.
const APP_DEBUG = false;
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/** Client-safe error detail: full message in debug, generic text in production. */
function dbgMsg(Throwable $e): string {
    $real = $e->getMessage();
    if (APP_DEBUG) return $real;
    error_log('[library_sys] ' . $real);   // keep the real cause in the server log
    return 'an unexpected error occurred. Please try again.';
}

const LIBRARY_UPLOAD_DIR  = __DIR__ . '/../storage/attachments/';
const LIBRARY_UPLOAD_PATH = 'storage/attachments/';
const DELIVERY_UPLOAD_DIR  = __DIR__ . '/../storage/attachments/deliveries/';
const DELIVERY_UPLOAD_PATH = 'storage/attachments/deliveries/';
const ANNOUNCE_UPLOAD_DIR  = __DIR__ . '/../storage/attachments/announcements/';
const ANNOUNCE_UPLOAD_PATH = 'storage/attachments/announcements/';
const AVATAR_UPLOAD_DIR    = __DIR__ . '/../storage/attachments/avatars/';   // uploaded photos (served via endpoint)
const AVATAR_LIB_DIR       = __DIR__ . '/../assets/img/avatars/';            // curated static library
const AVATAR_LIB_PATH      = 'assets/img/avatars/';
const AVATAR_CATEGORIES    = ['neutral', 'professional', 'teen', 'child'];
const AVATAR_UPLOAD_COOLDOWN_DAYS = 30;
const DISCOVERY_RATE_LIMIT = 40;   // external API calls per provider per minute
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20 MB
const ALLOWED_MIME_TYPES = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel',
    'text/csv', 'text/plain',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.ms-powerpoint',
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
];
const ALLOWED_EXTENSIONS = ['pdf','xlsx','xls','csv','txt','docx','doc','pptx','ppt','jpg','jpeg','png','gif','webp','bmp'];

function sendJson(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function sendSuccess(array $data = [], string $message = 'Success', array $extra = []): void {
    // $extra adds top-level envelope keys (e.g. ['meta' => ...]) without overriding the core ones.
    sendJson(['success' => true, 'message' => $message, 'data' => $data] + $extra);
}

function sendError(string $message, int $status = 400): void {
    sendJson(['success' => false, 'message' => $message], $status);
}

function apiRequireLogin(): void {
    if (!is_logged_in()) {
        sendError('Authentication required.', 401);
    }
}

function apiRequireAdmin(): void {
    apiRequireLogin();
    if (($_SESSION['role'] ?? 'user') !== 'admin') {
        sendError('Access denied: admin privileges required.', 403);
    }
}

function apiRequireLibraryAccess(): void {
    apiRequireLogin();
}

function apiRequireValidCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    if (!function_exists('validateCSRFToken') || !validateCSRFToken($token)) {
        sendError('Invalid CSRF token.', 403);
    }
}

function cleanValue(?string $value): string {
    return trim((string) $value);
}

function parseDocumentDate(array $source): array {
    if (!empty($source['date'])) {
        $time = strtotime(cleanValue($source['date']));
        if ($time !== false) {
            return [(int) date('Y', $time), (int) date('n', $time), (int) date('j', $time)];
        }
    }

    $year = isset($source['year']) && is_numeric($source['year']) ? (int) $source['year'] : null;
    $month = isset($source['month']) && is_numeric($source['month']) ? (int) $source['month'] : null;
    $day = isset($source['day']) && is_numeric($source['day']) ? (int) $source['day'] : null;

    return [$year, $month, $day];
}

function currentUserId(): ?int {
    $user = getCurrentUser();
    return isset($user['id']) ? (int) $user['id'] : null;
}

function documentStatus(array $document): string {
    if (!empty($document['is_archived'])) {
        return 'archived';
    }
    return !empty($document['is_borrowed']) ? 'borrowed' : 'available';
}

function ensureLibrarySchema(PDO $pdo): void {
    // Short-circuit: skip all DDL if schema is already at current version
    try {
        $v = $pdo->query("SELECT setting_value FROM library_settings WHERE setting_key = 'schema_version'")->fetchColumn();
        if ($v === '13') return;
    } catch (Throwable) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        document_type VARCHAR(100) NULL,
        section VARCHAR(100) NULL,
        year INT NULL,
        month INT NULL,
        day INT NULL,
        notes TEXT NULL,
        upload_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        file_path VARCHAR(500) NULL,
        file_name VARCHAR(255) NULL,
        file_size BIGINT NULL,
        file_type VARCHAR(50) NULL,
        is_archived TINYINT(1) NOT NULL DEFAULT 0,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        deleted_at DATETIME NULL,
        is_borrowed TINYINT(1) NOT NULL DEFAULT 0,
        updated_at DATETIME NULL,
        INDEX idx_library_documents_status (is_deleted, is_archived, is_borrowed),
        INDEX idx_library_documents_date (year, month, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_borrowers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        contact VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_library_borrower_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_borrowing_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        borrower_id INT NOT NULL,
        borrowed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expected_return_date DATE NULL,
        returned_at DATETIME NULL,
        notes TEXT NULL,
        return_notes TEXT NULL,
        created_by INT NULL,
        returned_by INT NULL,
        INDEX idx_library_borrow_document (document_id),
        INDEX idx_library_borrower (borrower_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_document_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_id INT NOT NULL,
        version_number INT NOT NULL,
        title VARCHAR(255) NULL,
        document_type VARCHAR(100) NULL,
        section VARCHAR(100) NULL,
        year INT NULL,
        month INT NULL,
        day INT NULL,
        notes TEXT NULL,
        file_path VARCHAR(500) NULL,
        file_name VARCHAR(255) NULL,
        file_size BIGINT NULL,
        file_type VARCHAR(50) NULL,
        change_type VARCHAR(50) NOT NULL DEFAULT 'update',
        changed_by INT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_library_versions_document (document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS books (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        author VARCHAR(255) NULL,
        subject VARCHAR(100) NULL,
        category VARCHAR(100) NULL,
        isbn VARCHAR(50) NULL,
        grade_level VARCHAR(50) NULL,
        location_label VARCHAR(255) NULL,
        quantity_total INT NOT NULL DEFAULT 0,
        quantity_available INT NOT NULL DEFAULT 0,
        quantity_damaged INT NOT NULL DEFAULT 0,
        quantity_missing INT NOT NULL DEFAULT 0,
        condition_status VARCHAR(20) NOT NULL DEFAULT 'good',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_books_subject (subject),
        INDEX idx_books_grade (grade_level),
        INDEX idx_books_title (title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_date DATE NOT NULL,
        source VARCHAR(255) NOT NULL,
        remarks TEXT NULL,
        logged_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_deliveries_date (delivery_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id INT NOT NULL,
        book_id INT NOT NULL,
        quantity_received INT NOT NULL DEFAULT 0,
        quantity_damaged INT NOT NULL DEFAULT 0,
        quantity_missing INT NOT NULL DEFAULT 0,
        notes VARCHAR(255) NULL,
        INDEX idx_delivery_items_delivery (delivery_id),
        INDEX idx_delivery_items_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        posted_by INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_announcements_active (is_active),
        INDEX idx_announcements_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_borrow_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        borrower_id INT NOT NULL,
        requested_by INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        borrow_type VARCHAR(20) NULL,
        time_allowed_minutes INT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        borrowed_at DATETIME NULL,
        due_at DATETIME NULL,
        returned_at DATETIME NULL,
        returned_by INT NULL,
        return_notes TEXT NULL,
        fine_amount DECIMAL(10,2) NULL,
        INDEX idx_book_borrow_status (status),
        INDEX idx_book_borrow_due (due_at),
        INDEX idx_book_borrow_borrower (borrower_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_borrow_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        borrow_id INT NOT NULL,
        book_id INT NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        returned_quantity INT NOT NULL DEFAULT 0,
        returned_damaged INT NOT NULL DEFAULT 0,
        returned_missing INT NOT NULL DEFAULT 0,
        INDEX idx_book_borrow_items_borrow (borrow_id),
        INDEX idx_book_borrow_items_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        book_id INT NOT NULL,
        queue_position INT NOT NULL DEFAULT 1,
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        notified_at DATETIME NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reservations_book (book_id),
        INDEX idx_reservations_user (user_id),
        INDEX idx_reservations_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS library_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL,
        setting_value VARCHAR(255) NOT NULL DEFAULT '',
        UNIQUE KEY uq_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT IGNORE INTO library_settings (setting_key, setting_value) VALUES ('reservation_expiry_days', '3')");

    $borrowerColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_borrowers') as $row) {
        $borrowerColumns[$row['Field']] = true;
    }
    if (!isset($borrowerColumns['contact'])) {
        $pdo->exec('ALTER TABLE library_borrowers ADD COLUMN contact VARCHAR(255) NULL AFTER name');
    }
    if (!isset($borrowerColumns['created_at'])) {
        $pdo->exec('ALTER TABLE library_borrowers ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    $borrowRecordColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_borrowing_records') as $row) {
        $borrowRecordColumns[$row['Field']] = true;
    }
    $borrowRecordRequired = [
        'expected_return_date' => 'DATE NULL',
        'returned_at' => 'DATETIME NULL',
        'notes' => 'TEXT NULL',
        'return_notes' => 'TEXT NULL',
        'created_by' => 'INT NULL',
        'returned_by' => 'INT NULL',
    ];
    foreach ($borrowRecordRequired as $name => $definition) {
        if (!isset($borrowRecordColumns[$name])) {
            $pdo->exec("ALTER TABLE library_borrowing_records ADD COLUMN {$name} {$definition}");
        }
    }


    $versionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_document_versions') as $row) {
        $versionColumns[$row['Field']] = true;
    }
    $versionRequired = [
        'version_number' => 'INT NOT NULL DEFAULT 1',
        'title' => 'VARCHAR(255) NULL',
        'document_type' => 'VARCHAR(100) NULL',
        'section' => 'VARCHAR(100) NULL',
        'year' => 'INT NULL',
        'month' => 'INT NULL',
        'day' => 'INT NULL',
        'notes' => 'TEXT NULL',
        'file_name' => 'VARCHAR(255) NULL',
        'file_size' => 'BIGINT NULL',
        'file_type' => 'VARCHAR(50) NULL',
        'change_type' => "VARCHAR(50) NOT NULL DEFAULT 'update'",
        'changed_by' => 'INT NULL',
        'changed_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($versionRequired as $name => $definition) {
        if (!isset($versionColumns[$name])) {
            $pdo->exec("ALTER TABLE library_document_versions ADD COLUMN {$name} {$definition}");
        }
    }
    if (isset($versionColumns['version_no']) && !isset($versionColumns['version_number'])) {
        $pdo->exec('ALTER TABLE library_document_versions CHANGE version_no version_number INT NOT NULL DEFAULT 1');
    }
    try {
        $pdo->exec('ALTER TABLE library_document_versions MODIFY file_path VARCHAR(500) NULL');
    } catch (Throwable $e) {
        // Ignore if the DB does not allow modification because of an existing constraint.
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_documents') as $row) {
        $columns[$row['Field']] = true;
    }

    $required = [
        'file_path' => 'VARCHAR(500) NULL',
        'file_name' => 'VARCHAR(255) NULL',
        'file_size' => 'BIGINT NULL',
        'file_type' => 'VARCHAR(50) NULL',
        'is_archived' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_deleted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'deleted_at' => 'DATETIME NULL',
        'is_borrowed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'updated_at' => 'DATETIME NULL',
    ];

    foreach ($required as $name => $definition) {
        if (!isset($columns[$name])) {
            $pdo->exec("ALTER TABLE library_documents ADD COLUMN {$name} {$definition}");
        }
    }
    $pdo->exec('UPDATE library_documents SET deleted_at = COALESCE(updated_at, NOW()) WHERE COALESCE(is_deleted, 0) = 1 AND deleted_at IS NULL');

    // ── Extended books columns ──────────────────────────────────────────────
    $booksColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM books') as $r) $booksColumns[$r['Field']] = true;
    foreach ([
        'cover_url'    => 'VARCHAR(500) NULL',
        'publisher'    => 'VARCHAR(255) NULL',
        'description'  => 'TEXT NULL',
        'publish_year' => 'INT NULL',
        'is_favorite'  => 'TINYINT(1) NOT NULL DEFAULT 0',
        'added_by'     => 'INT NULL',
        'notes'        => 'TEXT NULL',
    ] as $col => $def) {
        if (!isset($booksColumns[$col])) {
            try { $pdo->exec("ALTER TABLE books ADD COLUMN `$col` $def"); } catch (Throwable) {}
        }
    }

    // ── Extended library_borrowers columns + unique LRN index ───────────────
    $bCol = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_borrowers') as $r) $bCol[$r['Field']] = true;
    // Unique sparse index on LRN: allows multiple NULLs, prevents duplicate non-null LRNs
    try { $pdo->exec('ALTER TABLE library_borrowers ADD UNIQUE INDEX uq_lrn (lrn)'); } catch (Throwable) {}
    foreach ([
        'user_id'        => 'INT NULL',
        'borrower_type'  => "VARCHAR(20) NOT NULL DEFAULT 'individual'",
        'lrn'            => 'VARCHAR(50) NULL',
        'contact_person' => 'VARCHAR(255) NULL',
        'classification' => "VARCHAR(50) NOT NULL DEFAULT 'individual'",
        'date_of_birth'  => 'DATE NULL',
        'address'        => 'TEXT NULL',
        'email'          => 'VARCHAR(255) NULL',
        'updated_at'     => 'DATETIME NULL',
    ] as $col => $def) {
        if (!isset($bCol[$col])) {
            try { $pdo->exec("ALTER TABLE library_borrowers ADD COLUMN `$col` $def"); } catch (Throwable) {}
        }
    }

    // ── audit_logs ──────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(100) NOT NULL,
        module VARCHAR(50) NOT NULL DEFAULT 'library',
        target_type VARCHAR(50) NULL,
        target_id INT NULL,
        description TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_user (user_id),
        INDEX idx_audit_module (module),
        INDEX idx_audit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── admin_notifications ─────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL DEFAULT 'info',
        title VARCHAR(255) NOT NULL,
        body TEXT NULL,
        module VARCHAR(50) NULL,
        target_id INT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notif_read (is_read),
        INDEX idx_notif_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── sms_queue ───────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(30) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        related_type VARCHAR(50) NULL,
        related_id INT NULL,
        sent_at DATETIME NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_sms_status (status),
        INDEX idx_sms_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Expanded library_settings defaults ──────────────────────────────────
    $settingsDefaults = [
        'fine_per_day'           => '5',
        'max_borrow_days'        => '14',
        'max_books_per_borrow'   => '5',
        'library_name'           => 'SDO Quirino Library',
        'library_address'        => '',
        'library_contact'        => '',
        'sms_enabled'            => '0',
        'sms_api_key'            => '',
        'sms_sender_name'        => 'LIBRARY',
        'sms_overdue_message'    => 'Dear {name}, your borrowed book "{book}" is overdue. Please return it. Fine: PHP {fine}.',
        'sms_borrow_message'     => 'Dear {name}, you borrowed "{book}". Due: {due}. Thank you.',
        'auto_fine_enabled'      => '1',
        'reservation_expiry_days'=> '3',
        'notify_borrow'          => '1',
        'notify_overdue'         => '1',
    ];
    $insertSetting = $pdo->prepare(
        "INSERT IGNORE INTO library_settings (setting_key, setting_value) VALUES (?, ?)"
    );
    foreach ($settingsDefaults as $key => $val) {
        $insertSetting->execute([$key, $val]);
    }

    // ── borrowing_policies ──────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS borrowing_policies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        classification VARCHAR(30) NOT NULL,
        max_borrow_days INT NOT NULL DEFAULT 14,
        max_books_per_borrow INT NOT NULL DEFAULT 5,
        fine_per_day DECIMAL(8,2) NOT NULL DEFAULT 5.00,
        reservation_expiry_days INT NOT NULL DEFAULT 3,
        grace_period_days INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_bp_cls (classification)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        ['child',               7,  3,  5.00, 2, 0],
        ['teen',                14, 5,  5.00, 3, 0],
        ['individual',          14, 5,  5.00, 3, 0],
        ['deped',               30, 20, 0.00, 5, 2],
        ['school',              30, 20,10.00, 5, 1],
        ['professional',        21, 10, 5.00, 3, 0],
        ['private_institution', 30, 15,10.00, 5, 1],
    ] as [$cls, $days, $books, $fine, $exp, $grace]) {
        $pdo->prepare("INSERT IGNORE INTO borrowing_policies (classification,max_borrow_days,max_books_per_borrow,fine_per_day,reservation_expiry_days,grace_period_days) VALUES (?,?,?,?,?,?)")
            ->execute([$cls, $days, $books, $fine, $exp, $grace]);
    }

    // ── user_notification_prefs ────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_notification_prefs (
        user_id INT NOT NULL PRIMARY KEY,
        notify_borrow_sms TINYINT(1) NOT NULL DEFAULT 1,
        notify_overdue_sms TINYINT(1) NOT NULL DEFAULT 1,
        notify_due_reminder TINYINT(1) NOT NULL DEFAULT 1,
        notify_announcements TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── user_preferences ───────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_preferences (
        user_id INT NOT NULL PRIMARY KEY,
        theme VARCHAR(20) NOT NULL DEFAULT 'light',
        items_per_page INT NOT NULL DEFAULT 25
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── user_sessions ──────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        last_active DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usr_sess_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── extra settings defaults ────────────────────────────────────────────────
    foreach (['maintenance_mode'=>'0','maintenance_msg'=>'System is under maintenance. Please check back later.','library_email'=>''] as $k => $v) {
        $insertSetting->execute([$k, $v]);
    }

    // ── users column migrations ────────────────────────────────────────────────
    $uCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $r) $uCols[$r['Field']] = true;
    if (!isset($uCols['contact']))        { try { $pdo->exec('ALTER TABLE users ADD COLUMN contact VARCHAR(255) NULL AFTER full_name'); }                              catch (Throwable) {} }
    if (!isset($uCols['created_at']))     { try { $pdo->exec('ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'); }                catch (Throwable) {} }
    if (!isset($uCols['classification'])) { try { $pdo->exec("ALTER TABLE users ADD COLUMN classification VARCHAR(50) NOT NULL DEFAULT 'individual'"); }              catch (Throwable) {} }
    if (!isset($uCols['profile_image']))  { try { $pdo->exec('ALTER TABLE users ADD COLUMN profile_image VARCHAR(500) NULL'); }                                       catch (Throwable) {} }
    if (!isset($uCols['status']))         { try { $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'approved'"); }                        catch (Throwable) {} }
    if (!isset($uCols['is_active']))      { try { $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1'); }                               catch (Throwable) {} }
    if (!isset($uCols['avatar_id']))                { try { $pdo->exec('ALTER TABLE users ADD COLUMN avatar_id VARCHAR(120) NULL'); }                          catch (Throwable) {} }
    if (!isset($uCols['profile_image_updated_at'])) { try { $pdo->exec('ALTER TABLE users ADD COLUMN profile_image_updated_at DATETIME NULL'); }               catch (Throwable) {} }

    // ── delivery_documents ─────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size BIGINT NULL,
        file_type VARCHAR(50) NULL,
        label VARCHAR(255) NULL,
        uploaded_by INT NULL,
        uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dd_delivery (delivery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── deliveries column migrations ───────────────────────────────────────────
    $dCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM deliveries') as $r) $dCols[$r['Field']] = true;
    if (!isset($dCols['status']))      { try { $pdo->exec("ALTER TABLE deliveries ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'received' AFTER remarks"); }       catch (Throwable) {} }
    if (!isset($dCols['po_number']))   { try { $pdo->exec('ALTER TABLE deliveries ADD COLUMN po_number VARCHAR(100) NULL AFTER status'); }                          catch (Throwable) {} }
    if (!isset($dCols['ref_number']))  { try { $pdo->exec('ALTER TABLE deliveries ADD COLUMN ref_number VARCHAR(100) NULL AFTER po_number'); }                      catch (Throwable) {} }
    if (!isset($dCols['received_by'])) { try { $pdo->exec('ALTER TABLE deliveries ADD COLUMN received_by VARCHAR(255) NULL AFTER ref_number'); }                   catch (Throwable) {} }

    // ── import_logs ────────────────────────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        imported_by INT NULL,
        source_file VARCHAR(255) NULL,
        total_rows INT NOT NULL DEFAULT 0,
        imported_count INT NOT NULL DEFAULT 0,
        skipped_count INT NOT NULL DEFAULT 0,
        duplicate_count INT NOT NULL DEFAULT 0,
        error_count INT NOT NULL DEFAULT 0,
        summary JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import_user (imported_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Reservation v2: date-ranged, quantity-based bookings ───────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS book_reservations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        user_id INT NOT NULL,
        quantity INT NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        status ENUM('pending','confirmed','ready','fulfilled','completed','cancelled','expired','no_show') NOT NULL DEFAULT 'pending',
        borrow_id INT NULL,
        pickup_deadline DATETIME NULL,
        approved_by INT NULL,
        approved_at DATETIME NULL,
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        INDEX idx_res_overlap (book_id, status, start_date, end_date),
        INDEX idx_res_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_waitlist (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        user_id INT NOT NULL,
        quantity INT NOT NULL,
        preferred_start DATE NULL,
        preferred_end DATE NULL,
        allow_partial TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('waiting','offered','converted','declined','expired','cancelled') NOT NULL DEFAULT 'waiting',
        offer_qty INT NULL,
        offer_expires_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_wl_queue (book_id, status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // borrow ↔ reservation link + requested date window
    $brCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM book_borrow_records') as $r) $brCols[$r['Field']] = true;
    if (!isset($brCols['reservation_id'])) {
        try { $pdo->exec('ALTER TABLE book_borrow_records ADD COLUMN reservation_id INT NULL'); } catch (Throwable) {}
    }
    if (!isset($brCols['requested_start'])) {
        try { $pdo->exec('ALTER TABLE book_borrow_records ADD COLUMN requested_start DATE NULL'); } catch (Throwable) {}
    }
    if (!isset($brCols['requested_due'])) {
        try { $pdo->exec('ALTER TABLE book_borrow_records ADD COLUMN requested_due DATE NULL'); } catch (Throwable) {}
    }

    // One-time migration: legacy queue rows keep their place (by original timestamp)
    $migrated = $pdo->query("SELECT setting_value FROM library_settings WHERE setting_key = 'res_v2_migrated'")->fetchColumn();
    if (!$migrated) {
        try {
            foreach ($pdo->query("SELECT * FROM reservations WHERE status IN ('waiting','ready')") as $old) {
                $pdo->prepare("INSERT INTO book_waitlist (book_id, user_id, quantity, allow_partial, status, created_at)
                               VALUES (?,?,1,1,'waiting',?)")
                    ->execute([(int)$old['book_id'], (int)$old['user_id'], $old['created_at'] ?: date('Y-m-d H:i:s')]);
            }
        } catch (Throwable) {}
        $insertSetting->execute(['res_v2_migrated', '1']);
    }

    // ── Announcements v2: priority, scheduling/expiry, attachments, read tracking ──
    $annCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM announcements') as $r) $annCols[$r['Field']] = true;
    foreach ([
        'priority'    => "VARCHAR(20) NOT NULL DEFAULT 'normal'",
        'expire_at'   => 'DATETIME NULL',
        'updated_at'  => 'DATETIME NULL',
        'category'    => "VARCHAR(30) NOT NULL DEFAULT 'general'",
        'body_format' => "VARCHAR(10) NOT NULL DEFAULT 'text'",
        'is_pinned'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'is_featured' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'publish_at'  => 'DATETIME NULL',
    ] as $col => $def) {
        if (!isset($annCols[$col])) {
            try { $pdo->exec("ALTER TABLE announcements ADD COLUMN `$col` $def"); } catch (Throwable) {}
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        announcement_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size BIGINT NULL,
        file_type VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ann_att_announcement (announcement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        announcement_id INT NOT NULL,
        user_id INT NOT NULL,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (announcement_id, user_id),
        INDEX idx_ann_reads_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Book Discovery v1: enriched metadata on books + supporting tables ────────
    $bkCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM books') as $r) $bkCols[$r['Field']] = true;
    foreach ([
        'isbn13'            => 'VARCHAR(13) NULL',
        'isbn10'            => 'VARCHAR(10) NULL',
        'cover_url'         => 'VARCHAR(500) NULL',
        'cover_cached_path' => 'VARCHAR(500) NULL',
        'description'       => 'TEXT NULL',
        'page_count'        => 'INT NULL',
        'published_year'    => 'SMALLINT NULL',
        'publisher'         => 'VARCHAR(255) NULL',
        'lang'              => 'VARCHAR(5) NULL',
        'source'            => 'VARCHAR(20) NULL',
        'metadata_score'    => 'TINYINT NULL',
        'is_archived'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'subtitle'          => 'VARCHAR(500) NULL',
        'series'            => 'VARCHAR(255) NULL',
        'edition'           => 'VARCHAR(100) NULL',
        'book_format'       => 'VARCHAR(50) NULL',
        'reading_level'     => 'VARCHAR(50) NULL',
        'lcc'               => 'VARCHAR(50) NULL',
        'ddc'               => 'VARCHAR(50) NULL',
    ] as $col => $def) {
        if (!isset($bkCols[$col])) {
            try { $pdo->exec("ALTER TABLE books ADD COLUMN `$col` $def"); } catch (Throwable) {}
        }
    }
    try { $pdo->exec('ALTER TABLE books ADD INDEX idx_books_archived (is_archived)'); } catch (Throwable) {}
    try { $pdo->exec('ALTER TABLE books ADD UNIQUE INDEX uq_books_isbn13 (isbn13)'); } catch (Throwable) {}
    try { $pdo->exec('ALTER TABLE books ADD UNIQUE INDEX uq_books_isbn10 (isbn10)'); } catch (Throwable) {}
    try { $pdo->exec('ALTER TABLE books ADD FULLTEXT INDEX ft_books (title, author, description)'); } catch (Throwable) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS authors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        name_norm VARCHAR(255) NOT NULL,
        UNIQUE KEY uq_author_norm (name_norm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_authors (
        book_id INT NOT NULL, author_id INT NOT NULL, ord TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (book_id, author_id), INDEX idx_ba_author (author_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL,
        UNIQUE KEY uq_cat_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_categories (
        book_id INT NOT NULL, category_id INT NOT NULL,
        PRIMARY KEY (book_id, category_id), INDEX idx_bc_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_external_ids (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        source VARCHAR(20) NOT NULL,
        external_id VARCHAR(120) NOT NULL,
        raw_json JSON NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_src_ext (source, external_id),
        INDEX idx_bei_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_relations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        book_id INT NOT NULL,
        relation_type ENUM('same_author','same_category','similar_title','alt_edition','recommended') NOT NULL,
        related_book_id INT NULL,
        related_isbn13 VARCHAR(13) NULL,
        score DECIMAL(5,4) NOT NULL DEFAULT 0,
        title VARCHAR(255) NULL, author VARCHAR(255) NULL, cover_url VARCHAR(500) NULL, source VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rel (book_id, relation_type, related_isbn13),
        INDEX idx_rel_anchor (book_id, relation_type), INDEX idx_rel_target (related_book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS favorites (
        user_id INT NOT NULL,
        isbn13 VARCHAR(13) NOT NULL,
        book_id INT NULL,
        title VARCHAR(255) NULL, author VARCHAR(255) NULL, cover_url VARCHAR(500) NULL, source VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, isbn13), INDEX idx_fav_book (book_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_cache (
        cache_key CHAR(64) PRIMARY KEY,
        provider VARCHAR(20) NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        http_status SMALLINT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        INDEX idx_cache_exp (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_buckets (
        provider VARCHAR(20) NOT NULL, window_start DATETIME NOT NULL, cnt INT NOT NULL DEFAULT 0,
        PRIMARY KEY (provider, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS book_enrich_queue (
        book_id INT PRIMARY KEY,
        status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
        attempts TINYINT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Mark schema version so subsequent requests skip this block
    try {
        $pdo->exec("INSERT INTO library_settings (setting_key, setting_value) VALUES ('schema_version', '13')
                    ON DUPLICATE KEY UPDATE setting_value = '13'");
    } catch (Throwable) {}
}

function fetchDocument(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM library_documents WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purgeExpiredTrash(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SELECT id, file_path FROM library_documents WHERE COALESCE(is_deleted, 0) = 1 AND deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired as $doc) {
            permanentlyDeleteDocument($pdo, (int) $doc['id']);
        }
    } catch (Throwable $e) {
        // Keep normal requests working if an older database cannot evaluate the purge yet.
    }
}

function permanentlyDeleteDocument(PDO $pdo, int $id, bool $unlinkFile = true): void {
    $stmt = $pdo->prepare('SELECT file_path FROM library_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($unlinkFile && !empty($doc['file_path'])) {
        @unlink(__DIR__ . '/../' . $doc['file_path']);
    }

    $pdo->prepare('DELETE FROM library_document_versions WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM library_borrowing_records WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM library_documents WHERE id = ?')->execute([$id]);
}

function nextVersionNo(PDO $pdo, int $documentId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM library_document_versions WHERE document_id = ?');
    $stmt->execute([$documentId]);
    return (int) $stmt->fetchColumn();
}

function saveVersion(PDO $pdo, int $documentId, string $changeType): void {
    $doc = fetchDocument($pdo, $documentId);
    if (!$doc) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO library_document_versions
         (document_id, version_number, title, document_type, section, year, month, day, notes, file_path, file_name, file_size, file_type, change_type, changed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $documentId,
        nextVersionNo($pdo, $documentId),
        $doc['title'] ?? null,
        $doc['document_type'] ?? null,
        $doc['section'] ?? null,
        $doc['year'] ?? null,
        $doc['month'] ?? null,
        $doc['day'] ?? null,
        $doc['notes'] ?? null,
        $doc['file_path'] ?? '',
        $doc['file_name'] ?? null,
        $doc['file_size'] ?? null,
        $doc['file_type'] ?? null,
        $changeType,
        currentUserId(),
    ]);
}

function activeBorrow(PDO $pdo, int $documentId): array {
    $stmt = $pdo->prepare(
        'SELECT br.*, b.name AS borrower_name, b.contact AS borrower_contact
         FROM library_borrowing_records br
         INNER JOIN library_borrowers b ON b.id = br.borrower_id
         WHERE br.document_id = ? AND br.returned_at IS NULL
         ORDER BY br.borrowed_at DESC
         LIMIT 1'
    );
    $stmt->execute([$documentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function attachBorrowData(PDO $pdo, array &$doc): void {
    $borrow = activeBorrow($pdo, (int) $doc['id']);
    $doc['archived'] = !empty($doc['is_archived']);
    $doc['status'] = documentStatus($doc);
    $doc['borrowed_by'] = $borrow['borrower_name'] ?? null;
    $doc['borrowed_at'] = $borrow['borrowed_at'] ?? null;
    $doc['expected_return_date'] = $borrow['expected_return_date'] ?? null;
    $doc['borrow_notes'] = $borrow['notes'] ?? null;
    $doc['upload_year'] = !empty($doc['upload_date']) ? (int) date('Y', strtotime($doc['upload_date'])) : null;
}


function isAllowedUploadFile(string $tmpFile, string $originalName): bool {
    if (!is_uploaded_file($tmpFile)) return false;
    if (@filesize($tmpFile) > MAX_UPLOAD_BYTES) return false;

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) return false;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? (string) finfo_file($finfo, $tmpFile) : '';
    if ($finfo) finfo_close($finfo);

    // CSV files often report as text/plain — accept if extension matches
    if (in_array($ext, ['csv', 'txt'], true) && $mime === 'text/plain') return true;

    return in_array($mime, ALLOWED_MIME_TYPES, true);
}

function storeUploadedFile(int $documentId, string $uploadDir = LIBRARY_UPLOAD_DIR, string $uploadPath = LIBRARY_UPLOAD_PATH): array {
    global $pdo;
    $stored = [];

    if (empty($_FILES['files']['name'][0]) && empty($_FILES['file']['name'])) {
        return $stored;
    }

    // Normalise single-file input (name="file") to multi-file structure
    $filesArr = $_FILES['files'] ?? null;
    if (!$filesArr && !empty($_FILES['file']['name'])) {
        $filesArr = [
            'name'     => [$_FILES['file']['name']],
            'tmp_name' => [$_FILES['file']['tmp_name']],
            'error'    => [$_FILES['file']['error']],
        ];
    }
    if (!$filesArr) return $stored;

    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $stmt = $pdo->prepare(
        'UPDATE library_documents SET file_path=?,file_name=?,file_size=?,file_type=?,updated_at=NOW() WHERE id=?'
    );

    foreach ($filesArr['tmp_name'] as $i => $tmpName) {
        $errCode = $filesArr['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('Uploaded file exceeds the allowed size limit (20 MB).');
        }
        if ($errCode !== UPLOAD_ERR_OK) continue;

        $originalName = basename($filesArr['name'][$i]);
        if (!isAllowedUploadFile($tmpName, $originalName)) {
            throw new RuntimeException("File type not allowed: {$originalName}. Supported: PDF, Excel, CSV, Word, Images.");
        }

        $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safe    = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $newName = 'lib_' . uniqid('', true) . '_' . $safe;

        if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
            $size = filesize($uploadDir . $newName);
            $stmt->execute([$uploadPath . $newName, $originalName, $size, $ext, $documentId]);
            saveVersion($pdo, $documentId, 'file_upload');
            $stored[] = ['path' => $uploadPath . $newName, 'name' => $originalName, 'size' => $size, 'type' => $ext];
        }
    }
    return $stored;
}


function storeDeliveryDocument(int $deliveryId): void {
    global $pdo;

    if (!is_dir(DELIVERY_UPLOAD_DIR)) mkdir(DELIVERY_UPLOAD_DIR, 0755, true);

    $filesArr = $_FILES['files'] ?? null;
    if (!$filesArr && !empty($_FILES['file']['name'])) {
        $filesArr = [
            'name'     => [$_FILES['file']['name']],
            'tmp_name' => [$_FILES['file']['tmp_name']],
            'error'    => [$_FILES['file']['error']],
        ];
    }
    if (!$filesArr || empty($filesArr['name'][0])) return;

    $stmt = $pdo->prepare(
        'INSERT INTO delivery_documents (delivery_id,file_path,file_name,file_size,file_type,uploaded_by,uploaded_at)
         VALUES (?,?,?,?,?,?,NOW())'
    );

    foreach ($filesArr['tmp_name'] as $i => $tmpName) {
        $errCode = $filesArr['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($errCode !== UPLOAD_ERR_OK) continue;

        $originalName = basename($filesArr['name'][$i]);
        if (!isAllowedUploadFile($tmpName, $originalName)) {
            throw new RuntimeException("File type not allowed: {$originalName}.");
        }

        $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safe    = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $newName = 'dlv_' . $deliveryId . '_' . uniqid('', true) . '_' . $safe;

        if (move_uploaded_file($tmpName, DELIVERY_UPLOAD_DIR . $newName)) {
            $stmt->execute([
                $deliveryId,
                DELIVERY_UPLOAD_PATH . $newName,
                $originalName,
                filesize(DELIVERY_UPLOAD_DIR . $newName),
                $ext,
                currentUserId(),
            ]);
        }
    }
}

// When required by cron.php (CLI context), stop here — functions above are available
// but the HTTP bootstrap (session check, action router) must not run.
if (PHP_SAPI === 'cli') { return; }

apiRequireLogin();
ensureLibrarySchema($pdo);
// Trash purge is a maintenance job that cron.php runs reliably; sampling it here keeps
// the safety net without paying a cleanup query on every single request under load.
if (random_int(1, 50) === 1) { purgeExpiredTrash($pdo); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action === '') {
    // JSON-body requests put the action inside the payload (php://input is re-readable)
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonBody) && isset($jsonBody['action'])) {
        $action = (string) $jsonBody['action'];
    }
}
// Require CSRF for every state-mutating request (all POSTs and JSON-body requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($jsonBody)) {
    apiRequireValidCsrf();
}

// Reads never write the session (verified: only avatar/profile POSTs mutate $_SESSION),
// so release the per-user session lock now. Without this, a user's concurrent GET
// requests — the dashboard and inventory fire several at once — serialize behind PHP's
// session file lock and inflate tail latency under load. $_SESSION stays readable.
if ($_SERVER['REQUEST_METHOD'] === 'GET') { session_write_close(); }

switch ($action) {
    case 'get': handleGet(); break;
    case 'trash': handleTrash(); break;
    case 'books_get': handleBooksGet(); break;
    case 'books_add': handleBooksAdd(); break;
    case 'books_update': handleBooksUpdate(); break;
    case 'books_delete': handleBooksDelete(); break;
    case 'book_add_copies': handleBookAddCopies(); break;
    case 'book_mark_condition': handleBookMarkCondition(); break;
    case 'book_archive': handleBookArchive(); break;
    case 'book_stats':           handleBookStats(); break;
    case 'book_category_stats': handleBookCategoryStats(); break;
    case 'inventory_stats': handleInventoryStats(); break;
    case 'book_reports': handleBookReports(); break;
    case 'book_borrow_requests_get': handleBookBorrowRequestsGet(); break;
    case 'book_borrow_request_add': handleBookBorrowRequestAdd(); break;
    case 'book_borrow_approve': handleBookBorrowApprove(); break;
    case 'book_borrow_reject': handleBookBorrowReject(); break;
    case 'book_borrow_cancel': handleBookBorrowCancel(); break;
    case 'book_borrow_return': handleBookBorrowReturn(); break;
    case 'delivery_add':        handleDeliveryAdd(); break;
    case 'delivery_get':        handleDeliveryGet(); break;
    case 'delivery_update':     handleDeliveryUpdate(); break;
    case 'delivery_delete':     handleDeliveryDelete(); break;
    case 'delivery_attach_doc': handleDeliveryAttachDoc(); break;
    case 'delivery_delete_doc': handleDeliveryDeleteDoc(); break;
    case 'delivery_get_docs':   handleDeliveryGetDocs(); break;
    case 'books_bulk_import':   handleBooksBulkImport(); break;
    case 'borrowers_search': handleBorrowersSearch(); break;
    case 'borrowers_add': handleBorrowersAdd(); break;
    case 'announcements_get': handleAnnouncementsGet(); break;
    case 'announcement_view': handleAnnouncementView(); break;
    case 'announcement_mark_read': handleAnnouncementMarkRead(); break;
    case 'announcement_file': handleAnnouncementFile(); break;
    case 'announcement_image': handleAnnouncementImage(); break;
    case 'announcement_image_upload': handleAnnouncementImageUpload(); break;
    case 'announcements_add': handleAnnouncementsAdd(); break;
    case 'announcements_delete': handleAnnouncementsDelete(); break;
    case 'add': handleAdd(); break;
    case 'update': handleUpdate(); break;
    case 'delete': handleDelete(); break;
    case 'restore_deleted': handleRestoreDeleted(); break;
    case 'permanent_delete': handlePermanentDelete(); break;
    case 'permanent_delete_all': handlePermanentDeleteAll(); break;
    case 'archive': handleArchive(); break;
    case 'delete_file': handleDeleteFile(); break;
    case 'history': handleVersionHistory(); break;
    case 'borrow_history': handleBorrowHistory(); break;
    case 'borrow': handleBorrow(); break;
    case 'return': handleReturn(); break;
    case 'get_reservations':     handleGetReservations(); break;
    case 'create_reservation':   handleCreateReservation(); break;
    case 'cancel_reservation':   handleCancelReservation(); break;
    case 'notify_next_in_queue': handleNotifyNextInQueue(); break;
    case 'expire_reservations':  handleExpireReservations(); break;
    case 'reservation_calendar': handleReservationCalendar(); break;
    case 'waitlist_join':        handleWaitlistJoin(); break;
    case 'waitlist_respond':     handleWaitlistRespond(); break;
    case 'reservation_convert':  handleReservationConvert(); break;
    case 'isbn_lookup':          handleIsbnLookup(); break;
    case 'file_serve':           handleFileServe(); break;
    case 'discovery_search':     handleDiscoverySearch(); break;
    case 'book_add_from_discovery': handleBookAddFromDiscovery(); break;
    case 'book_similar':         handleBookSimilar(); break;
    case 'enrich_drain':         handleEnrichDrain(); break;
    case 'book_backfill':        handleBookBackfill(); break;
    case 'favorite_toggle':      handleFavoriteToggle(); break;
    case 'favorites_get':        handleFavoritesGet(); break;
    case 'audit_logs_get':       handleAuditLogsGet(); break;
    case 'settings_get':         handleSettingsGet(); break;
    case 'settings_save':        handleSettingsSave(); break;
    case 'borrowers_get':        handleBorrowersGet(); break;
    case 'borrower_profile':     handleBorrowerProfile(); break;
    case 'borrowers_update':     handleBorrowersUpdate(); break;
    case 'notifications_get':    handleNotificationsGet(); break;
    case 'notifications_mark_read': handleNotificationsMarkRead(); break;
    case 'calculate_fine':       handleCalculateFine(); break;
    case 'category_stats':           handleCategoryStats(); break;
    // ── Settings Module ──────────────────────────────────────────────────────
    case 'users_list':               handleUsersList(); break;
    case 'users_create':             handleUsersCreate(); break;
    case 'users_update':             handleUsersUpdate(); break;
    case 'users_toggle_status':      handleUsersToggleStatus(); break;
    case 'users_delete':             handleUsersDelete(); break;
    case 'policies_get':             handlePoliciesGet(); break;
    case 'policies_save':            handlePoliciesSave(); break;
    case 'maintenance_toggle':       handleMaintenanceToggle(); break;
    case 'db_export_csv':            handleDbExportCsv(); break;
    case 'db_purge_trash':           handleDbPurgeTrash(); break;
    case 'user_profile_get':         handleUserProfileGet(); break;
    case 'user_profile_update':      handleUserProfileUpdate(); break;
    case 'user_avatar':              handleUserAvatar(); break;
    case 'avatar_library':           handleAvatarLibrary(); break;
    case 'user_avatar_upload':       handleUserAvatarUpload(); break;
    case 'user_avatar_select':       handleUserAvatarSelect(); break;
    case 'user_avatar_remove':       handleUserAvatarRemove(); break;
    case 'user_password_change':     handleUserPasswordChange(); break;
    case 'user_notif_prefs_get':     handleUserNotifPrefsGet(); break;
    case 'user_notif_prefs_save':    handleUserNotifPrefsSave(); break;
    case 'user_activity_get':        handleUserActivityGet(); break;
    case 'user_sessions_get':        handleUserSessionsGet(); break;
    case 'user_sessions_revoke_all': handleUserSessionsRevokeAll(); break;
    case 'cron_sweep': handleCronSweep(); break;
    default: sendError('Unknown action.', 400);
}

function handleCronSweep(): void {
    global $pdo;
    apiRequireAdmin();
    try {
        purgeExpiredTrash($pdo);
        resMaintenanceSweep($pdo);
        sendSuccess([], 'Maintenance sweep completed.');
    } catch (Throwable $e) {
        sendError('Sweep failed: ' . dbgMsg($e), 500);
    }
}

function handleGet(): void {
    global $pdo;

    try {
        $stmt = $pdo->query(
            'SELECT d.*, u.full_name AS created_by_name
             FROM library_documents d
             LEFT JOIN users u ON d.created_by = u.id
             WHERE COALESCE(d.is_deleted, 0) = 0
             ORDER BY d.upload_date DESC'
        );
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($documents as &$doc) {
            attachBorrowData($pdo, $doc);
        }
        unset($doc);

        sendSuccess($documents);
    } catch (Throwable $e) {
        sendError('Failed to load documents: ' . dbgMsg($e), 500);
    }
}

function handleTrash(): void {
    global $pdo;
    apiRequireAdmin();

    try {
        $stmt = $pdo->query(
            "SELECT d.*, u.full_name AS created_by_name,
                    GREATEST(0, 30 - DATEDIFF(NOW(), COALESCE(d.deleted_at, d.updated_at, NOW()))) AS days_left
             FROM library_documents d
             LEFT JOIN users u ON d.created_by = u.id
             WHERE COALESCE(d.is_deleted, 0) = 1
             ORDER BY COALESCE(d.deleted_at, d.updated_at) DESC"
        );
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($documents as &$doc) {
            attachBorrowData($pdo, $doc);
        }
        unset($doc);
        sendSuccess($documents);
    } catch (Throwable $e) {
        sendError('Failed to load trash: ' . dbgMsg($e), 500);
    }
}

function handleAdd(): void {
    global $pdo;
    apiRequireStaff();

    $title = cleanValue($_POST['title'] ?? '');
    $type = cleanValue($_POST['document_type'] ?? '') ?: 'Document';
    $section = cleanValue($_POST['section'] ?? '');
    $notes = cleanValue($_POST['notes'] ?? '');
    [$year, $month, $day] = parseDocumentDate($_POST);

    if ($title === '') {
        sendError('Title is required.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO library_documents
             (title, document_type, section, year, month, day, notes, upload_date, created_by, is_archived, is_deleted, is_borrowed)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0, 0, 0)'
        );
        $stmt->execute([$title, $type, $section, $year, $month, $day, $notes ?: null, currentUserId()]);
        $documentId = (int) $pdo->lastInsertId();

        saveVersion($pdo, $documentId, 'created');
        storeUploadedFile($documentId);

        $pdo->commit();
        sendSuccess(['document_id' => $documentId], 'Document saved successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to save document: ' . dbgMsg($e), 500);
    }
}

function handleUpdate(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    $title = cleanValue($_POST['title'] ?? '');
    $type = cleanValue($_POST['document_type'] ?? '') ?: 'Document';
    $section = cleanValue($_POST['section'] ?? '');
    $notes = cleanValue($_POST['notes'] ?? '');
    [$year, $month, $day] = parseDocumentDate($_POST);

    if (!$id || $title === '') {
        sendError('Missing required fields.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE library_documents
             SET title = ?, document_type = ?, section = ?, year = ?, month = ?, day = ?, notes = ?, updated_at = NOW()
             WHERE id = ? AND COALESCE(is_deleted, 0) = 0'
        );
        $stmt->execute([$title, $type, $section, $year, $month, $day, $notes ?: null, $id]);

        saveVersion($pdo, $id, 'updated');
        storeUploadedFile($id);

        $pdo->commit();
        sendSuccess([], 'Document updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to update document: ' . dbgMsg($e), 500);
    }
}

function handleDelete(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        sendError('Missing document id.');
    }

    try {
        saveVersion($pdo, $id, 'deleted');
        $stmt = $pdo->prepare('UPDATE library_documents SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        sendSuccess([], 'Document moved to trash.');
    } catch (Throwable $e) {
        sendError('Failed to delete document: ' . dbgMsg($e), 500);
    }
}

function handleRestoreDeleted(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        sendError('Missing document id.');
    }

    try {
        $stmt = $pdo->prepare('UPDATE library_documents SET is_deleted = 0, deleted_at = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        saveVersion($pdo, $id, 'restored');
        sendSuccess([], 'Document restored.');
    } catch (Throwable $e) {
        sendError('Failed to restore document: ' . dbgMsg($e), 500);
    }
}

function handlePermanentDelete(): void {
    global $pdo;
    apiRequireAdmin();

    $ids = $_POST['ids'] ?? $_POST['id'] ?? [];
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        sendError('Select at least one document.');
    }

    try {
        $pdo->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // Unlink files before deleting rows
        $fileStmt = $pdo->prepare("SELECT file_path FROM library_documents WHERE id IN ({$placeholders})");
        $fileStmt->execute($ids);
        $fileStmt->execute($ids);
        foreach ($fileStmt->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            if ($fp) @unlink(__DIR__ . '/../' . $fp);
        }
        $pdo->prepare("DELETE FROM library_document_versions WHERE document_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM library_borrowing_records WHERE document_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM library_documents WHERE id IN ({$placeholders})")->execute($ids);
        $pdo->commit();
        sendSuccess([], 'Selected trash documents permanently deleted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to permanently delete documents: ' . dbgMsg($e), 500);
    }
}

function handlePermanentDeleteAll(): void {
    global $pdo;
    apiRequireAdmin();

    try {
        $stmt = $pdo->query('SELECT id FROM library_documents WHERE COALESCE(is_deleted, 0) = 1');
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $pdo->beginTransaction();
        foreach ($ids as $id) {
            permanentlyDeleteDocument($pdo, $id);
        }
        $pdo->commit();
        sendSuccess([], 'All trash documents permanently deleted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to empty trash: ' . dbgMsg($e), 500);
    }
}

function handleArchive(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    $archive = (int) ($_POST['archive'] ?? 1);
    if (!$id) {
        sendError('Missing document id.');
    }

    try {
        $stmt = $pdo->prepare('UPDATE library_documents SET is_archived = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$archive ? 1 : 0, $id]);
        saveVersion($pdo, $id, $archive ? 'archived' : 'restored');
        sendSuccess([], $archive ? 'Document archived.' : 'Document restored.');
    } catch (Throwable $e) {
        sendError('Failed to update archive status: ' . dbgMsg($e), 500);
    }
}

function handleDeleteFile(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        sendError('Missing document id.');
    }

    try {
        $doc = fetchDocument($pdo, $id);
        if (!empty($doc['file_path'])) {
            @unlink(__DIR__ . '/../' . $doc['file_path']);
        }

        $stmt = $pdo->prepare('UPDATE library_documents SET file_path = NULL, file_name = NULL, file_size = NULL, file_type = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
        saveVersion($pdo, $id, 'file_removed');
        sendSuccess([], 'File removed successfully.');
    } catch (Throwable $e) {
        sendError('Failed to remove file: ' . dbgMsg($e), 500);
    }
}

function handleVersionHistory(): void {
    global $pdo;

    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        sendError('Missing document id.');
    }

    $stmt = $pdo->prepare(
        "SELECT v.*, v.version_number AS version_no, u.full_name AS changed_by_name
         FROM library_document_versions v
         LEFT JOIN users u ON u.id = v.changed_by
         WHERE v.document_id = ?
           AND v.change_type IN ('created', 'updated', 'file_upload', 'file_removed')
         ORDER BY v.version_number DESC, v.changed_at DESC"
    );
    $stmt->execute([$id]);
    sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleBorrowHistory(): void {
    global $pdo;

    $id = (int) ($_GET['id'] ?? 0);
    $activeOnly = (($_GET['active'] ?? '') === '1');
    $isAdmin = (($_SESSION['role'] ?? 'user') === 'admin');

    $conditions = ['COALESCE(d.is_deleted, 0) = 0'];
    $params = [];

    if ($id) {
        $conditions[] = 'br.document_id = ?';
        $params[] = $id;
    }
    if ($activeOnly) {
        $conditions[] = 'br.returned_at IS NULL';
    }
    if (!$isAdmin) {
        $conditions[] = 'br.created_by = ?';
        $params[] = currentUserId();
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);

    $stmt = $pdo->prepare(
        "SELECT br.*, b.name AS borrower_name, b.contact AS borrower_contact, d.title AS document_title
         FROM library_borrowing_records br
         INNER JOIN library_borrowers b ON b.id = br.borrower_id
         INNER JOIN library_documents d ON d.id = br.document_id
         {$where}
         ORDER BY br.borrowed_at DESC"
    );
    $stmt->execute($params);
    sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleBorrow(): void {
    global $pdo;
    apiRequireLibraryAccess();

    $isStaffReq = in_array($_SESSION['role'] ?? 'viewer', ['admin', 'staff'], true);
    $documentId = (int) ($_POST['document_id'] ?? 0);
    $borrowerName = cleanValue($_POST['borrower_name'] ?? '');
    $borrowerContact = cleanValue($_POST['borrower_contact'] ?? '');
    $borrowedAt = cleanValue($_POST['borrowed_at'] ?? '') ?: date('Y-m-d H:i:s');
    $expectedReturn = cleanValue($_POST['expected_return_date'] ?? '') ?: null;
    $notes = cleanValue($_POST['notes'] ?? '');

    // Staff provide a borrower name (for walk-in patrons); regular users use their session identity
    if (!$documentId || ($isStaffReq && $borrowerName === '')) {
        sendError('Document' . ($isStaffReq ? ' and borrower name' : '') . ' required.');
    }
    if (activeBorrow($pdo, $documentId)) {
        sendError('This document is already borrowed.');
    }

    try {
        $pdo->beginTransaction();

        if ($isStaffReq) {
            $stmt = $pdo->prepare('INSERT INTO library_borrowers (name, contact) VALUES (?, ?) ON DUPLICATE KEY UPDATE contact = VALUES(contact)');
            $stmt->execute([$borrowerName, $borrowerContact ?: null]);
            $stmt = $pdo->prepare('SELECT id FROM library_borrowers WHERE name = ? LIMIT 1');
            $stmt->execute([$borrowerName]);
            $borrowerId = (int) $stmt->fetchColumn();
        } else {
            // Identity from session — never from user-supplied input
            $borrowerId = borrowerForUser($pdo, (int) currentUserId());
        }

        $stmt = $pdo->prepare(
            'INSERT INTO library_borrowing_records (document_id, borrower_id, borrowed_at, expected_return_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$documentId, $borrowerId, $borrowedAt, $expectedReturn, $notes ?: null, currentUserId()]);

        $stmt = $pdo->prepare('UPDATE library_documents SET is_borrowed = 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$documentId]);
        saveVersion($pdo, $documentId, 'borrowed');

        $pdo->commit();
        sendSuccess([], 'Document marked as borrowed.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to borrow document: ' . dbgMsg($e), 500);
    }
}

function handleReturn(): void {
    global $pdo;
    apiRequireLibraryAccess();

    $documentId = (int) ($_POST['document_id'] ?? 0);
    $returnNotes = cleanValue($_POST['return_notes'] ?? '');
    if (!$documentId) {
        sendError('Missing document id.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE library_borrowing_records
             SET returned_at = NOW(), return_notes = ?, returned_by = ?
             WHERE document_id = ? AND returned_at IS NULL
             ORDER BY borrowed_at DESC
             LIMIT 1'
        );
        $stmt->execute([$returnNotes ?: null, currentUserId(), $documentId]);

        $stmt = $pdo->prepare('UPDATE library_documents SET is_borrowed = 0, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$documentId]);
        saveVersion($pdo, $documentId, 'returned');

        $pdo->commit();
        sendSuccess([], 'Document returned successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to return document: ' . dbgMsg($e), 500);
    }
}

function handleBooksGet(): void {
    global $pdo;
    apiRequireLogin();
    try {
        // Backward-compatible: with no `per_page` the response is byte-for-byte the
        // same as before (all rows), so the existing client-side Inventory UI keeps
        // working. Pass `per_page` (plus optional `page`, `q`) to switch on
        // server-side pagination + search; `per_page` is hard-capped to protect the
        // server from pathological payloads.
        $q        = trim((string) ($_GET['q'] ?? ''));
        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $perPage  = (int) ($_GET['per_page'] ?? 0);
        $paginate = $perPage > 0;
        if ($paginate) { $perPage = min($perPage, 200); }

        $where  = '';
        $params = [];
        if ($q !== '') {
            $where  = ' WHERE (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR isbn13 LIKE ? OR subject LIKE ? OR category LIKE ?)';
            $like   = '%' . $q . '%';
            $params = [$like, $like, $like, $like, $like, $like];
        }

        $total = null;
        if ($paginate) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM books{$where}");
            $cnt->execute($params);
            $total = (int) $cnt->fetchColumn();
        }

        $sql = "SELECT * FROM books{$where} ORDER BY subject ASC, grade_level ASC, title ASC";
        if ($paginate) {
            $offset = ($page - 1) * $perPage;           // ints sanitized above — safe to inline
            $sql   .= " LIMIT {$perPage} OFFSET {$offset}";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Per-book circulation, computed as three aggregate maps (no N+1)
        $onLoan = $pdo->query(
            "SELECT i.book_id, COALESCE(SUM(GREATEST(i.quantity - COALESCE(i.returned_quantity,0),0)),0) q
             FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id
             WHERE r.status = 'borrowed' GROUP BY i.book_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $reserved = [];
        try {
            $reserved = $pdo->query(
                "SELECT book_id, COALESCE(SUM(quantity),0) q FROM book_reservations
                 WHERE status IN ('pending','confirmed','ready') GROUP BY book_id"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable) {}
        $overdueIds = [];
        try {
            $overdueIds = array_flip(array_map('intval', $pdo->query(
                "SELECT DISTINCT i.book_id FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id
                 WHERE r.status = 'borrowed' AND r.due_at IS NOT NULL AND r.due_at < NOW()"
            )->fetchAll(PDO::FETCH_COLUMN)));
        } catch (Throwable) {}

        foreach ($books as &$b) {
            $id = (int)$b['id'];
            $b['copies_on_loan']  = (int)($onLoan[$id] ?? 0);
            $b['copies_reserved'] = (int)($reserved[$id] ?? 0);
            $b['has_overdue']     = isset($overdueIds[$id]);
            $b['is_archived']     = (int)($b['is_archived'] ?? 0) === 1;
        }
        unset($b);
        if ($paginate) {
            sendSuccess($books, 'Success', ['meta' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'q'           => $q,
            ]]);
        }
        sendSuccess($books);
    } catch (Throwable $e) {
        sendError('Failed to load books: ' . dbgMsg($e), 500);
    }
}

/**
 * Server-side Inventory aggregates — totals (health cards) + per-category counts,
 * computed with the SAME definitions the client uses in inventory.js. Stage 1 of the
 * pagination migration: lets the UI keep accurate stats once it stops loading the
 * whole table. Additive and side-effect free; small numeric payload.
 */
function handleInventoryStats(): void {
    global $pdo;
    apiRequireLogin();
    try {
        $row = $pdo->query(
            "SELECT
                COUNT(*)                                                                AS titles,
                COALESCE(SUM(quantity_total),0)                                         AS copies,
                COALESCE(SUM(quantity_available),0)                                     AS available,
                COALESCE(SUM(quantity_missing),0)                                       AS lost,
                COALESCE(SUM(quantity_damaged),0)                                       AS damaged,
                COALESCE(SUM(quantity_available > 0),0)                                 AS cat_available,
                COALESCE(SUM(quantity_available = 0),0)                                 AS cat_out,
                COALESCE(SUM(quantity_available > 0 AND quantity_available <= 3),0)     AS cat_low,
                COALESCE(SUM(quantity_missing > 0),0)                                   AS cat_lost,
                COALESCE(SUM(quantity_damaged > 0),0)                                   AS cat_damaged,
                COALESCE(SUM(created_at >= (NOW() - INTERVAL 7 DAY)),0)                 AS recent
             FROM books WHERE COALESCE(is_archived,0) = 0"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $archived = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE COALESCE(is_archived,0) = 1")->fetchColumn();

        // Circulation-derived counts (guarded — these tables may be absent on old schemas)
        $borrowedCopies = $borrowedTitles = $overdueTitles = $reservedCopies = $reservedTitles = 0;
        try {
            $borrowedCopies = (int) $pdo->query(
                "SELECT COALESCE(SUM(GREATEST(i.quantity - COALESCE(i.returned_quantity,0),0)),0)
                 FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id
                 JOIN books bk ON bk.id = i.book_id
                 WHERE r.status = 'borrowed' AND COALESCE(bk.is_archived,0) = 0"
            )->fetchColumn();
            $borrowedTitles = (int) $pdo->query(
                "SELECT COUNT(DISTINCT i.book_id)
                 FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id
                 JOIN books bk ON bk.id = i.book_id
                 WHERE r.status = 'borrowed' AND COALESCE(bk.is_archived,0) = 0
                   AND (i.quantity - COALESCE(i.returned_quantity,0)) > 0"
            )->fetchColumn();
            $overdueTitles = (int) $pdo->query(
                "SELECT COUNT(DISTINCT i.book_id)
                 FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id
                 JOIN books bk ON bk.id = i.book_id
                 WHERE r.status = 'borrowed' AND r.due_at IS NOT NULL AND r.due_at < NOW()
                   AND COALESCE(bk.is_archived,0) = 0"
            )->fetchColumn();
        } catch (Throwable) {}
        try {
            $reservedCopies = (int) $pdo->query(
                "SELECT COALESCE(SUM(res.quantity),0) FROM book_reservations res
                 JOIN books bk ON bk.id = res.book_id
                 WHERE res.status IN ('pending','confirmed','ready') AND COALESCE(bk.is_archived,0) = 0"
            )->fetchColumn();
            $reservedTitles = (int) $pdo->query(
                "SELECT COUNT(DISTINCT res.book_id) FROM book_reservations res
                 JOIN books bk ON bk.id = res.book_id
                 WHERE res.status IN ('pending','confirmed','ready') AND COALESCE(bk.is_archived,0) = 0"
            )->fetchColumn();
        } catch (Throwable) {}

        sendSuccess([
            'totals' => [
                'titles' => (int) ($row['titles'] ?? 0), 'copies' => (int) ($row['copies'] ?? 0),
                'available' => (int) ($row['available'] ?? 0), 'borrowed' => $borrowedCopies,
                'reserved' => $reservedCopies, 'overdue' => $overdueTitles,
                'lost' => (int) ($row['lost'] ?? 0), 'damaged' => (int) ($row['damaged'] ?? 0),
                'recent' => (int) ($row['recent'] ?? 0),
            ],
            'categories' => [
                'all' => (int) ($row['titles'] ?? 0), 'available' => (int) ($row['cat_available'] ?? 0),
                'borrowed' => $borrowedTitles, 'reserved' => $reservedTitles,
                'low' => (int) ($row['cat_low'] ?? 0), 'out' => (int) ($row['cat_out'] ?? 0),
                'overdue' => $overdueTitles, 'lost' => (int) ($row['cat_lost'] ?? 0),
                'damaged' => (int) ($row['cat_damaged'] ?? 0), 'recent' => (int) ($row['recent'] ?? 0),
                'archived' => $archived,
            ],
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load inventory stats: ' . dbgMsg($e), 500);
    }
}

function handleBooksAdd(): void {
    global $pdo;
    apiRequireStaff();

    $title          = cleanValue($_POST['title'] ?? '');
    $author         = cleanValue($_POST['author'] ?? '');
    $subject        = cleanValue($_POST['subject'] ?? '');
    $category       = cleanValue($_POST['category'] ?? '');
    $isbn           = cleanValue($_POST['isbn'] ?? '');
    $gradeLevel     = cleanValue($_POST['grade_level'] ?? '');
    $locationLabel  = cleanValue($_POST['location_label'] ?? '');
    $qtyTotal       = max(0, (int) ($_POST['quantity_total'] ?? 0));
    $qtyAvailable   = max(0, (int) ($_POST['quantity_available'] ?? $qtyTotal));
    $qtyDamaged     = max(0, (int) ($_POST['quantity_damaged'] ?? 0));
    $qtyMissing     = max(0, (int) ($_POST['quantity_missing'] ?? 0));
    $condition      = in_array($_POST['condition_status'] ?? '', ['good','fair','poor']) ? $_POST['condition_status'] : 'good';

    if ($title === '') {
        sendError('Book title is required.');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO books
             (title, author, subject, category, isbn, grade_level, location_label,
              quantity_total, quantity_available, quantity_damaged, quantity_missing, condition_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $title, $author ?: null, $subject ?: null, $category ?: null,
            $isbn ?: null, $gradeLevel ?: null, $locationLabel ?: null,
            $qtyTotal, $qtyAvailable, $qtyDamaged, $qtyMissing, $condition
        ]);
        $newBookId = (int) $pdo->lastInsertId();
        logAudit($pdo, 'book_added', 'books', 'book', $newBookId, "Added: {$title}");
        sendSuccess(['id' => $newBookId], 'Book added successfully.');
    } catch (Throwable $e) {
        sendError('Failed to add book: ' . dbgMsg($e), 500);
    }
}

function handleBooksUpdate(): void {
    global $pdo;
    apiRequireStaff();

    $id             = (int) ($_POST['id'] ?? 0);
    $title          = cleanValue($_POST['title'] ?? '');
    $author         = cleanValue($_POST['author'] ?? '');
    $subject        = cleanValue($_POST['subject'] ?? '');
    $category       = cleanValue($_POST['category'] ?? '');
    $isbn           = cleanValue($_POST['isbn'] ?? '');
    $gradeLevel     = cleanValue($_POST['grade_level'] ?? '');
    $locationLabel  = cleanValue($_POST['location_label'] ?? '');
    $qtyTotal       = max(0, (int) ($_POST['quantity_total'] ?? 0));
    $qtyAvailable   = max(0, (int) ($_POST['quantity_available'] ?? 0));
    $qtyDamaged     = max(0, (int) ($_POST['quantity_damaged'] ?? 0));
    $qtyMissing     = max(0, (int) ($_POST['quantity_missing'] ?? 0));
    $condition      = in_array($_POST['condition_status'] ?? '', ['good','fair','poor']) ? $_POST['condition_status'] : 'good';

    if (!$id || $title === '') {
        sendError('Missing required fields.');
    }

    // Guard: available cannot exceed usable stock (total − damaged − missing)
    $usable = max(0, $qtyTotal - $qtyDamaged - $qtyMissing);
    if ($qtyAvailable > $usable) {
        sendError("quantity_available ({$qtyAvailable}) cannot exceed usable stock ({$usable} = total − damaged − missing).");
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE books SET
             title = ?, author = ?, subject = ?, category = ?, isbn = ?,
             grade_level = ?, location_label = ?, quantity_total = ?,
             quantity_available = ?, quantity_damaged = ?, quantity_missing = ?,
             condition_status = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            $title, $author ?: null, $subject ?: null, $category ?: null,
            $isbn ?: null, $gradeLevel ?: null, $locationLabel ?: null,
            $qtyTotal, $qtyAvailable, $qtyDamaged, $qtyMissing, $condition, $id
        ]);
        sendSuccess([], 'Book updated successfully.');
    } catch (Throwable $e) {
        sendError('Failed to update book: ' . dbgMsg($e), 500);
    }
}

function handleBookAddCopies(): void {
    global $pdo;
    apiRequireStaff();
    $id  = (int)($_POST['id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    if (!$id || $qty < 1) sendError('Book and a positive quantity are required.');
    try {
        $pdo->prepare("UPDATE books SET quantity_total = quantity_total + ?, quantity_available = quantity_available + ?, updated_at = NOW() WHERE id = ?")
            ->execute([$qty, $qty, $id]);
        logAudit($pdo, 'book_add_copies', 'books', 'book', $id, "+{$qty} copies");
        sendSuccess([], "Added {$qty} copy(ies).");
    } catch (Throwable $e) { sendError('Failed to add copies: ' . dbgMsg($e), 500); }
}

function handleBookMarkCondition(): void {
    global $pdo;
    apiRequireStaff();
    $id   = (int)($_POST['id'] ?? 0);
    $qty  = (int)($_POST['quantity'] ?? 0);
    $type = in_array($_POST['type'] ?? '', ['lost', 'damaged'], true) ? $_POST['type'] : '';
    if (!$id || $qty < 1 || $type === '') sendError('Book, quantity, and type (lost/damaged) are required.');
    try {
        $pdo->beginTransaction();
        $b = $pdo->prepare("SELECT quantity_available FROM books WHERE id = ? FOR UPDATE");
        $b->execute([$id]);
        $avail = (int)$b->fetchColumn();
        if ($qty > $avail) { $pdo->rollBack(); sendError("Only {$avail} available copy(ies) can be marked."); }
        $col = $type === 'lost' ? 'quantity_missing' : 'quantity_damaged';
        $pdo->prepare("UPDATE books SET quantity_available = quantity_available - ?, {$col} = {$col} + ?, updated_at = NOW() WHERE id = ?")
            ->execute([$qty, $qty, $id]);
        $pdo->commit();
        logAudit($pdo, "book_mark_{$type}", 'books', 'book', $id, "{$qty} marked {$type}");
        sendSuccess([], "Marked {$qty} copy(ies) as {$type}.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to update: ' . dbgMsg($e), 500);
    }
}

function handleBookArchive(): void {
    global $pdo;
    apiRequireStaff();
    $id = (int)($_POST['id'] ?? 0);
    $archive = (int)($_POST['archive'] ?? 1) === 1 ? 1 : 0;
    if (!$id) sendError('Book id required.');
    try {
        $pdo->prepare("UPDATE books SET is_archived = ?, updated_at = NOW() WHERE id = ?")->execute([$archive, $id]);
        logAudit($pdo, $archive ? 'book_archived' : 'book_unarchived', 'books', 'book', $id, '');
        sendSuccess([], $archive ? 'Book archived.' : 'Book restored.');
    } catch (Throwable $e) { sendError('Failed: ' . dbgMsg($e), 500); }
}

function handleBooksDelete(): void {
    global $pdo;
    apiRequireStaff();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        sendError('Missing book id.');
    }

    try {
        $pdo->prepare('DELETE FROM books WHERE id = ?')->execute([$id]);
        sendSuccess([], 'Book deleted successfully.');
    } catch (Throwable $e) {
        sendError('Failed to delete book: ' . dbgMsg($e), 500);
    }
}


function handleDeliveryGet(): void {
    global $pdo;
    try {
        $stmt = $pdo->query(
            'SELECT d.*, u.full_name AS logged_by_name
             FROM deliveries d
             LEFT JOIN users u ON u.id = d.logged_by
             ORDER BY d.delivery_date DESC, d.created_at DESC'
        );
        $deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deliveries as &$delivery) {
            $stmt2 = $pdo->prepare(
                'SELECT di.*, b.title, b.subject, b.grade_level
                 FROM delivery_items di
                 LEFT JOIN books b ON b.id = di.book_id
                 WHERE di.delivery_id = ?'
            );
            $stmt2->execute([$delivery['id']]);
            $delivery['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($delivery);

        sendSuccess($deliveries);
    } catch (Throwable $e) {
        sendError('Failed to load deliveries: ' . dbgMsg($e), 500);
    }
}

function handleDeliveryAdd(): void {
    global $pdo;
    apiRequireStaff();

    $deliveryDate = cleanValue($_POST['delivery_date'] ?? '') ?: date('Y-m-d');
    $source       = cleanValue($_POST['source'] ?? '');
    $remarks      = cleanValue($_POST['remarks'] ?? '');
    $items        = $_POST['items'] ?? [];

    if ($source === '') {
        sendError('Delivery source is required.');
    }
    if (empty($items)) {
        sendError('At least one book item is required.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO deliveries (delivery_date, source, remarks, logged_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$deliveryDate, $source, $remarks ?: null, currentUserId()]);
        $deliveryId = (int) $pdo->lastInsertId();

        $stmtItem = $pdo->prepare(
            'INSERT INTO delivery_items
             (delivery_id, book_id, quantity_received, quantity_damaged, quantity_missing, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmtUpdateBook = $pdo->prepare(
            'UPDATE books SET
             quantity_total = quantity_total + ?,
             quantity_available = quantity_available + ?,
             quantity_damaged = quantity_damaged + ?,
             quantity_missing = quantity_missing + ?,
             updated_at = NOW()
             WHERE id = ?'
        );

        foreach ($items as $item) {
            $bookId   = (int) ($item['book_id'] ?? 0);
            $qtyRcv   = max(0, (int) ($item['quantity_received'] ?? 0));
            $qtyDmg   = max(0, (int) ($item['quantity_damaged'] ?? 0));
            $qtyMiss  = max(0, (int) ($item['quantity_missing'] ?? 0));
            $notes    = cleanValue($item['notes'] ?? '');
            $qtyGood  = max(0, $qtyRcv - $qtyDmg - $qtyMiss);

            if (!$bookId || $qtyRcv === 0) continue;

            $stmtItem->execute([$deliveryId, $bookId, $qtyRcv, $qtyDmg, $qtyMiss, $notes ?: null]);
            $stmtUpdateBook->execute([$qtyRcv, $qtyGood, $qtyDmg, $qtyMiss, $bookId]);
            if ($qtyGood > 0) resProcessWaitlist($pdo, $bookId);  // new stock → offer to queue
        }

        logAudit($pdo, 'delivery_added', 'delivery-log', 'delivery', $deliveryId,
            "Source: {$source}, " . count($items) . " item(s)");
        createAdminNotification($pdo, 'delivery', "New Delivery Logged",
            "Delivery from {$source} recorded.", 'delivery-log', $deliveryId);

        $pdo->commit();
        sendSuccess(['delivery_id' => $deliveryId], 'Delivery logged successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to log delivery: ' . dbgMsg($e), 500);
    }
}

// ── DELIVERY UPDATE / DELETE / DOCUMENTS ─────────────────────────────────────

function handleDeliveryUpdate(): void {
    global $pdo;
    apiRequireStaff();

    $id         = (int) ($_POST['id'] ?? 0);
    $date       = cleanValue($_POST['delivery_date'] ?? '') ?: date('Y-m-d');
    $source     = cleanValue($_POST['source'] ?? '');
    $remarks    = cleanValue($_POST['remarks'] ?? '');
    $status     = cleanValue($_POST['status'] ?? 'received');
    $poNumber   = cleanValue($_POST['po_number'] ?? '');
    $refNumber  = cleanValue($_POST['ref_number'] ?? '');
    $receivedBy = cleanValue($_POST['received_by'] ?? '');

    if (!$id || $source === '') sendError('Delivery ID and source are required.');

    $allowed = ['pending', 'received', 'approved', 'cancelled'];
    if (!in_array($status, $allowed, true)) $status = 'received';

    try {
        $pdo->prepare(
            'UPDATE deliveries SET delivery_date=?,source=?,remarks=?,status=?,po_number=?,ref_number=?,received_by=? WHERE id=?'
        )->execute([$date, $source, $remarks ?: null, $status, $poNumber ?: null, $refNumber ?: null, $receivedBy ?: null, $id]);
        logAudit($pdo, 'delivery_updated', 'delivery-log', 'delivery', $id, "Source: {$source}, Status: {$status}");
        sendSuccess([], 'Delivery updated.');
    } catch (Throwable $e) {
        sendError('Failed to update delivery: ' . dbgMsg($e), 500);
    }
}

function handleDeliveryDelete(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) sendError('Delivery ID required.');

    try {
        $pdo->prepare('DELETE FROM delivery_items WHERE delivery_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM deliveries WHERE id=?')->execute([$id]);
        logAudit($pdo, 'delivery_deleted', 'delivery-log', 'delivery', $id, "Delivery #{$id} deleted");
        sendSuccess([], 'Delivery deleted.');
    } catch (Throwable $e) {
        sendError('Failed to delete delivery: ' . dbgMsg($e), 500);
    }
}

function handleDeliveryAttachDoc(): void {
    global $pdo;
    apiRequireStaff();

    $deliveryId = (int) ($_POST['delivery_id'] ?? 0);
    $label      = cleanValue($_POST['label'] ?? '');
    if (!$deliveryId) sendError('Delivery ID required.');

    try {
        storeDeliveryDocument($deliveryId);
        // Update label for last inserted doc
        if ($label) {
            $lastId = (int) $pdo->lastInsertId();
            if ($lastId) $pdo->prepare('UPDATE delivery_documents SET label=? WHERE id=?')->execute([$label, $lastId]);
        }
        logAudit($pdo, 'delivery_doc_attached', 'delivery-log', 'delivery', $deliveryId, "Document attached");
        sendSuccess([], 'Document attached.');
    } catch (Throwable $e) {
        sendError(dbgMsg($e), 400);
    }
}

function handleDeliveryDeleteDoc(): void {
    global $pdo;
    apiRequireStaff();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) sendError('Document ID required.');

    try {
        $row = $pdo->prepare('SELECT file_path FROM delivery_documents WHERE id=?');
        $row->execute([$id]);
        $doc = $row->fetch(PDO::FETCH_ASSOC);
        if ($doc && $doc['file_path']) {
            $abs = __DIR__ . '/../' . $doc['file_path'];
            if (file_exists($abs)) @unlink($abs);
        }
        $pdo->prepare('DELETE FROM delivery_documents WHERE id=?')->execute([$id]);
        sendSuccess([], 'Document removed.');
    } catch (Throwable $e) {
        sendError('Failed to delete document: ' . dbgMsg($e), 500);
    }
}

function handleDeliveryGetDocs(): void {
    global $pdo;

    $deliveryId = (int) ($_GET['delivery_id'] ?? 0);
    if (!$deliveryId) sendError('Delivery ID required.');

    try {
        $stmt = $pdo->prepare(
            'SELECT dd.*, u.full_name AS uploaded_by_name
             FROM delivery_documents dd
             LEFT JOIN users u ON u.id = dd.uploaded_by
             WHERE dd.delivery_id = ?
             ORDER BY dd.uploaded_at DESC'
        );
        $stmt->execute([$deliveryId]);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load documents: ' . dbgMsg($e), 500);
    }
}

// ── BULK BOOK IMPORT ─────────────────────────────────────────────────────────

function handleBooksBulkImport(): void {
    global $pdo;
    apiRequireAdmin();

    $rawRows    = $_POST['rows'] ?? '';
    $mode       = cleanValue($_POST['duplicate_mode'] ?? 'skip'); // skip | update | create
    $sourceFile = cleanValue($_POST['source_file'] ?? '');

    if (!$rawRows) sendError('No import data received.');

    $rows = json_decode($rawRows, true);
    if (!is_array($rows) || empty($rows)) sendError('Invalid or empty import data.');

    $allowed = ['skip', 'update', 'create'];
    if (!in_array($mode, $allowed, true)) $mode = 'skip';

    $imported = 0; $skipped = 0; $duplicates = 0; $errors = 0;
    $details  = [];

    try {
        $pdo->beginTransaction();

        $findDup = $pdo->prepare(
            "SELECT id FROM books
             WHERE (isbn IS NOT NULL AND isbn != '' AND UPPER(REPLACE(REPLACE(isbn,'-',''),' ','')) = ?)
                OR (title = ? AND COALESCE(author,'') = ?)
             LIMIT 1"
        );
        $insert = $pdo->prepare(
            'INSERT INTO books (title,author,subject,category,isbn,grade_level,location_label,quantity_total,quantity_available,condition_status,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $update = $pdo->prepare(
            'UPDATE books SET author=?,subject=?,category=?,grade_level=?,location_label=?,
             quantity_total=quantity_total+?,quantity_available=quantity_available+?,updated_at=NOW() WHERE id=?'
        );

        foreach ($rows as $idx => $row) {
            $title    = trim($row['title'] ?? '');
            $author   = trim($row['author'] ?? '');
            $isbn     = strtoupper(preg_replace('/[^0-9Xx]/', '', $row['isbn'] ?? ''));
            $subject  = trim($row['subject'] ?? '');
            $category = trim($row['category'] ?? '');
            $grade    = trim($row['grade_level'] ?? '');
            $location = trim($row['location_label'] ?? '');
            $qty      = max(0, (int) ($row['quantity'] ?? 0));
            $condRaw  = strtolower(trim((string) ($row['condition'] ?? '')));
            $condition= in_array($condRaw, ['good','fair','poor'], true) ? $condRaw : 'good';

            if ($title === '') {
                $details[] = ['row' => $idx + 1, 'status' => 'error', 'reason' => 'Title is required'];
                $errors++;
                continue;
            }

            $findDup->execute([$isbn ?: null, $title, $author]);
            $existing = $findDup->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ($mode === 'skip') {
                    $details[] = ['row' => $idx + 1, 'title' => $title, 'status' => 'duplicate', 'reason' => 'Skipped (duplicate)'];
                    $duplicates++;
                } elseif ($mode === 'update') {
                    $update->execute([$author ?: null, $subject ?: null, $category ?: null, $grade ?: null, $location ?: null, $qty, $qty, $existing['id']]);
                    $details[] = ['row' => $idx + 1, 'title' => $title, 'status' => 'updated'];
                    $duplicates++;
                    $imported++;
                } else {
                    $insert->execute([$title, $author ?: null, $subject ?: null, $category ?: null, $isbn ?: null, $grade ?: null, $location ?: null, $qty, $qty, $condition]);
                    $details[] = ['row' => $idx + 1, 'title' => $title, 'status' => 'imported'];
                    $imported++;
                }
            } else {
                $insert->execute([$title, $author ?: null, $subject ?: null, $category ?: null, $isbn ?: null, $grade ?: null, $location ?: null, $qty, $qty, $condition]);
                $details[] = ['row' => $idx + 1, 'title' => $title, 'status' => 'imported'];
                $imported++;
            }
        }

        // Log the import
        $pdo->prepare(
            'INSERT INTO import_logs (imported_by,source_file,total_rows,imported_count,skipped_count,duplicate_count,error_count,summary)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([currentUserId(), $sourceFile, count($rows), $imported, $skipped, $duplicates, $errors, json_encode($details)]);

        $pdo->commit();
        logAudit($pdo, 'bulk_import', 'books', 'books', null, "Imported:{$imported} Dupes:{$duplicates} Errors:{$errors}");

        sendSuccess([
            'imported'   => $imported,
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
            'errors'     => $errors,
            'details'    => $details,
        ], "Import complete: {$imported} added, {$duplicates} duplicates, {$errors} errors.");

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Import failed: ' . dbgMsg($e), 500);
    }
}

// ── ANNOUNCEMENTS v2 ─────────────────────────────
// List + per-user read status + attachments; full reader view; secure file serving.

/**
 * Allowlist HTML sanitizer for rich announcement bodies. Accepts the structured
 * markup the editor produces (headings, lists, links, quotes, callouts, tables,
 * images served from our own endpoints) and strips everything else — scripts,
 * event handlers, styles, external/inline-data images, javascript: URLs, etc.
 * This is what makes "paste from Word/Docs" safe: unknown tags are unwrapped.
 */
function sanitizeAnnouncementHtml(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $allowed = [
        'p','br','h1','h2','h3','h4','strong','b','em','i','u','s','blockquote',
        'ul','ol','li','a','img','table','thead','tbody','tr','td','th','div','span','hr','pre','code',
    ];
    // Per-tag attribute allowlist
    $attrs = [
        'a'   => ['href','title'],
        'img' => ['src','alt'],
        'div' => ['class'],
        'span'=> ['class'],
        'td'  => ['colspan','rowspan'],
        'th'  => ['colspan','rowspan'],
    ];
    // Only these CSS classes survive (the structured "style" system + callouts)
    $okClasses = ['callout','callout-info','callout-warning','callout-success','ql-align-center','ql-align-right','ql-align-justify'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Force UTF-8 and wrap so DOMDocument doesn't inject <html><body> semantics oddly
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $root = $doc->getElementById('__root');
    if (!$root) return '';

    $walk = function (DOMNode $node) use (&$walk, $allowed, $attrs, $okClasses): void {
        // Iterate over a static copy since we mutate the tree
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowed, true)) {
                    // Unwrap disallowed element: move its children up, then remove it
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                // Scrub attributes
                $keep = $attrs[$tag] ?? [];
                foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                    $an = strtolower($attr->name);
                    if (!in_array($an, $keep, true)) { $child->removeAttribute($attr->name); continue; }
                    $av = trim($attr->value);
                    if ($an === 'href') {
                        if (!preg_match('#^(https?:|mailto:|/|\#)#i', $av)) { $child->removeAttribute('href'); }
                        else { $child->setAttribute('target','_blank'); $child->setAttribute('rel','noopener nofollow'); }
                    } elseif ($an === 'src') {
                        // Only same-origin announcement images/files — no external or data: URLs
                        if (!preg_match('#^(api/library_handler\.php\?action=announcement_(image|file)|storage/attachments/announcements/)#i', $av)) {
                            $child->parentNode->removeChild($child);
                            continue 2;
                        }
                    } elseif ($an === 'class') {
                        $kept = array_values(array_intersect(preg_split('/\s+/', $av), $okClasses));
                        if ($kept) $child->setAttribute('class', implode(' ', $kept));
                        else $child->removeAttribute('class');
                    }
                }
                $walk($child);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    // Collapse the empty paragraphs editors love to emit
    $out = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $out);
    return trim($out);
}

function announcementAttachmentsFor(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, announcement_id, file_name, file_size, file_type
         FROM announcement_attachments WHERE announcement_id IN ($ph) ORDER BY id ASC"
    );
    $stmt->execute($ids);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $map[(int)$a['announcement_id']][] = [
            'id'        => (int)$a['id'],
            'file_name' => $a['file_name'],
            'file_size' => (int)$a['file_size'],
            'file_type' => $a['file_type'],
        ];
    }
    return $map;
}

function handleAnnouncementsGet(): void {
    global $pdo;
    try {
        $isStaff = in_array($_SESSION['role'] ?? 'user', ['admin', 'staff'], true);
        $uid     = (int) currentUserId();
        $q       = cleanValue($_GET['q'] ?? '');
        $cat     = cleanValue($_GET['category'] ?? '');

        $cond = [];
        $params = [$uid];
        if (!$isStaff) {
            // Published, scheduled time reached, and not expired
            $cond[] = "a.is_active = 1 AND (a.publish_at IS NULL OR a.publish_at <= NOW()) AND (a.expire_at IS NULL OR a.expire_at > NOW())";
        }
        if ($q !== '')   { $cond[] = "(a.title LIKE ? OR a.body LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
        if ($cat !== '') { $cond[] = "a.category = ?"; $params[] = $cat; }
        $where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';

        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.body, a.body_format, a.is_active, a.priority, a.category,
                    a.is_pinned, a.is_featured, a.expire_at, a.publish_at, a.updated_at,
                    a.posted_by, a.created_at, u.full_name AS posted_by_name,
                    (SELECT COUNT(*) FROM announcement_attachments att WHERE att.announcement_id = a.id) AS attachment_count,
                    (SELECT COUNT(*) FROM announcement_reads r WHERE r.announcement_id = a.id AND r.user_id = ?) AS is_read
             FROM announcements a
             LEFT JOIN users u ON u.id = a.posted_by
             {$where}
             ORDER BY a.is_pinned DESC, a.is_featured DESC,
                      FIELD(a.priority,'urgent','important','normal'), a.is_active DESC, a.created_at DESC
             LIMIT 100"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $attMap = announcementAttachmentsFor($pdo, $ids);

        $unread = 0;
        foreach ($rows as &$r) {
            $r['is_read']     = (int)$r['is_read'] > 0;
            $r['is_pinned']   = (int)$r['is_pinned'] === 1;
            $r['is_featured'] = (int)$r['is_featured'] === 1;
            $r['is_expired']  = !empty($r['expire_at']) && strtotime($r['expire_at']) <= time();
            $r['excerpt']     = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($r['body']))), 0, 140);
            $r['attachments'] = $attMap[(int)$r['id']] ?? [];
            unset($r['body']); // list view uses excerpt only; full body comes from the reader endpoint
            if (!$r['is_read'] && (int)$r['is_active'] === 1) $unread++;
        }
        unset($r);

        sendSuccess(['items' => $rows, 'unread_count' => $unread]);
    } catch (Throwable $e) {
        sendError('Failed to load announcements: ' . dbgMsg($e), 500);
    }
}

function handleAnnouncementView(): void {
    global $pdo;
    $id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $uid = (int) currentUserId();
    if (!$id) sendError('Missing announcement id.');

    try {
        $isStaff = in_array($_SESSION['role'] ?? 'user', ['admin', 'staff'], true);
        $stmt = $pdo->prepare(
            "SELECT a.id, a.title, a.body, a.body_format, a.is_active, a.priority, a.category,
                    a.is_pinned, a.is_featured, a.expire_at, a.publish_at, a.updated_at,
                    a.posted_by, a.created_at, u.full_name AS posted_by_name
             FROM announcements a LEFT JOIN users u ON u.id = a.posted_by
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$a) sendError('Announcement not found.', 404);
        if (!$isStaff && (int)$a['is_active'] !== 1) sendError('Announcement not available.', 403);

        $a['attachments'] = announcementAttachmentsFor($pdo, [$id])[$id] ?? [];
        $a['is_expired']  = !empty($a['expire_at']) && strtotime($a['expire_at']) <= time();
        $a['is_pinned']   = (int)$a['is_pinned'] === 1;
        $a['is_featured'] = (int)$a['is_featured'] === 1;
        // Re-sanitize on read as defence-in-depth for legacy/plain rows
        if (($a['body_format'] ?? 'text') === 'html') {
            $a['body_html'] = sanitizeAnnouncementHtml($a['body']);
        }

        // Mark read (idempotent)
        $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)")
            ->execute([$id, $uid]);

        sendSuccess($a);
    } catch (Throwable $e) {
        sendError('Failed to open announcement: ' . dbgMsg($e), 500);
    }
}

function handleAnnouncementMarkRead(): void {
    global $pdo;
    $id  = (int) ($_POST['id'] ?? 0);
    $uid = (int) currentUserId();
    if (!$id) sendError('Missing announcement id.');
    try {
        $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)")
            ->execute([$id, $uid]);
        sendSuccess([], 'Marked as read.');
    } catch (Throwable $e) {
        sendError('Failed: ' . dbgMsg($e), 500);
    }
}

// Stream an attachment securely (auth enforced by bootstrap apiRequireLogin).
function handleAnnouncementFile(): void {
    global $pdo;
    $attId = (int) ($_GET['id'] ?? 0);
    $dl    = ($_GET['download'] ?? '') === '1';
    if (!$attId) { http_response_code(400); exit('Bad request.'); }

    $isStaff = in_array($_SESSION['role'] ?? 'user', ['admin', 'staff'], true);
    $stmt = $pdo->prepare(
        "SELECT att.file_path, att.file_name, att.file_type, a.is_active, a.expire_at
         FROM announcement_attachments att
         JOIN announcements a ON a.id = att.announcement_id
         WHERE att.id = ? LIMIT 1"
    );
    $stmt->execute([$attId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); exit('Not found.'); }
    // Non-staff may only fetch files of published, non-expired announcements
    if (!$isStaff) {
        $expired = !empty($row['expire_at']) && strtotime($row['expire_at']) <= time();
        if ((int)$row['is_active'] !== 1 || $expired) { http_response_code(403); exit('Forbidden.'); }
    }

    $abs = __DIR__ . '/../' . $row['file_path'];
    if (!is_file($abs)) { http_response_code(404); exit('File missing.'); }

    $ext = strtolower(pathinfo($row['file_name'], PATHINFO_EXTENSION));
    $inlineOk = in_array($ext, ['pdf','jpg','jpeg','png','gif','webp','bmp'], true);
    $mime = [
        'pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
        'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp',
    ][$ext] ?? 'application/octet-stream';

    $disp = ($dl || !$inlineOk) ? 'attachment' : 'inline';
    // Override the JSON content-type set at the top of this script
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($row['file_name']) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=600');
    readfile($abs);
    exit;
}

function storeAnnouncementAttachments(PDO $pdo, int $announcementId): int {
    if (empty($_FILES['attachments']['name'][0])) return 0;
    if (!is_dir(ANNOUNCE_UPLOAD_DIR)) @mkdir(ANNOUNCE_UPLOAD_DIR, 0755, true);

    $files = $_FILES['attachments'];
    $insert = $pdo->prepare(
        'INSERT INTO announcement_attachments (announcement_id, file_path, file_name, file_size, file_type)
         VALUES (?,?,?,?,?)'
    );
    $stored = 0;
    foreach ($files['tmp_name'] as $i => $tmp) {
        $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('An attachment exceeds the 20 MB limit.');
        }
        if ($err !== UPLOAD_ERR_OK) continue;

        $original = basename($files['name'][$i]);
        if (!isAllowedUploadFile($tmp, $original)) {
            throw new RuntimeException("File type not allowed: {$original}. Supported: PDF, Word, Excel, images.");
        }
        $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original);
        $name = 'ann_' . uniqid('', true) . '_' . $safe;
        if (move_uploaded_file($tmp, ANNOUNCE_UPLOAD_DIR . $name)) {
            $insert->execute([
                $announcementId, ANNOUNCE_UPLOAD_PATH . $name, $original,
                filesize(ANNOUNCE_UPLOAD_DIR . $name), $ext,
            ]);
            $stored++;
        }
    }
    return $stored;
}

const ANNOUNCEMENT_CATEGORIES = ['general','urgent','event','academic','library','memorandum'];

function handleAnnouncementsAdd(): void {
    global $pdo;
    apiRequireStaff();

    $title    = cleanValue($_POST['title'] ?? '');
    $format   = ($_POST['body_format'] ?? 'text') === 'html' ? 'html' : 'text';
    $rawBody  = (string) ($_POST['body'] ?? '');
    $body     = $format === 'html' ? sanitizeAnnouncementHtml($rawBody) : cleanValue($rawBody);
    $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
    $priority = in_array($_POST['priority'] ?? '', ['normal','important','urgent'], true) ? $_POST['priority'] : 'normal';
    $category = in_array($_POST['category'] ?? '', ANNOUNCEMENT_CATEGORIES, true) ? $_POST['category'] : 'general';
    $isPinned   = !empty($_POST['is_pinned']) ? 1 : 0;
    $isFeatured = !empty($_POST['is_featured']) ? 1 : 0;
    $expireAt = cleanValue($_POST['expire_at'] ?? '');
    $expireAt = ($expireAt !== '' && strtotime($expireAt)) ? date('Y-m-d H:i:s', strtotime($expireAt)) : null;
    $publishAt = cleanValue($_POST['publish_at'] ?? '');
    $publishAt = ($publishAt !== '' && strtotime($publishAt)) ? date('Y-m-d H:i:s', strtotime($publishAt)) : null;

    $plain = trim(strip_tags($body));
    if ($title === '' || $plain === '') {
        sendError('Title and message are required.');
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO announcements
             (title, body, body_format, posted_by, is_active, priority, category, is_pinned, is_featured, publish_at, expire_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$title, $body, $format, currentUserId(), $isActive, $priority, $category, $isPinned, $isFeatured, $publishAt, $expireAt]);
        $id = (int) $pdo->lastInsertId();

        $attached = storeAnnouncementAttachments($pdo, $id);
        $pdo->commit();

        logAudit($pdo, 'announcement_posted', 'announcements', 'announcement', $id, "\"{$title}\" [{$category}] ({$attached} file(s))");
        createAdminNotification($pdo, 'announcement', 'Announcement posted', $title, 'dashboard', $id);
        sendSuccess(['id' => $id, 'attachments' => $attached], 'Announcement posted successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to post announcement: ' . dbgMsg($e), 500);
    }
}

// Inline image upload for the rich-text editor. Returns a same-origin URL the
// editor embeds; the sanitizer only trusts URLs from this endpoint.
function handleAnnouncementImageUpload(): void {
    global $pdo;
    apiRequireStaff();

    if (empty($_FILES['image']['name']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        sendError('No image received.');
    }
    $tmp = $_FILES['image']['tmp_name'];
    $original = basename($_FILES['image']['name']);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif','webp','bmp'], true) || !isAllowedUploadFile($tmp, $original)) {
        sendError('Only image files (JPG, PNG, GIF, WebP) are allowed.');
    }
    $dir = ANNOUNCE_UPLOAD_DIR . 'inline/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'img_' . uniqid('', true) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . $name)) sendError('Failed to store image.', 500);

    $url = 'api/library_handler.php?action=announcement_image&file=' . rawurlencode($name);
    sendSuccess(['url' => $url], 'Uploaded.');
}

function handleAnnouncementImage(): void {
    $file = $_GET['file'] ?? '';
    // Strict whitelist of the generated name pattern — blocks traversal entirely
    if (!preg_match('/^img_[a-zA-Z0-9._]+\.(jpg|jpeg|png|gif|webp|bmp)$/', $file)) {
        http_response_code(404); exit('Not found.');
    }
    $abs = ANNOUNCE_UPLOAD_DIR . 'inline/' . $file;
    if (!is_file($abs)) { http_response_code(404); exit('Not found.'); }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'][$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    readfile($abs);
    exit;
}

function handleAnnouncementsDelete(): void {
    global $pdo;
    apiRequireStaff();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) sendError('Missing announcement id.');

    try {
        // Remove attachment files from disk first
        $f = $pdo->prepare('SELECT file_path FROM announcement_attachments WHERE announcement_id = ?');
        $f->execute([$id]);
        foreach ($f->fetchAll(PDO::FETCH_COLUMN) as $fp) {
            if ($fp) @unlink(__DIR__ . '/../' . $fp);
        }
        $pdo->prepare('DELETE FROM announcement_attachments WHERE announcement_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM announcement_reads WHERE announcement_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        sendSuccess([], 'Announcement deleted.');
    } catch (Throwable $e) {
        sendError('Failed to delete announcement: ' . dbgMsg($e), 500);
    }
}

function apiRequireStaff(): void {
    apiRequireLogin();
    $role = $_SESSION['role'] ?? 'viewer';
    if (!in_array($role, ['admin', 'staff'], true)) {
        sendError('Access denied: staff privileges required.', 403);
    }
}

function findOrCreateBorrower(PDO $pdo, string $name, string $contact, string $lrn = ''): int {
    // LRN is the reliable unique identifier; prefer it over name when available
    if ($lrn !== '') {
        $stmt = $pdo->prepare('SELECT id FROM library_borrowers WHERE lrn = ? LIMIT 1');
        $stmt->execute([$lrn]);
        $id = (int) $stmt->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE library_borrowers SET contact = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$contact ?: null, $id]);
            return $id;
        }
    }
    // Fall back to name-based upsert (walk-ins without LRN)
    $pdo->prepare('INSERT INTO library_borrowers (name, lrn, contact) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE contact = VALUES(contact), updated_at = NOW()')
        ->execute([$name, $lrn ?: null, $contact ?: null]);
    $stmt = $pdo->prepare('SELECT id FROM library_borrowers WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn();
}

/**
 * Identity-based borrower resolution: the borrower record is derived from the
 * AUTHENTICATED account (users.id), never from client-supplied names. This is
 * the only path regular users' transactions take — it makes impersonation
 * impossible and keeps borrow history tied to the account, not a name string.
 */
function borrowerForUser(PDO $pdo, int $userId): int {
    $find = $pdo->prepare('SELECT id FROM library_borrowers WHERE user_id = ? LIMIT 1');
    try {
        $find->execute([$userId]);
        $id = (int) $find->fetchColumn();
        if ($id) return $id;
    } catch (Throwable) {
        // user_id column guaranteed by schema migration; query failure is unexpected
    }

    $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $u->execute([$userId]);
    $usr = $u->fetch(PDO::FETCH_ASSOC);
    if (!$usr) throw new RuntimeException('Account not found.');
    $name = trim((string)($usr['full_name'] ?? '')) ?: ('User #' . $userId);

    // Claim a legacy unlinked borrower row with the same name, if any
    $pdo->prepare('UPDATE library_borrowers SET user_id = ? WHERE name = ? AND user_id IS NULL LIMIT 1')
        ->execute([$userId, $name]);
    $find->execute([$userId]);
    $id = (int) $find->fetchColumn();
    if ($id) return $id;

    // Create a linked borrower; suffix with username (not internal ID) if name is taken
    $username   = trim((string)($usr['username'] ?? '')) ?: ('u' . $userId);
    $candidates = [$name, $name . ' (' . $username . ')'];
    foreach ($candidates as $candidate) {
        try {
            $pdo->prepare('INSERT INTO library_borrowers (name, contact, user_id) VALUES (?,?,?)')
                ->execute([$candidate, $usr['contact'] ?? null, $userId]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable) { /* name taken — try with username suffix */ }
    }
    throw new RuntimeException('Unable to resolve borrower profile for this account.');
}

function handleBookStats(): void {
    global $pdo;
    try {
        $row = $pdo->query(
            "SELECT COALESCE(SUM(quantity_total),0) total_qty, COALESCE(SUM(quantity_available),0) available_qty,
                    COALESCE(SUM(quantity_damaged),0) damaged_qty, COALESCE(SUM(quantity_missing),0) lost_qty,
                    COUNT(*) titles
             FROM books WHERE COALESCE(is_archived,0) = 0"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($row['total_qty'] ?? 0);
        $available = (int)($row['available_qty'] ?? 0);

        $onLoan = (int)$pdo->query(
            "SELECT COALESCE(SUM(GREATEST(i.quantity - COALESCE(i.returned_quantity,0),0)),0)
             FROM book_borrow_items i JOIN book_borrow_records r ON r.id = i.borrow_id WHERE r.status = 'borrowed'"
        )->fetchColumn();
        $reserved = 0;
        try { $reserved = (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM book_reservations WHERE status IN ('pending','confirmed','ready')")->fetchColumn(); } catch (Throwable) {}
        $overdue = (int)$pdo->query("SELECT COUNT(*) FROM book_borrow_records WHERE status='borrowed' AND due_at IS NOT NULL AND due_at < NOW()")->fetchColumn();
        $today = (int)$pdo->query("SELECT COUNT(*) FROM book_borrow_records WHERE (borrowed_at IS NOT NULL AND DATE(borrowed_at)=CURDATE()) OR (returned_at IS NOT NULL AND DATE(returned_at)=CURDATE())")->fetchColumn();
        $recent = (int)$pdo->query("SELECT COUNT(*) FROM books WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND COALESCE(is_archived,0)=0")->fetchColumn();
        $archived = (int)$pdo->query("SELECT COUNT(*) FROM books WHERE COALESCE(is_archived,0)=1")->fetchColumn();

        sendSuccess([
            'total_qty' => $total,
            'titles' => (int)($row['titles'] ?? 0),
            'available_qty' => $available,
            'borrowed_qty' => $onLoan,
            'reserved_qty' => $reserved,
            'overdue_count' => $overdue,
            'lost_qty' => (int)($row['lost_qty'] ?? 0),
            'damaged_qty' => (int)($row['damaged_qty'] ?? 0),
            'recently_added' => $recent,
            'archived_count' => $archived,
            'today_transactions' => $today,
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load book stats: ' . dbgMsg($e), 500);
    }
}

function handleBookCategoryStats(): void {
    global $pdo;
    apiRequireLogin();
    try {
        $stmt = $pdo->query(
            'SELECT id, title, author, grade_level, location_label, subject,
                    quantity_total, quantity_available, condition_status
             FROM books
             ORDER BY subject ASC, title ASC'
        );
        $books = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($books as $book) {
            $subject = trim((string)($book['subject'] ?? '')) ?: 'Uncategorized';
            if (!isset($grouped[$subject])) {
                $grouped[$subject] = ['subject' => $subject, 'total' => 0, 'copies' => 0, 'available' => 0, 'books' => []];
            }
            $grouped[$subject]['total']++;
            $grouped[$subject]['copies']    += (int)($book['quantity_total'] ?? 0);
            $grouped[$subject]['available'] += (int)($book['quantity_available'] ?? 0);
            $grouped[$subject]['books'][]   = [
                'id'                 => (int)$book['id'],
                'title'              => $book['title'],
                'author'             => $book['author'],
                'grade_level'        => $book['grade_level'],
                'location_label'     => $book['location_label'],
                'quantity_available' => (int)($book['quantity_available'] ?? 0),
                'quantity_total'     => (int)($book['quantity_total'] ?? 0),
                'condition_status'   => $book['condition_status'],
            ];
        }
        usort($grouped, fn($a, $b) => $b['copies'] - $a['copies']);
        sendSuccess(array_values($grouped));
    } catch (Throwable $e) {
        sendError('Failed to load category stats: ' . dbgMsg($e), 500);
    }
}

function handleBookBorrowRequestsGet(): void {
    global $pdo;

    $role = $_SESSION['role'] ?? 'viewer';
    $isStaff = in_array($role, ['admin', 'staff'], true);
    $status = cleanValue($_GET['status'] ?? '');
    $scope = cleanValue($_GET['scope'] ?? '');

    $conditions = [];
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'r.status = ?';
        $params[] = $status;
    }
    if (!$isStaff || $scope === 'mine') {
        $conditions[] = 'r.requested_by = ?';
        $params[] = currentUserId();
    } elseif ($scope === 'pending') {
        $conditions[] = "r.status = 'pending'";
    } elseif ($scope === 'active') {
        $conditions[] = "r.status = 'borrowed'";
    }

    $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

    // Pagination — backward-compatible: no `per_page` returns all matching rows
    // (previous behavior). Conditions only reference r.*, so COUNT needs no joins.
    $page     = max(1, (int) ($_GET['page'] ?? 1));
    $perPage  = (int) ($_GET['per_page'] ?? 0);
    $paginate = $perPage > 0;
    if ($paginate) { $perPage = min($perPage, 200); }

    $total = null;
    if ($paginate) {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM book_borrow_records r {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();
    }

    // Explicit column list — never expose borrower_id (internal FK) or LRN to clients
    $sql =
        "SELECT r.id, r.status, r.borrow_type, r.time_allowed_minutes,
                r.requested_at, r.borrowed_at, r.due_at, r.returned_at,
                r.return_notes, r.fine_amount, r.reservation_id,
                r.requested_start, r.requested_due,
                b.name AS borrower_name, b.contact AS borrower_contact,
                u.full_name AS requested_by_name, u2.full_name AS reviewed_by_name
         FROM book_borrow_records r
         INNER JOIN library_borrowers b ON b.id = r.borrower_id
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users u2 ON u2.id = r.reviewed_by
         {$where}
         ORDER BY r.requested_at DESC, r.id DESC";
    if ($paginate) {
        $offset = ($page - 1) * $perPage;
        $sql   .= " LIMIT {$perPage} OFFSET {$offset}";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Batch-load all line items in ONE query (was an N+1: one query per borrow row).
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $itemsByBorrow = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $itemStmt = $pdo->prepare(
            "SELECT i.*, bk.title, bk.subject, bk.grade_level
             FROM book_borrow_items i
             LEFT JOIN books bk ON bk.id = i.book_id
             WHERE i.borrow_id IN ($ph)"
        );
        $itemStmt->execute($ids);
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
            $itemsByBorrow[(int) $it['borrow_id']][] = $it;
        }
    }
    foreach ($rows as &$row) {
        $row['items'] = $itemsByBorrow[(int) $row['id']] ?? [];
    }
    unset($row);

    if ($paginate) {
        sendSuccess($rows, 'Success', ['meta' => [
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]]);
    }
    sendSuccess($rows);
}

function handleBookBorrowRequestAdd(): void {
    global $pdo;
    apiRequireLogin();

    $isStaffReq      = in_array($_SESSION['role'] ?? 'viewer', ['admin', 'staff'], true);
    $borrowerId      = (int) ($_POST['borrower_id'] ?? 0);
    $borrowerName    = cleanValue($_POST['borrower_name'] ?? '');
    $borrowerContact = cleanValue($_POST['borrower_contact'] ?? '');
    $borrowType      = cleanValue($_POST['borrow_type'] ?? '');
    $timeAllowed = isset($_POST['time_allowed_minutes']) ? (int) $_POST['time_allowed_minutes'] : null;
    $borrowDate = cleanValue($_POST['borrow_date'] ?? '');
    $returnDate = cleanValue($_POST['return_date'] ?? '');
    $items = $_POST['items'] ?? [];

    // Staff may transact on behalf of walk-in borrowers; regular users ALWAYS
    // borrow as themselves — any client-supplied borrower identity is ignored.
    if ($isStaffReq && $borrowerName === '') {
        sendError('Borrower name is required.');
    }
    if ($borrowDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $borrowDate)) sendError('Invalid borrow date.');
    if ($returnDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) sendError('Invalid return date.');
    if ($borrowDate !== '' && $returnDate !== '' && $returnDate < $borrowDate) {
        sendError('Return date must be on or after the borrow date.');
    }
    if (!is_array($items) || empty($items)) {
        sendError('At least one book item is required.');
    }

    $cleanItems = [];
    foreach ($items as $item) {
        $bookId = (int) ($item['book_id'] ?? 0);
        $qty = max(1, (int) ($item['quantity'] ?? 1));
        if ($bookId <= 0) continue;
        $cleanItems[] = ['book_id' => $bookId, 'quantity' => $qty];
    }
    if (!$cleanItems) {
        sendError('Please select at least one valid book.');
    }

    try {
        $pdo->beginTransaction();

        if (!$isStaffReq) {
            $borrowerId = borrowerForUser($pdo, (int) currentUserId());
        } elseif ($borrowerId > 0) {
            // Staff selected a borrower from search — validate the ID exists
            $chk = $pdo->prepare('SELECT id FROM library_borrowers WHERE id = ? LIMIT 1');
            $chk->execute([$borrowerId]);
            if (!(int) $chk->fetchColumn()) {
                throw new RuntimeException('Selected borrower not found.');
            }
        } else {
            // Staff typed a name manually without using the search
            $borrowerId = findOrCreateBorrower($pdo, $borrowerName, $borrowerContact);
        }
        if (!$borrowerId) {
            throw new RuntimeException('Unable to resolve borrower.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO book_borrow_records (borrower_id, requested_by, status, borrow_type, time_allowed_minutes, requested_start, requested_due)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$borrowerId, currentUserId(), 'pending', $borrowType ?: null, $timeAllowed, $borrowDate ?: null, $returnDate ?: null]);
        $borrowId = (int) $pdo->lastInsertId();

        $stmtItem = $pdo->prepare('INSERT INTO book_borrow_items (borrow_id, book_id, quantity) VALUES (?, ?, ?)');
        foreach ($cleanItems as $item) {
            $stmtItem->execute([$borrowId, $item['book_id'], $item['quantity']]);
        }

        $pdo->commit();
        sendSuccess(['id' => $borrowId], 'Borrow request submitted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to submit borrow request: ' . dbgMsg($e), 500);
    }
}

function handleBookBorrowApprove(): void {
    global $pdo;
    apiRequireStaff();

    $borrowId = (int) ($_POST['id'] ?? 0);
    $borrowedAt = cleanValue($_POST['borrowed_at'] ?? '') ?: date('Y-m-d H:i:s');
    $dueAt = cleanValue($_POST['due_at'] ?? '');

    if (!$borrowId) {
        sendError('Missing borrow request id.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM book_borrow_records WHERE id = ? FOR UPDATE');
        $stmt->execute([$borrowId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            throw new RuntimeException('Borrow request not found.');
        }
        if (($record['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Only pending requests can be approved.');
        }

        $stmtItems = $pdo->prepare('SELECT * FROM book_borrow_items WHERE borrow_id = ?');
        $stmtItems->execute([$borrowId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            throw new RuntimeException('Borrow items not found.');
        }

        // Precedence: explicit due > date requested on the form > fixed time-allowance > open.
        $computedDue = \Lib\DueDate::compute(
            $dueAt,
            $record['requested_due'] ?? null,
            isset($record['time_allowed_minutes']) ? (int) $record['time_allowed_minutes'] : null,
            $borrowedAt
        );

        $stmtBook = $pdo->prepare('SELECT quantity_available, title FROM books WHERE id = ? FOR UPDATE');
        $stmtUpdateBook = $pdo->prepare('UPDATE books SET quantity_available = quantity_available - ?, updated_at = NOW() WHERE id = ?');

        // Projected interval this borrow will occupy (today → due date)
        $intStart = date('Y-m-d');
        $intEnd   = $computedDue ? max(date('Y-m-d', strtotime($computedDue)), $intStart) : $intStart;

        foreach ($items as $item) {
            $bookId = (int) $item['book_id'];
            $qty = (int) $item['quantity'];
            $stmtBook->execute([$bookId]);
            $bookRow = $stmtBook->fetch(PDO::FETCH_ASSOC);
            $available = (int) ($bookRow['quantity_available'] ?? 0);
            if ($available < $qty) {
                throw new RuntimeException('Not enough available copies for one or more books.');
            }
            // Don't starve confirmed reservations: capacity must hold over the borrow window
            $freeForRange = resMaxForRange($pdo, $bookId, $intStart, $intEnd);
            if ($freeForRange < $qty) {
                throw new RuntimeException(
                    "Approving this borrow conflicts with confirmed reservations for \"{$bookRow['title']}\" — " .
                    "only {$freeForRange} cop(ies) remain unreserved through {$intEnd}. " .
                    "Reduce the quantity, shorten the due date, or review the reservations."
                );
            }
        }

        foreach ($items as $item) {
            $bookId = (int) $item['book_id'];
            $qty = (int) $item['quantity'];
            $stmtUpdateBook->execute([$qty, $bookId]);
        }

        $stmt = $pdo->prepare(
            "UPDATE book_borrow_records
             SET status = 'borrowed',
                 reviewed_by = ?, reviewed_at = NOW(),
                 borrowed_at = ?, due_at = ?
             WHERE id = ?"
        );
        $stmt->execute([currentUserId(), $borrowedAt, $computedDue, $borrowId]);

        $borrowerName = $record['borrower_name'] ?? 'Borrower';
        logAudit($pdo, 'borrow_approved', 'borrowing', 'borrow_record', $borrowId,
            "Approved borrow ID {$borrowId}, due: {$computedDue}");
        createAdminNotification($pdo, 'borrow', "Borrow Approved #$borrowId",
            "Borrow request approved. Due: {$computedDue}", 'borrowing', $borrowId);

        $pdo->commit();
        sendSuccess([], 'Borrow request approved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to approve borrow request: ' . dbgMsg($e), 500);
    }
}

function handleBookBorrowReject(): void {
    global $pdo;
    apiRequireStaff();

    $borrowId = (int) ($_POST['id'] ?? 0);
    if (!$borrowId) {
        sendError('Missing borrow request id.');
    }

    try {
        $stmt = $pdo->prepare(
            "UPDATE book_borrow_records
             SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([currentUserId(), $borrowId]);
        sendSuccess([], 'Borrow request rejected.');
    } catch (Throwable $e) {
        sendError('Failed to reject borrow request: ' . dbgMsg($e), 500);
    }
}

function handleBookBorrowCancel(): void {
    global $pdo;
    apiRequireLogin();

    $borrowId = (int) ($_POST['id'] ?? 0);
    if (!$borrowId) {
        sendError('Missing borrow request id.');
    }

    $role = $_SESSION['role'] ?? 'viewer';
    $isStaff = in_array($role, ['admin', 'staff'], true);

    try {
        if ($isStaff) {
            $stmt = $pdo->prepare("UPDATE book_borrow_records SET status = 'cancelled', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->execute([currentUserId(), $borrowId]);
            logAudit($pdo, 'borrow_cancelled', 'borrowing', 'borrow', $borrowId, 'Cancelled by staff');
            sendSuccess([], 'Borrow request cancelled.');
            return;
        }

        $stmt = $pdo->prepare("UPDATE book_borrow_records SET status = 'cancelled' WHERE id = ? AND requested_by = ? AND status = 'pending'");
        $stmt->execute([$borrowId, currentUserId()]);
        sendSuccess([], 'Borrow request cancelled.');
    } catch (Throwable $e) {
        sendError('Failed to cancel borrow request: ' . dbgMsg($e), 500);
    }
}

function handleBookBorrowReturn(): void {
    global $pdo;
    apiRequireStaff();

    $borrowId = (int) ($_POST['id'] ?? 0);
    $returnNotes = cleanValue($_POST['return_notes'] ?? '');
    $fineAmount = isset($_POST['fine_amount']) && is_numeric($_POST['fine_amount']) ? (float) $_POST['fine_amount'] : null;
    $items = $_POST['items'] ?? [];
    $autoFineOverride = ($_POST['auto_fine'] ?? '') === '1';

    if (!$borrowId) {
        sendError('Missing borrow id.');
    }
    if (!is_array($items) || empty($items)) {
        sendError('Return items are required.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM book_borrow_records WHERE id = ? FOR UPDATE');
        $stmt->execute([$borrowId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$record) {
            throw new RuntimeException('Borrow record not found.');
        }
        if (($record['status'] ?? '') !== 'borrowed') {
            throw new RuntimeException('Only borrowed records can be returned.');
        }

        $stmtItems = $pdo->prepare('SELECT * FROM book_borrow_items WHERE borrow_id = ? FOR UPDATE');
        $stmtItems->execute([$borrowId]);
        $borrowItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (!$borrowItems) {
            throw new RuntimeException('Borrow items not found.');
        }

        $byBook = [];
        foreach ($borrowItems as $bi) {
            $byBook[(int) $bi['book_id']] = $bi;
        }

        $stmtUpdateItem = $pdo->prepare(
            'UPDATE book_borrow_items
             SET returned_quantity = returned_quantity + ?,
                 returned_damaged = returned_damaged + ?,
                 returned_missing = returned_missing + ?
             WHERE borrow_id = ? AND book_id = ?'
        );
        $stmtUpdateBook = $pdo->prepare(
            'UPDATE books SET
             quantity_available = quantity_available + ?,
             quantity_damaged = quantity_damaged + ?,
             quantity_missing = quantity_missing + ?,
             updated_at = NOW()
             WHERE id = ?'
        );

        foreach ($items as $item) {
            $bookId = (int) ($item['book_id'] ?? 0);
            if (!$bookId || !isset($byBook[$bookId])) {
                continue;
            }

            $returnedQty = max(0, (int) ($item['returned_quantity'] ?? 0));
            $returnedDamaged = max(0, (int) ($item['returned_damaged'] ?? 0));
            $returnedMissing = max(0, (int) ($item['returned_missing'] ?? 0));
            $returnedDamaged = min($returnedDamaged, $returnedQty);
            $returnedMissing = min($returnedMissing, $returnedQty - $returnedDamaged);

            $originalQty = (int) $byBook[$bookId]['quantity'];
            $alreadyReturned = (int) $byBook[$bookId]['returned_quantity'];
            $remaining = max(0, $originalQty - $alreadyReturned);
            if ($returnedQty > $remaining) {
                throw new RuntimeException('Returned quantity exceeds borrowed quantity.');
            }

            $goodReturn = max(0, $returnedQty - $returnedDamaged - $returnedMissing);
            $stmtUpdateItem->execute([$returnedQty, $returnedDamaged, $returnedMissing, $borrowId, $bookId]);
            $stmtUpdateBook->execute([$goodReturn, $returnedDamaged, $returnedMissing, $bookId]);
        }

        $stmtItems->execute([$borrowId]);
        $updatedItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        $allReturned = true;
        foreach ($updatedItems as $ui) {
            if ((int) $ui['returned_quantity'] < (int) $ui['quantity']) {
                $allReturned = false;
                break;
            }
        }

        if ($allReturned) {
            // Auto-calculate fine if not supplied and auto_fine_enabled
            if ($fineAmount === null || $autoFineOverride) {
                $autoFineOn = ($pdo->query(
                    "SELECT setting_value FROM library_settings WHERE setting_key = 'auto_fine_enabled'"
                )->fetchColumn() === '1');
                if ($autoFineOn) {
                    $rate = (float)($pdo->query(
                        "SELECT setting_value FROM library_settings WHERE setting_key = 'fine_per_day'"
                    )->fetchColumn() ?: 5);
                    $rFetch = $pdo->prepare("SELECT due_at FROM book_borrow_records WHERE id = ? LIMIT 1");
                    $rFetch->execute([$borrowId]);
                    $dueAt = $rFetch->fetchColumn();
                    if ($dueAt) {
                        // Same rule as handleCalculateFine — delegated to the unit-tested core.
                        $fineAmount = \Lib\Fines::calculate($dueAt, new DateTime(), $rate)['fine_amount'];
                    }
                }
            }

            $stmt = $pdo->prepare(
                "UPDATE book_borrow_records
                 SET status = 'returned',
                     returned_at = NOW(),
                     returned_by = ?,
                     return_notes = ?,
                     fine_amount = ?
                 WHERE id = ?"
            );
            $stmt->execute([currentUserId(), $returnNotes ?: null, $fineAmount, $borrowId]);
            logAudit($pdo, 'book_returned', 'borrowing', 'borrow_record', $borrowId,
                "Fine: PHP " . number_format((float)($fineAmount ?? 0), 2));
        }
        // Returned copies free up capacity — mark linked reservation completed,
        // then offer freed capacity to the waiting list (FIFO)
        if ($allReturned && !empty($record['reservation_id'])) {
            $pdo->prepare("UPDATE book_reservations SET status = 'completed', updated_at = NOW()
                           WHERE id = ? AND status = 'fulfilled'")
                ->execute([(int)$record['reservation_id']]);
        }
        foreach (array_unique(array_map(fn($bi) => (int)$bi['book_id'], $borrowItems)) as $freedBookId) {
            resProcessWaitlist($pdo, $freedBookId);
        }

        $pdo->commit();
        sendSuccess(['completed' => $allReturned], $allReturned ? 'Books returned successfully.' : 'Return saved (partial).');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to return books: ' . dbgMsg($e), 500);
    }
}
// ═══════════════════════════════════════════════════════════════
// RESERVATION ENGINE v2 — date-ranged, quantity-based bookings
// Availability is DERIVED per day; nothing is double-entered.
//   usable(book)       = total − damaged − missing
//   blocked(book, D)   = Σ reservations(pending|confirmed|ready) overlapping D
//                      + Σ open borrows projected out on D
//   available(book, D) = usable − blocked
// All capacity mutations serialize on SELECT books FOR UPDATE.
// ═══════════════════════════════════════════════════════════════

function resBookStock(PDO $pdo, int $bookId): array {
    $stmt = $pdo->prepare('SELECT id, title, quantity_total, quantity_available, quantity_damaged, quantity_missing FROM books WHERE id = ?');
    $stmt->execute([$bookId]);
    $b = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$b) throw new RuntimeException('Book not found.');
    $b['usable'] = max(0, (int)$b['quantity_total'] - (int)$b['quantity_damaged'] - (int)$b['quantity_missing']);
    return $b;
}

function resAvailabilityByDay(PDO $pdo, int $bookId, string $from, string $to): array {
    $book = resBookStock($pdo, $bookId);

    $r = $pdo->prepare(
        "SELECT quantity, start_date, end_date FROM book_reservations
         WHERE book_id = ? AND status IN ('pending','confirmed','ready')
           AND start_date <= ? AND end_date >= ?"
    );
    $r->execute([$bookId, $to, $from]);
    $reservations = $r->fetchAll(PDO::FETCH_ASSOC);

    $b = $pdo->prepare(
        "SELECT GREATEST(i.quantity - COALESCE(i.returned_quantity, 0), 0) AS qty,
                DATE(COALESCE(r.borrowed_at, NOW())) AS start_date,
                GREATEST(DATE(COALESCE(r.due_at, NOW())), CURDATE()) AS end_date
         FROM book_borrow_items i
         JOIN book_borrow_records r ON r.id = i.borrow_id
         WHERE i.book_id = ? AND r.status = 'borrowed'"
    );
    $b->execute([$bookId]);
    $borrows = array_values(array_filter($b->fetchAll(PDO::FETCH_ASSOC), fn($x) => (int)$x['qty'] > 0));

    // Pre-aggregate into day-indexed maps (O(total_span_days)) instead of O(n*range_days)
    $resByDay = [];
    $borByDay = [];
    $expandRange = static function (array $records, string $qtyKey, array &$resByDay, string $clampFrom, string $clampTo): void {
        foreach ($records as $x) {
            $start = max($clampFrom, $x['start_date']);
            $end   = min($clampTo,   $x['end_date']);
            if ($start > $end) continue;
            $d = new DateTime($start);
            $last = new DateTime($end);
            while ($d <= $last) {
                $k = $d->format('Y-m-d');
                $resByDay[$k] = ($resByDay[$k] ?? 0) + (int)$x[$qtyKey];
                $d->modify('+1 day');
            }
        }
    };
    $expandRange($reservations, 'quantity', $resByDay, $from, $to);
    $expandRange($borrows,      'qty',      $borByDay, $from, $to);

    $days = [];
    $d    = new DateTime($from);
    $last = new DateTime($to);
    while ($d <= $last) {
        $key  = $d->format('Y-m-d');
        $resQ = $resByDay[$key] ?? 0;
        $borQ = $borByDay[$key] ?? 0;
        $days[$key] = [
            'total'     => $book['usable'],
            'reserved'  => $resQ,
            'borrowed'  => $borQ,
            'available' => max(0, $book['usable'] - $resQ - $borQ),
        ];
        $d->modify('+1 day');
    }
    return ['book' => $book, 'days' => $days];
}

// Max copies reservable across EVERY day of [start, end] (inclusive)
function resMaxForRange(PDO $pdo, int $bookId, string $start, string $end): int {
    $data = resAvailabilityByDay($pdo, $bookId, $start, $end);
    $min = PHP_INT_MAX;
    foreach ($data['days'] as $day) $min = min($min, $day['available']);
    return $min === PHP_INT_MAX ? 0 : $min;
}

function resQueueSms(PDO $pdo, int $userId, string $message, ?int $relatedId = null): void {
    try {
        $c = $pdo->prepare('SELECT contact FROM users WHERE id = ?');
        $c->execute([$userId]);
        $phone = trim((string)$c->fetchColumn());
        if ($phone === '') return;
        $pdo->prepare("INSERT INTO sms_queue (phone, message, status, related_type, related_id)
                       VALUES (?,?, 'pending', 'reservation', ?)")
            ->execute([$phone, $message, $relatedId]);
    } catch (Throwable) {}
}

/**
 * Strict FIFO offer engine. Caller should hold the book row lock when
 * releasing capacity. Offers do not consume capacity until accepted,
 * and only the queue head may hold an active offer (no skipping).
 */
function resProcessWaitlist(PDO $pdo, int $bookId): int {
    $active = $pdo->prepare("SELECT id FROM book_waitlist WHERE book_id = ? AND status = 'offered' AND offer_expires_at > NOW() LIMIT 1");
    $active->execute([$bookId]);
    if ($active->fetch()) return 0;

    $head = $pdo->prepare("SELECT * FROM book_waitlist WHERE book_id = ? AND status = 'waiting' ORDER BY created_at ASC, id ASC LIMIT 1");
    $head->execute([$bookId]);
    $w = $head->fetch(PDO::FETCH_ASSOC);
    if (!$w) return 0;

    $today = date('Y-m-d');
    $start = ($w['preferred_start'] && $w['preferred_start'] >= $today) ? $w['preferred_start'] : $today;
    $end   = ($w['preferred_end'] && $w['preferred_end'] >= $start) ? $w['preferred_end'] : date('Y-m-d', strtotime($start . ' +7 days'));

    $max = resMaxForRange($pdo, $bookId, $start, $end);
    $offerQty = min((int)$w['quantity'], $max);
    if ($offerQty < 1) return 0;
    if ($offerQty < (int)$w['quantity'] && !(int)$w['allow_partial']) return 0; // strict FIFO: head blocks

    $expDays = (int)($pdo->query("SELECT setting_value FROM library_settings WHERE setting_key = 'reservation_expiry_days'")->fetchColumn() ?: 3);
    $expires = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));
    $pdo->prepare("UPDATE book_waitlist SET status = 'offered', offer_qty = ?, offer_expires_at = ? WHERE id = ?")
        ->execute([$offerQty, $expires, $w['id']]);

    $title = '';
    try { $title = (string)$pdo->query("SELECT title FROM books WHERE id = " . (int)$bookId)->fetchColumn(); } catch (Throwable) {}
    resQueueSms($pdo, (int)$w['user_id'],
        "SDO Library: {$offerQty} cop(ies) of \"{$title}\" are now available for you. Confirm in the Library system before {$expires}.",
        (int)$w['id']);
    createAdminNotification($pdo, 'reservation', 'Waitlist Offer Sent',
        "Offered {$offerQty} cop(ies) of \"{$title}\" to the next user in queue.", 'reservations', (int)$w['id']);
    return 1;
}

/**
 * Lifecycle sweep — safe to run on every request (idempotent, indexed):
 *  confirmed → ready when start date arrives (arms pickup deadline)
 *  ready     → no_show past pickup deadline (releases capacity → queue)
 *  offered   → expired past offer TTL (→ next in queue)
 *  any blocking status → expired once the window has fully passed
 */
function resMaintenanceSweep(PDO $pdo): void {
    $due = $pdo->query(
        "SELECT r.id, r.start_date, u.classification
         FROM book_reservations r LEFT JOIN users u ON u.id = r.user_id
         WHERE r.status IN ('pending','confirmed') AND r.start_date <= CURDATE()"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($due as $r) {
        $grace = 1;
        try {
            $g = $pdo->prepare('SELECT GREATEST(COALESCE(grace_period_days,0),1) FROM borrowing_policies WHERE classification = ?');
            $g->execute([$r['classification'] ?: 'individual']);
            $grace = (int)($g->fetchColumn() ?: 1);
        } catch (Throwable) {}
        $deadline = date('Y-m-d 23:59:59', strtotime($r['start_date'] . " +{$grace} days"));
        $pdo->prepare("UPDATE book_reservations SET status = 'ready', pickup_deadline = ?, updated_at = NOW()
                       WHERE id = ? AND status IN ('pending','confirmed')")
            ->execute([$deadline, $r['id']]);
    }

    $noShows = $pdo->query(
        "SELECT id, book_id FROM book_reservations
         WHERE status = 'ready' AND pickup_deadline IS NOT NULL AND pickup_deadline < NOW()"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($noShows as $r) {
        $pdo->prepare("UPDATE book_reservations SET status = 'no_show', updated_at = NOW() WHERE id = ? AND status = 'ready'")
            ->execute([$r['id']]);
        logAudit($pdo, 'reservation_no_show', 'reservations', 'reservation', (int)$r['id'], 'Pickup deadline passed; copies released');
        resProcessWaitlist($pdo, (int)$r['book_id']);
    }

    $expiredOffers = $pdo->query(
        "SELECT id, book_id FROM book_waitlist WHERE status = 'offered' AND offer_expires_at < NOW()"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($expiredOffers as $w) {
        $pdo->prepare("UPDATE book_waitlist SET status = 'expired' WHERE id = ? AND status = 'offered'")->execute([$w['id']]);
        resProcessWaitlist($pdo, (int)$w['book_id']);
    }

    $pdo->exec("UPDATE book_reservations SET status = 'expired', updated_at = NOW()
                WHERE status IN ('pending','confirmed','ready') AND end_date < CURDATE()");
}

function handleReservationCalendar(): void {
    global $pdo;
    apiRequireLogin();

    $bookId = (int)($_GET['book_id'] ?? 0);
    $from   = cleanValue($_GET['from'] ?? '');
    $to     = cleanValue($_GET['to'] ?? '');
    if (!$bookId) sendError('Book ID required.');

    if ($from === '' || $to === '') {
        $month = cleanValue($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) sendError('Invalid date range.');
    if ((new DateTime($from))->diff(new DateTime($to))->days > 92) sendError('Date range too large (max 92 days).');

    try {
        $data = resAvailabilityByDay($pdo, $bookId, $from, $to);
        $out = [
            'book' => [
                'id'     => (int)$data['book']['id'],
                'title'  => $data['book']['title'],
                'usable' => $data['book']['usable'],
                'on_shelf_now' => (int)$data['book']['quantity_available'],
            ],
            'days' => $data['days'],
        ];
        // Optional feasibility probe for a sub-range
        $qs = cleanValue($_GET['start'] ?? '');
        $qe = cleanValue($_GET['end'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $qs) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $qe) && $qe >= $qs) {
            $out['range'] = ['start' => $qs, 'end' => $qe, 'max_available' => resMaxForRange($pdo, $bookId, $qs, $qe)];
        }
        sendSuccess($out);
    } catch (Throwable $e) {
        sendError('Failed to compute availability: ' . dbgMsg($e), 500);
    }
}

function handleGetReservations(): void {
    global $pdo;
    apiRequireLogin();
    $isStaff = in_array($_SESSION['role'] ?? 'viewer', ['admin', 'staff'], true);
    $userId  = (int)currentUserId();

    try {
        $posExpr = "(SELECT COUNT(*) + 1 FROM book_waitlist w2
                     WHERE w2.book_id = w.book_id AND w2.status = 'waiting'
                       AND (w2.created_at < w.created_at OR (w2.created_at = w.created_at AND w2.id < w.id)))";

        if ($isStaff) {
            $resv = $pdo->query(
                "SELECT r.id, r.book_id, r.quantity, r.start_date, r.end_date, r.status,
                        r.pickup_deadline, r.approved_at, r.notes, r.created_at, r.updated_at,
                        u.full_name AS user_name, u.username AS user_email, b.title AS book_title
                 FROM book_reservations r
                 JOIN users u ON u.id = r.user_id
                 JOIN books b ON b.id = r.book_id
                 ORDER BY FIELD(r.status,'ready','confirmed','pending') DESC, r.start_date ASC, r.id DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $wl = $pdo->query(
                "SELECT w.*, u.full_name AS user_name, b.title AS book_title,
                        CASE WHEN w.status = 'waiting' THEN {$posExpr} ELSE NULL END AS queue_position
                 FROM book_waitlist w
                 JOIN users u ON u.id = w.user_id
                 JOIN books b ON b.id = w.book_id
                 WHERE w.status IN ('waiting','offered')
                 ORDER BY w.book_id, w.created_at ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare(
                "SELECT r.id, r.book_id, r.quantity, r.start_date, r.end_date, r.status,
                        r.pickup_deadline, r.approved_at, r.notes, r.created_at, r.updated_at,
                        b.title AS book_title
                 FROM book_reservations r
                 JOIN books b ON b.id = r.book_id
                 WHERE r.user_id = ?
                 ORDER BY r.id DESC LIMIT 100"
            );
            $stmt->execute([$userId]);
            $resv = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare(
                "SELECT w.*, b.title AS book_title,
                        CASE WHEN w.status = 'waiting' THEN {$posExpr} ELSE NULL END AS queue_position
                 FROM book_waitlist w
                 JOIN books b ON b.id = w.book_id
                 WHERE w.user_id = ? AND w.status IN ('waiting','offered','converted','declined','expired')
                 ORDER BY w.id DESC LIMIT 50"
            );
            $stmt->execute([$userId]);
            $wl = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        sendSuccess(['reservations' => $resv, 'waitlist' => $wl]);
    } catch (Throwable $e) {
        sendError('Failed to load reservations: ' . dbgMsg($e), 500);
    }
}

function handleCreateReservation(): void {
    global $pdo;
    apiRequireLogin();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookId = (int)($input['book_id'] ?? $_POST['book_id'] ?? 0);
    $qty    = (int)($input['quantity'] ?? $_POST['quantity'] ?? 0);
    $start  = cleanValue($input['start_date'] ?? $_POST['start_date'] ?? '');
    $end    = cleanValue($input['end_date'] ?? $_POST['end_date'] ?? '');
    $userId = (int)currentUserId();

    if (!$bookId || $qty < 1) sendError('Book and quantity (at least 1) are required.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        sendError('Reservation start and end dates are required (YYYY-MM-DD).');
    }
    if ($end < $start) sendError('End date must be on or after the start date.');
    if ($start < date('Y-m-d')) sendError('Start date cannot be in the past.');
    if ((new DateTime($start))->diff(new DateTime($end))->days > 60) sendError('Reservation window cannot exceed 60 days.');

    try {
        $pdo->beginTransaction();
        // Serialize all capacity decisions for this book
        $lock = $pdo->prepare('SELECT id FROM books WHERE id = ? FOR UPDATE');
        $lock->execute([$bookId]);
        if (!$lock->fetch()) { $pdo->rollBack(); sendError('Book not found.', 404); }

        $book = resBookStock($pdo, $bookId);
        if ($qty > $book['usable']) {
            $pdo->rollBack();
            sendError("Requested quantity ({$qty}) exceeds the library's total usable stock ({$book['usable']}).");
        }

        // Hoarding guard: same user + book + overlapping window
        $dup = $pdo->prepare(
            "SELECT id FROM book_reservations
             WHERE user_id = ? AND book_id = ? AND status IN ('pending','confirmed','ready')
               AND start_date <= ? AND end_date >= ?"
        );
        $dup->execute([$userId, $bookId, $end, $start]);
        if ($dup->fetch()) {
            $pdo->rollBack();
            sendError('You already have an active reservation for this book in an overlapping date range.');
        }

        $max = resMaxForRange($pdo, $bookId, $start, $end);
        if ($qty > $max) {
            $pdo->rollBack();
            sendJson([
                'success' => false,
                'code'    => 'INSUFFICIENT',
                'message' => $max > 0
                    ? "Only {$max} cop(ies) of \"{$book['title']}\" are available for {$start} to {$end}."
                    : "No copies of \"{$book['title']}\" are available for {$start} to {$end}.",
                'data'    => ['max_available' => $max],
            ], 200);
        }

        // Provisional pickup deadline: start_date + grace period (refined by sweep when ready)
        $expDays = (int)($pdo->query("SELECT setting_value FROM library_settings WHERE setting_key='reservation_expiry_days'")->fetchColumn() ?: 3);
        $provisionalDeadline = date('Y-m-d 23:59:59', strtotime($start . " +{$expDays} days"));
        $pdo->prepare(
            "INSERT INTO book_reservations (book_id, user_id, quantity, start_date, end_date, status, approved_at, pickup_deadline)
             VALUES (?,?,?,?,?, 'confirmed', NOW(), ?)"
        )->execute([$bookId, $userId, $qty, $start, $end, $provisionalDeadline]);
        $resId = (int)$pdo->lastInsertId();

        logAudit($pdo, 'reservation_created', 'reservations', 'reservation', $resId,
            "{$qty} × \"{$book['title']}\" {$start} → {$end}");
        createAdminNotification($pdo, 'reservation', 'New Reservation',
            "{$qty} cop(ies) of \"{$book['title']}\" reserved {$start} → {$end}.", 'reservations', $resId);

        $pdo->commit();
        sendSuccess(['id' => $resId], "Reservation confirmed: {$qty} cop(ies) from {$start} to {$end}. Copies free again on " . date('M j', strtotime($end . ' +1 day')) . '.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to create reservation: ' . dbgMsg($e), 500);
    }
}

function handleWaitlistJoin(): void {
    global $pdo;
    apiRequireLogin();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookId = (int)($input['book_id'] ?? $_POST['book_id'] ?? 0);
    $qty    = (int)($input['quantity'] ?? $_POST['quantity'] ?? 0);
    $start  = cleanValue($input['preferred_start'] ?? $_POST['preferred_start'] ?? '');
    $end    = cleanValue($input['preferred_end'] ?? $_POST['preferred_end'] ?? '');
    $partial = (int)($input['allow_partial'] ?? $_POST['allow_partial'] ?? 1) ? 1 : 0;
    $userId = (int)currentUserId();

    if (!$bookId || $qty < 1) sendError('Book and quantity are required.');
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) sendError('Invalid preferred start date.');
    if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) sendError('Invalid preferred end date.');

    try {
        $dup = $pdo->prepare("SELECT id FROM book_waitlist WHERE user_id = ? AND book_id = ? AND status IN ('waiting','offered')");
        $dup->execute([$userId, $bookId]);
        if ($dup->fetch()) sendError('You are already in the waiting list for this book.');

        $pdo->prepare(
            "INSERT INTO book_waitlist (book_id, user_id, quantity, preferred_start, preferred_end, allow_partial)
             VALUES (?,?,?,?,?,?)"
        )->execute([$bookId, $userId, $qty, $start ?: null, $end ?: null, $partial]);
        $wlId = (int)$pdo->lastInsertId();

        $pos = $pdo->prepare("SELECT COUNT(*) FROM book_waitlist WHERE book_id = ? AND status = 'waiting' AND id <= ?");
        $pos->execute([$bookId, $wlId]);
        $position = (int)$pos->fetchColumn();

        logAudit($pdo, 'waitlist_joined', 'reservations', 'waitlist', $wlId, "{$qty} × book #{$bookId}, position {$position}");
        sendSuccess(['id' => $wlId, 'position' => $position], "Added to the waiting list — you are #{$position} in queue. You'll be notified when copies free up.");
    } catch (Throwable $e) {
        sendError('Failed to join waiting list: ' . dbgMsg($e), 500);
    }
}

function handleWaitlistRespond(): void {
    global $pdo;
    apiRequireLogin();

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $wlId     = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $response = cleanValue($input['response'] ?? $_POST['response'] ?? '');
    $userId   = (int)currentUserId();

    if (!$wlId || !in_array($response, ['accept', 'decline'], true)) sendError('Waitlist ID and response (accept/decline) required.');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM book_waitlist WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute([$wlId, $userId]);
        $w = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$w) { $pdo->rollBack(); sendError('Waitlist entry not found.', 404); }
        if ($w['status'] !== 'offered') { $pdo->rollBack(); sendError('This offer is no longer active.'); }
        if ($w['offer_expires_at'] && $w['offer_expires_at'] < date('Y-m-d H:i:s')) {
            $pdo->prepare("UPDATE book_waitlist SET status = 'expired' WHERE id = ?")->execute([$wlId]);
            $pdo->commit();
            sendError('This offer has expired.');
        }

        $bookId = (int)$w['book_id'];

        if ($response === 'decline') {
            $pdo->prepare("UPDATE book_waitlist SET status = 'declined' WHERE id = ?")->execute([$wlId]);
            resProcessWaitlist($pdo, $bookId);
            $pdo->commit();
            sendSuccess([], 'Offer declined. The next person in queue will be notified.');
        }

        // accept → re-validate under the book lock and convert to a confirmed reservation
        $pdo->prepare('SELECT id FROM books WHERE id = ? FOR UPDATE')->execute([$bookId]);

        $today = date('Y-m-d');
        $start = ($w['preferred_start'] && $w['preferred_start'] >= $today) ? $w['preferred_start'] : $today;
        $end   = ($w['preferred_end'] && $w['preferred_end'] >= $start) ? $w['preferred_end'] : date('Y-m-d', strtotime($start . ' +7 days'));

        $max = resMaxForRange($pdo, $bookId, $start, $end);
        $finalQty = min((int)$w['offer_qty'], $max);
        if ($finalQty < 1) {
            // Capacity was claimed in the meantime — keep them at the head of the queue
            $pdo->prepare("UPDATE book_waitlist SET status = 'waiting', offer_qty = NULL, offer_expires_at = NULL WHERE id = ?")
                ->execute([$wlId]);
            $pdo->commit();
            sendError('Those copies were claimed before you confirmed. You remain at the head of the queue and will be re-notified.');
        }

        $pdo->prepare(
            "INSERT INTO book_reservations (book_id, user_id, quantity, start_date, end_date, status, approved_at, notes)
             VALUES (?,?,?,?,?, 'confirmed', NOW(), 'From waiting list offer')"
        )->execute([$bookId, $userId, $finalQty, $start, $end]);
        $resId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE book_waitlist SET status = 'converted' WHERE id = ?")->execute([$wlId]);
        logAudit($pdo, 'waitlist_converted', 'reservations', 'reservation', $resId, "Waitlist #{$wlId} → reservation, {$finalQty} cop(ies) {$start} → {$end}");
        $pdo->commit();
        sendSuccess(['reservation_id' => $resId, 'quantity' => $finalQty],
            "Reservation confirmed: {$finalQty} cop(ies) from {$start} to {$end}.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to process offer response: ' . dbgMsg($e), 500);
    }
}

function handleReservationConvert(): void {
    global $pdo;
    apiRequireStaff();

    $resId = (int)($_POST['id'] ?? 0);
    $dueAt = cleanValue($_POST['due_at'] ?? '');
    if (!$resId) sendError('Reservation ID required.');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM book_reservations WHERE id = ? FOR UPDATE');
        $stmt->execute([$resId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$res) { $pdo->rollBack(); sendError('Reservation not found.', 404); }
        if (!in_array($res['status'], ['confirmed', 'ready'], true)) {
            $pdo->rollBack();
            sendError('Only confirmed/ready reservations can be converted to a borrow.');
        }

        $bookId = (int)$res['book_id'];
        $qty    = (int)$res['quantity'];

        $lock = $pdo->prepare('SELECT quantity_available, title FROM books WHERE id = ? FOR UPDATE');
        $lock->execute([$bookId]);
        $book = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$book) { $pdo->rollBack(); sendError('Book not found.', 404); }
        if ((int)$book['quantity_available'] < $qty) {
            $pdo->rollBack();
            sendError("Only {$book['quantity_available']} cop(ies) are physically on the shelf right now — cannot hand over {$qty}.");
        }

        // Borrower identity derives from the reserving ACCOUNT, not a name string
        $borrowerId = borrowerForUser($pdo, (int)$res['user_id']);

        $due = preg_match('/^\d{4}-\d{2}-\d{2}/', $dueAt) ? $dueAt : ($res['end_date'] . ' 23:59:59');

        $pdo->prepare(
            "INSERT INTO book_borrow_records
             (borrower_id, requested_by, status, borrow_type, requested_at, reviewed_by, reviewed_at, borrowed_at, due_at, reservation_id)
             VALUES (?,?, 'borrowed', 'outside', NOW(), ?, NOW(), NOW(), ?, ?)"
        )->execute([$borrowerId, (int)$res['user_id'], currentUserId(), $due, $resId]);
        $borrowId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO book_borrow_items (borrow_id, book_id, quantity) VALUES (?,?,?)')
            ->execute([$borrowId, $bookId, $qty]);
        $pdo->prepare('UPDATE books SET quantity_available = quantity_available - ?, updated_at = NOW() WHERE id = ?')
            ->execute([$qty, $bookId]);
        $pdo->prepare("UPDATE book_reservations SET status = 'fulfilled', borrow_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$borrowId, $resId]);

        logAudit($pdo, 'reservation_fulfilled', 'reservations', 'reservation', $resId,
            "Converted to borrow #{$borrowId}: {$qty} × \"{$book['title']}\", due {$due}");
        $pdo->commit();
        sendSuccess(['borrow_id' => $borrowId], "Pickup recorded — borrow #{$borrowId} created, due " . date('M j, Y', strtotime($due)) . '.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to convert reservation: ' . dbgMsg($e), 500);
    }
}

function handleCancelReservation(): void {
    global $pdo;
    apiRequireLogin();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $resId  = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $wlId   = (int)($input['waitlist_id'] ?? $_POST['waitlist_id'] ?? 0);
    $userId = (int)currentUserId();
    $isStaff = in_array($_SESSION['role'] ?? 'viewer', ['admin', 'staff'], true);

    if (!$resId && !$wlId) sendError('Reservation or waitlist ID required.');

    try {
        // Cancel a waitlist entry
        if ($wlId) {
            $stmt = $isStaff
                ? $pdo->prepare('SELECT * FROM book_waitlist WHERE id = ?')
                : $pdo->prepare('SELECT * FROM book_waitlist WHERE id = ? AND user_id = ?');
            $stmt->execute($isStaff ? [$wlId] : [$wlId, $userId]);
            $w = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$w) sendError('Waitlist entry not found.', 404);
            if (!in_array($w['status'], ['waiting', 'offered'], true)) sendError('Entry is no longer active.');

            $pdo->prepare("UPDATE book_waitlist SET status = 'cancelled' WHERE id = ?")->execute([$wlId]);
            if ($w['status'] === 'offered') resProcessWaitlist($pdo, (int)$w['book_id']);
            sendSuccess([], 'Removed from the waiting list.');
        }

        // Cancel a reservation (releases its capacity → process queue)
        $pdo->beginTransaction();
        $stmt = $isStaff
            ? $pdo->prepare('SELECT * FROM book_reservations WHERE id = ? FOR UPDATE')
            : $pdo->prepare('SELECT * FROM book_reservations WHERE id = ? AND user_id = ? FOR UPDATE');
        $stmt->execute($isStaff ? [$resId] : [$resId, $userId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$res) { $pdo->rollBack(); sendError('Reservation not found.', 404); }
        if (!in_array($res['status'], ['pending', 'confirmed', 'ready'], true)) {
            $pdo->rollBack();
            sendError('Only pending, confirmed or ready reservations can be cancelled.');
        }

        $pdo->prepare('SELECT id FROM books WHERE id = ? FOR UPDATE')->execute([(int)$res['book_id']]);
        $pdo->prepare("UPDATE book_reservations SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$resId]);
        logAudit($pdo, 'reservation_cancelled', 'reservations', 'reservation', $resId,
            "{$res['quantity']} cop(ies), {$res['start_date']} → {$res['end_date']}");
        resProcessWaitlist($pdo, (int)$res['book_id']);
        $pdo->commit();
        sendSuccess([], 'Reservation cancelled — copies released to the waiting list.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to cancel: ' . dbgMsg($e), 500);
    }
}

function handleNotifyNextInQueue(): void {
    global $pdo;
    apiRequireStaff();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookId = (int)($input['book_id'] ?? $_POST['book_id'] ?? 0);
    if (!$bookId) sendError('Book ID required.');

    try {
        $sent = resProcessWaitlist($pdo, $bookId);
        sendSuccess(['offers_sent' => $sent],
            $sent ? 'Offer sent to the next user in queue.' : 'No offer sent — queue empty, an offer is already active, or no capacity is free.');
    } catch (Throwable $e) {
        sendError('Failed to process queue: ' . dbgMsg($e), 500);
    }
}

function handleExpireReservations(): void {
    global $pdo;
    apiRequireAdmin();

    try {
        resMaintenanceSweep($pdo);
        sendSuccess([], 'Reservation lifecycle sweep completed.');
    } catch (Throwable $e) {
        sendError('Failed to run sweep: ' . dbgMsg($e), 500);
    }
}
// Fix 2: handleBookReports() was in the switch but never defined
function handleBookReports(): void {
    global $pdo;
    try {
        $from  = cleanValue($_GET['from'] ?? '');
        $to    = cleanValue($_GET['to']   ?? '');
        $format = cleanValue($_GET['format'] ?? 'json');

        $conditions = [];
        $params     = [];

        if ($from !== '') { $conditions[] = 'DATE(r.requested_at) >= ?'; $params[] = $from; }
        if ($to   !== '') { $conditions[] = 'DATE(r.requested_at) <= ?'; $params[] = $to;   }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $summary = $pdo->query(
            'SELECT
               COALESCE(SUM(b.quantity_total),     0) AS total_titles,
               COALESCE(SUM(b.quantity_available), 0) AS available_copies
             FROM books b'
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $borrowCount = $pdo->query(
            "SELECT COUNT(*) FROM book_borrow_records WHERE status IN ('borrowed','returned')"
        )->fetchColumn();

        $topStmt = $pdo->prepare(
            "SELECT bk.title, COUNT(*) AS borrow_count
             FROM book_borrow_items i
             JOIN book_borrow_records r ON r.id = i.borrow_id
             JOIN books bk ON bk.id = i.book_id
             {$where}
             GROUP BY i.book_id
             ORDER BY borrow_count DESC
             LIMIT 10"
        );
        $topStmt->execute($params);
        $topBooks = $topStmt->fetchAll(PDO::FETCH_ASSOC);

        sendSuccess([
            'summary' => [
                'total_titles'    => (int)($summary['total_titles']    ?? 0),
                'available_copies'=> (int)($summary['available_copies'] ?? 0),
                'total_borrows'   => (int)$borrowCount,
                'most_borrowed'   => $topBooks,
            ],
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load book reports: ' . dbgMsg($e), 500);
    }
}

// Fix 3: handleBorrowersSearch() and handleBorrowersAdd() belong in library_handler,
//         not only in tnf_handler. These were missing from library_handler.php.

function handleBorrowersSearch(): void {
    global $pdo;
    apiRequireStaff();   // borrower registry is staff-only; LRN fields are admin-only
    $isAdmin = (($_SESSION['role'] ?? 'user') === 'admin');
    $query = cleanValue($_GET['q']    ?? '');
    $type  = cleanValue($_GET['type'] ?? '');

    $conditions = [];
    $params     = [];

    if ($query !== '') {
        if ($isAdmin) {
            // Admins may search by name OR LRN
            $conditions[] = '(LOWER(name) LIKE LOWER(?) OR (lrn IS NOT NULL AND lrn LIKE ?))';
            $params[] = '%' . $query . '%';
            $params[] = '%' . $query . '%';
        } else {
            // Staff may only search by name — LRN is a protected identifier
            $conditions[] = 'LOWER(name) LIKE LOWER(?)';
            $params[] = '%' . $query . '%';
        }
    }
    if ($type !== '') {
        $conditions[] = 'borrower_type = ?';
        $params[] = $type;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Admin sees all fields including LRN; staff sees name/contact/type only
    $selectCols = $isAdmin
        ? 'id, name, borrower_type, lrn, contact, contact_person, classification'
        : 'id, name, borrower_type, contact, contact_person, classification';

    try {
        $stmt = $pdo->prepare(
            "SELECT {$selectCols} FROM library_borrowers {$where} ORDER BY name ASC LIMIT 20"
        );
        $stmt->execute($params);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        // Fallback: minimal columns if extended ones don't exist yet — still respect role
        try {
            $fallbackCols = $isAdmin ? 'id, name, lrn, contact' : 'id, name, contact';
            $fallbackWhere = ($query !== '') ? 'WHERE LOWER(name) LIKE LOWER(?)' : '';
            $fallbackParams = ($query !== '') ? ['%' . $query . '%'] : [];
            $stmt = $pdo->prepare(
                "SELECT {$fallbackCols} FROM library_borrowers {$fallbackWhere} ORDER BY name ASC LIMIT 20"
            );
            $stmt->execute($fallbackParams);
            sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e2) {
            sendError('Failed to search borrowers: ' . dbgMsg($e2), 500);
        }
    }
}

function handleBorrowersAdd(): void {
    global $pdo;
    apiRequireStaff();   // only staff register walk-in borrowers

    $name          = cleanValue($_POST['name']           ?? '');
    $type          = in_array($_POST['borrower_type'] ?? '', ['school','individual'])
                        ? $_POST['borrower_type'] : 'individual';
    $lrn           = cleanValue($_POST['lrn']            ?? '');
    $contact       = cleanValue($_POST['contact']        ?? '');
    $contactPerson = cleanValue($_POST['contact_person'] ?? '');

    if ($name === '') {
        sendError('Borrower name is required.');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO library_borrowers (borrower_type, lrn, name, contact, contact_person)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               borrower_type  = VALUES(borrower_type),
               lrn            = VALUES(lrn),
               contact        = VALUES(contact),
               contact_person = VALUES(contact_person)'
        );
        $stmt->execute([
            $type,
            $lrn           ?: null,
            $name,
            $contact       ?: null,
            $contactPerson ?: null,
        ]);

        $id = (int) $pdo->lastInsertId();
        if (!$id) {
            $id = (int) $pdo->query(
                "SELECT id FROM library_borrowers WHERE name = " . $pdo->quote($name) . " LIMIT 1"
            )->fetchColumn();
        }

        sendSuccess(
            ['id' => $id, 'name' => $name, 'borrower_type' => $type],
            'Borrower saved.'
        );
    } catch (Throwable $e) {
        sendError('Failed to save borrower: ' . dbgMsg($e), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// AUDIT LOGGING HELPER
// ═══════════════════════════════════════════════════════════════

function logAudit(PDO $pdo, string $action, string $module, ?string $targetType = null, ?int $targetId = null, ?string $description = null): void {
    try {
        $userId = currentUserId();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, module, target_type, target_id, description, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$userId, $action, $module, $targetType, $targetId, $description, $ip]);
    } catch (Throwable) {}
}

function createAdminNotification(PDO $pdo, string $type, string $title, string $body, string $module, ?int $targetId = null): void {
    try {
        $pdo->prepare(
            "INSERT INTO admin_notifications (type, title, body, module, target_id)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$type, $title, $body, $module, $targetId]);
    } catch (Throwable) {}
}

// ═══════════════════════════════════════════════════════════════
// INTELLIGENT BOOK DISCOVERY — Google Books (primary) + Open Library (fallback)
// ═══════════════════════════════════════════════════════════════

/** Strip to [0-9X] and validate; returns ['isbn13'=>?, 'isbn10'=>?] (both derived). */
function isbnNormalize(string $raw): array {
    $s = strtoupper(preg_replace('/[^0-9Xx]/', '', $raw));
    $i13 = null; $i10 = null;
    if (strlen($s) === 13 && ctype_digit($s) && isbn13Valid($s)) { $i13 = $s; $i10 = isbn13to10($s); }
    elseif (strlen($s) === 10 && isbn10Valid($s))               { $i10 = $s; $i13 = isbn10to13($s); }
    return ['isbn13' => $i13, 'isbn10' => $i10];
}
function isbn13Valid(string $s): bool {
    if (!preg_match('/^\d{13}$/', $s)) return false;
    $sum = 0; for ($i = 0; $i < 12; $i++) $sum += (int)$s[$i] * ($i % 2 ? 3 : 1);
    return (10 - $sum % 10) % 10 === (int)$s[12];
}
function isbn10Valid(string $s): bool {
    if (!preg_match('/^\d{9}[\dX]$/', $s)) return false;
    $sum = 0; for ($i = 0; $i < 10; $i++) { $c = $s[$i] === 'X' ? 10 : (int)$s[$i]; $sum += $c * (10 - $i); }
    return $sum % 11 === 0;
}
function isbn10to13(string $s): ?string {
    if (!isbn10Valid($s)) return null;
    $core = '978' . substr($s, 0, 9);
    $sum = 0; for ($i = 0; $i < 12; $i++) $sum += (int)$core[$i] * ($i % 2 ? 3 : 1);
    return $core . ((10 - $sum % 10) % 10);
}
function isbn13to10(string $s): ?string {
    if (substr($s, 0, 3) !== '978') return null;
    $core = substr($s, 3, 9);
    $sum = 0; for ($i = 0; $i < 9; $i++) $sum += (int)$core[$i] * (10 - $i);
    $c = (11 - $sum % 11) % 11;
    return $core . ($c === 10 ? 'X' : (string)$c);
}

/** HTTP GET with timeout; returns [status:int, body:?string]. */
function discoveryHttpGet(string $url): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 8,
        'header' => "User-Agent: SDOLibrarySystem/1.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $status = (int)$m[1]; }
    return [$status ?: ($body === false ? 0 : 200), $body === false ? null : $body];
}

/** Completeness 0–100 used for conflict resolution + quality flagging. */
function discoveryScore(array $d): int {
    $w = ['title'=>15,'authors'=>15,'isbn13'=>20,'cover_url'=>15,'description'=>15,'page_count'=>5,'published_year'=>5,'categories'=>5,'publisher'=>5];
    $s = 0;
    foreach ($w as $k => $v) if (!empty($d[$k])) $s += $v;
    return min(100, $s);
}

function normGoogleVolume(array $item): ?array {
    $v = $item['volumeInfo'] ?? [];
    if (empty($v['title'])) return null;
    $i13 = null; $i10 = null;
    foreach ($v['industryIdentifiers'] ?? [] as $id) {
        if (($id['type'] ?? '') === 'ISBN_13') $i13 = preg_replace('/[^0-9]/', '', $id['identifier']);
        if (($id['type'] ?? '') === 'ISBN_10') $i10 = strtoupper(preg_replace('/[^0-9Xx]/', '', $id['identifier']));
    }
    if (!$i13 && $i10) $i13 = isbn10to13($i10);
    if (!$i10 && $i13) $i10 = isbn13to10($i13);
    $cover = $v['imageLinks']['thumbnail'] ?? $v['imageLinks']['smallThumbnail'] ?? null;
    if ($cover) $cover = str_replace(['http://', '&edge=curl'], ['https://', ''], $cover);
    $cats = [];
    foreach ($v['categories'] ?? [] as $c) foreach (preg_split('#[/,]#', $c) as $p) { $p = trim($p); if ($p !== '') $cats[$p] = true; }
    $d = [
        'isbn13' => $i13, 'isbn10' => $i10,
        'title' => trim($v['title']),
        'subtitle' => isset($v['subtitle']) ? trim($v['subtitle']) : null,
        'authors' => array_values($v['authors'] ?? []),
        'publisher' => $v['publisher'] ?? null,
        'published_year' => (preg_match('/\d{4}/', $v['publishedDate'] ?? '', $m) ? (int)$m[0] : null),
        'description' => isset($v['description']) ? mb_substr(trim($v['description']), 0, 1500) : null,
        'page_count' => isset($v['pageCount']) ? (int)$v['pageCount'] : null,
        'categories' => array_slice(array_keys($cats), 0, 5),
        'lang' => $v['language'] ?? null,
        'cover_url' => $cover,
        'series' => null, 'edition' => null,
        'book_format' => isset($v['printType']) ? ucfirst(strtolower($v['printType'])) : null,
        'reading_level' => (($v['maturityRating'] ?? '') === 'MATURE') ? 'Mature' : null,
        'lcc' => null, 'ddc' => null,
        'source' => 'google', 'source_id' => $item['id'] ?? null,
    ];
    $d['metadata_score'] = discoveryScore($d);
    return $d;
}

function normOpenLibraryDoc(array $doc): ?array {
    if (empty($doc['title'])) return null;
    $i13 = $doc['isbn'][0] ?? null;
    if ($i13) { $n = isbnNormalize($i13); $i13 = $n['isbn13']; $i10 = $n['isbn10']; } else { $i10 = null; }
    $cover = !empty($doc['cover_i']) ? "https://covers.openlibrary.org/b/id/{$doc['cover_i']}-M.jpg" : null;
    $d = [
        'isbn13' => $i13, 'isbn10' => $i10,
        'title' => trim($doc['title']),
        'subtitle' => isset($doc['subtitle']) ? trim($doc['subtitle']) : null,
        'authors' => array_values($doc['author_name'] ?? []),
        'publisher' => $doc['publisher'][0] ?? null,
        'published_year' => isset($doc['first_publish_year']) ? (int)$doc['first_publish_year'] : null,
        'description' => null,
        'page_count' => isset($doc['number_of_pages_median']) ? (int)$doc['number_of_pages_median'] : null,
        'categories' => array_slice($doc['subject'] ?? [], 0, 5),
        'lang' => $doc['language'][0] ?? null,
        'cover_url' => $cover,
        'series' => $doc['series'][0] ?? null,
        'edition' => null,
        'book_format' => null,
        'reading_level' => null,
        'lcc' => $doc['lcc'][0] ?? null,
        'ddc' => $doc['ddc'][0] ?? null,
        'source' => 'openlibrary', 'source_id' => $doc['key'] ?? null,
    ];
    $d['metadata_score'] = discoveryScore($d);
    return $d;
}

// Library of Congress — SRU returns MODS XML; rich on classification (LCC/DDC),
// authoritative for government/archival materials. No cover art (metadata only).
function normLocMods(SimpleXMLElement $m): ?array {
    $m->registerXPathNamespace('m', 'http://www.loc.gov/mods/v3');
    $x = fn($p) => (string)($m->xpath($p)[0] ?? '');
    $title = trim($x('m:titleInfo[not(@type)]/m:title') ?: $x('m:titleInfo/m:title'));
    if ($title === '') return null;
    $nonSort = trim($x('m:titleInfo[not(@type)]/m:nonSort') ?: $x('m:titleInfo/m:nonSort'));
    if ($nonSort !== '') $title = trim($nonSort . ' ' . $title);
    $authors = [];
    foreach ($m->xpath('m:name[@type="personal"]/m:namePart[not(@type) or @type="given" or @type="family"]') as $n) {
        $s = trim(rtrim(trim((string)$n), ', ')); if ($s !== '' && !preg_match('/^\d{4}/', $s)) $authors[] = $s;
    }
    $nrm = isbnNormalize($x('m:identifier[@type="isbn"]'));
    $year = preg_match('/\d{4}/', $x('m:originInfo/m:dateIssued'), $mm) ? (int)$mm[0] : null;
    $pages = preg_match('/(\d+)\s*(?:p|pages|leaves)/i', $x('m:physicalDescription/m:extent'), $pm) ? (int)$pm[1] : null;
    $subjects = [];
    foreach ($m->xpath('m:subject/m:topic') as $t) { $s = trim((string)$t); if ($s !== '') $subjects[] = $s; }
    $d = [
        'isbn13' => $nrm['isbn13'], 'isbn10' => $nrm['isbn10'], 'title' => $title,
        'subtitle' => $x('m:titleInfo/m:subTitle') ?: null,
        'authors' => array_values(array_unique($authors)),
        'publisher' => $x('m:originInfo/m:publisher') ?: null, 'published_year' => $year,
        'description' => $x('m:abstract') ? mb_substr(trim($x('m:abstract')), 0, 1500) : null,
        'page_count' => $pages, 'categories' => array_slice($subjects, 0, 5),
        'lang' => $x('m:language/m:languageTerm') ?: null, 'cover_url' => null,
        'series' => $x('m:relatedItem[@type="series"]/m:titleInfo/m:title') ?: null,
        'edition' => $x('m:originInfo/m:edition') ?: null, 'book_format' => null, 'reading_level' => null,
        'lcc' => $x('m:classification[@authority="lcc"]') ?: null,
        'ddc' => $x('m:classification[@authority="ddc"]') ?: null,
        'source' => 'loc', 'source_id' => $x('m:recordInfo/m:recordIdentifier') ?: null,
    ];
    $d['metadata_score'] = discoveryScore($d);
    return $d;
}

// Internet Archive — digitized texts; always has a cover thumbnail, good for
// out-of-print and archival materials.
function normInternetArchiveDoc(array $doc): ?array {
    $title = is_array($doc['title'] ?? null) ? ($doc['title'][0] ?? '') : ($doc['title'] ?? '');
    $title = trim((string)$title);
    if ($title === '') return null;
    $creator = $doc['creator'] ?? [];
    $authors = array_values(array_filter(array_map('trim', is_array($creator) ? $creator : [$creator])));
    $isbnRaw = '';
    foreach ((array)($doc['isbn'] ?? []) as $cand) { if (isbnNormalize((string)$cand)['isbn13']) { $isbnRaw = (string)$cand; break; } }
    $nrm = isbnNormalize($isbnRaw);
    $year = isset($doc['year']) ? (int)$doc['year']
          : (isset($doc['date']) && preg_match('/\d{4}/', (string)$doc['date'], $mm) ? (int)$mm[0] : null);
    $id = (string)($doc['identifier'] ?? '');
    $desc = $doc['description'] ?? null; if (is_array($desc)) $desc = $desc[0] ?? null;
    $d = [
        'isbn13' => $nrm['isbn13'], 'isbn10' => $nrm['isbn10'], 'title' => $title, 'subtitle' => null,
        'authors' => $authors,
        'publisher' => is_array($doc['publisher'] ?? null) ? ($doc['publisher'][0] ?? null) : ($doc['publisher'] ?? null),
        'published_year' => $year,
        'description' => $desc ? mb_substr(trim(strip_tags((string)$desc)), 0, 1500) : null,
        'page_count' => null, 'categories' => array_slice((array)($doc['subject'] ?? []), 0, 5),
        'lang' => is_array($doc['language'] ?? null) ? ($doc['language'][0] ?? null) : ($doc['language'] ?? null),
        'cover_url' => $id ? 'https://archive.org/services/img/' . rawurlencode($id) : null,
        'series' => null, 'edition' => null, 'book_format' => 'Digitized', 'reading_level' => null,
        'lcc' => null, 'ddc' => null, 'source' => 'archive', 'source_id' => $id ?: null,
    ];
    $d['metadata_score'] = discoveryScore($d);
    return $d;
}

function discoveryRateOk(PDO $pdo, string $provider): bool {
    $win = date('Y-m-d H:i:00');
    $pdo->prepare("INSERT INTO api_rate_buckets (provider, window_start, cnt) VALUES (?,?,1)
                   ON DUPLICATE KEY UPDATE cnt = cnt + 1")->execute([$provider, $win]);
    $cnt = (int)$pdo->query("SELECT cnt FROM api_rate_buckets WHERE provider=" . $pdo->quote($provider) . " AND window_start=" . $pdo->quote($win))->fetchColumn();
    return $cnt <= DISCOVERY_RATE_LIMIT;
}

function discoveryFetch(PDO $pdo, string $mode, string $q, int $page): array {
    // Google Books primary
    if (discoveryRateOk($pdo, 'google')) {
        $query = $mode === 'isbn' ? ('isbn:' . $q) : $q;
        $url = 'https://www.googleapis.com/books/v1/volumes?country=US&maxResults=20&startIndex=' . ($page * 20)
             . '&q=' . rawurlencode($query);
        [$st, $body] = discoveryHttpGet($url);
        if ($st === 200 && $body) {
            $j = json_decode($body, true);
            $out = [];
            foreach ($j['items'] ?? [] as $it) { $d = normGoogleVolume($it); if ($d) $out[] = $d; }
            if ($out || ($j['totalItems'] ?? 0) === 0) return ['results' => $out, 'source' => 'google', 'has_more' => count($out) === 20];
        }
    }
    // Open Library fallback
    if (discoveryRateOk($pdo, 'openlibrary')) {
        $url = $mode === 'isbn'
            ? ('https://openlibrary.org/search.json?limit=20&page=' . ($page + 1) . '&isbn=' . rawurlencode($q))
            : ('https://openlibrary.org/search.json?limit=20&page=' . ($page + 1) . '&q=' . rawurlencode($q));
        [$st, $body] = discoveryHttpGet($url);
        if ($st === 200 && $body) {
            $j = json_decode($body, true);
            $out = [];
            foreach ($j['docs'] ?? [] as $doc) { $d = normOpenLibraryDoc($doc); if ($d) $out[] = $d; }
            if ($out) return ['results' => $out, 'source' => 'openlibrary', 'has_more' => count($out) === 20];
        }
    }
    // Library of Congress (SRU / MODS) — strong for archival & government materials
    if (discoveryRateOk($pdo, 'loc')) {
        $cql = $mode === 'isbn' ? ('bath.isbn=' . $q) : ('bath.anywhere="' . str_replace('"', '', $q) . '"');
        $url = 'http://lx2.loc.gov:210/lcdb?version=1.1&operation=searchRetrieve&maximumRecords=10&recordSchema=mods&query=' . rawurlencode($cql);
        [$st, $body] = discoveryHttpGet($url);
        if ($st === 200 && $body) {
            // DOMDocument parses the SRU envelope reliably where simplexml_load_string
            // returns false; the imported SimpleXML can be "falsy" so we guard on $domOk.
            $prev = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $domOk = $dom->loadXML($body, LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors(); libxml_use_internal_errors($prev);
            if ($domOk) {
                $xml = simplexml_import_dom($dom);
                $xml->registerXPathNamespace('m', 'http://www.loc.gov/mods/v3');
                $out = [];
                foreach (($xml->xpath('//m:mods') ?: []) as $mods) { $d = normLocMods($mods); if ($d) $out[] = $d; }
                if ($out) return ['results' => $out, 'source' => 'loc', 'has_more' => false];
            }
        }
    }
    // Internet Archive (digitized texts) — last-resort coverage, always has a cover
    if (discoveryRateOk($pdo, 'archive')) {
        $inner = $mode === 'isbn' ? ('isbn:' . $q) : $q;
        $fl = '&fl[]=identifier&fl[]=title&fl[]=creator&fl[]=isbn&fl[]=year&fl[]=date&fl[]=publisher&fl[]=subject&fl[]=language&fl[]=description';
        $url = 'https://archive.org/advancedsearch.php?output=json&rows=20&page=' . ($page + 1) . $fl
             . '&q=' . rawurlencode('mediatype:texts AND (' . $inner . ')');
        [$st, $body] = discoveryHttpGet($url);
        if ($st === 200 && $body) {
            $j = json_decode($body, true);
            $out = [];
            foreach ($j['response']['docs'] ?? [] as $doc) { $d = normInternetArchiveDoc($doc); if ($d) $out[] = $d; }
            if ($out) return ['results' => $out, 'source' => 'archive', 'has_more' => count($out) === 20];
        }
    }
    return ['results' => [], 'source' => 'none', 'has_more' => false];
}

/** Flag results already in our catalog so the UI shows "In library" not "Add". */
function discoveryTagInLibrary(PDO $pdo, array &$results): void {
    $isbns = [];
    foreach ($results as $r) { if ($r['isbn13']) $isbns[] = $r['isbn13']; if ($r['isbn10']) $isbns[] = $r['isbn10']; }
    if (!$isbns) return;
    $ph = implode(',', array_fill(0, count($isbns), '?'));
    $stmt = $pdo->prepare("SELECT id, isbn13, isbn10, quantity_available FROM books WHERE isbn13 IN ($ph) OR isbn10 IN ($ph)");
    $stmt->execute(array_merge($isbns, $isbns));
    $byIsbn = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if ($b['isbn13']) $byIsbn[$b['isbn13']] = $b;
        if ($b['isbn10']) $byIsbn[$b['isbn10']] = $b;
    }
    foreach ($results as &$r) {
        $hit = ($r['isbn13'] && isset($byIsbn[$r['isbn13']])) ? $byIsbn[$r['isbn13']]
             : (($r['isbn10'] && isset($byIsbn[$r['isbn10']])) ? $byIsbn[$r['isbn10']] : null);
        $r['in_library'] = (bool)$hit;
        $r['book_id'] = $hit['id'] ?? null;
        $r['available'] = $hit ? (int)$hit['quantity_available'] : null;
    }
    unset($r);
}

function handleDiscoverySearch(): void {
    global $pdo;
    apiRequireStaff();

    $raw  = cleanValue($_GET['q'] ?? '');
    $page = max(0, (int)($_GET['page'] ?? 0));
    if (mb_strlen($raw) < 2) sendError('Enter at least 2 characters.');

    // Mode detection + query normalization
    $n = isbnNormalize($raw);
    $mode = ($n['isbn13'] || $n['isbn10']) ? 'isbn' : 'keyword';
    $normq = $mode === 'isbn' ? ($n['isbn13'] ?? $n['isbn10']) : mb_strtolower(preg_replace('/\s+/', ' ', $raw));

    $cacheKey = hash('sha256', "v1|$mode|$normq|$page");
    $stale = false;

    // 1) Fresh cache hit
    $c = $pdo->prepare("SELECT payload, expires_at FROM api_cache WHERE cache_key = ?");
    $c->execute([$cacheKey]);
    $cached = $c->fetch(PDO::FETCH_ASSOC);
    if ($cached && strtotime($cached['expires_at']) > time()) {
        $data = json_decode($cached['payload'], true) ?: ['results' => [], 'source' => 'cache', 'has_more' => false];
        discoveryTagInLibrary($pdo, $data['results']);
        sendSuccess($data + ['page' => $page, 'cached' => true]);
    }

    // 2) Live fetch (provider chain w/ rate limiting)
    try {
        $data = discoveryFetch($pdo, $mode, $normq, $page);
    } catch (Throwable $e) {
        $data = ['results' => [], 'source' => 'none', 'has_more' => false];
    }

    // 3) Fallback to stale cache if the live fetch came back empty/failed
    if (!$data['results'] && $cached) {
        $data = json_decode($cached['payload'], true) ?: $data;
        $stale = true;
    } elseif ($data['source'] !== 'none') {
        $ttl = $mode === 'isbn' ? '+7 days' : '+24 hours';
        $pdo->prepare("INSERT INTO api_cache (cache_key, provider, payload, http_status, expires_at)
                       VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload), expires_at=VALUES(expires_at), created_at=NOW()")
            ->execute([$cacheKey, $data['source'], json_encode($data), 200, date('Y-m-d H:i:s', strtotime($ttl))]);
    }

    discoveryTagInLibrary($pdo, $data['results']);
    sendSuccess($data + ['page' => $page, 'cached' => false, 'stale' => $stale]);
}

/** Upsert author/category dimension rows; returns id. */
function discoveryUpsertAuthor(PDO $pdo, string $name): int {
    $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
    $pdo->prepare("INSERT INTO authors (name, name_norm) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
        ->execute([$name, $norm]);
    $s = $pdo->prepare("SELECT id FROM authors WHERE name_norm = ?"); $s->execute([$norm]);
    return (int)$s->fetchColumn();
}
function discoveryUpsertCategory(PDO $pdo, string $name): int {
    $slug = substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(trim($name))), 0, 150);
    $pdo->prepare("INSERT INTO categories (name, slug) VALUES (?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)")
        ->execute([$name, $slug]);
    $s = $pdo->prepare("SELECT id FROM categories WHERE slug = ?"); $s->execute([$slug]);
    return (int)$s->fetchColumn();
}

function handleBookAddFromDiscovery(): void {
    global $pdo;
    apiRequireStaff();

    $isbn   = cleanValue($_POST['isbn13'] ?? $_POST['isbn'] ?? '');
    $source = in_array($_POST['source'] ?? '', ['google', 'openlibrary'], true) ? $_POST['source'] : 'google';
    $srcId  = cleanValue($_POST['source_id'] ?? '');
    $qty    = max(1, (int)($_POST['quantity'] ?? 1));

    // Server re-fetches authoritative metadata — never trusts client-sent fields
    $dto = null;
    if ($isbn !== '') {
        $r = discoveryFetch($pdo, 'isbn', isbnNormalize($isbn)['isbn13'] ?? $isbn, 0);
        $dto = $r['results'][0] ?? null;
    }
    if (!$dto && $srcId !== '' && $source === 'google') {
        [$st, $body] = discoveryHttpGet('https://www.googleapis.com/books/v1/volumes/' . rawurlencode($srcId) . '?country=US');
        if ($st === 200 && $body) $dto = normGoogleVolume(json_decode($body, true) ?: []);
    }
    if (!$dto) sendError('Could not retrieve book details. Please add it manually.', 422);

    try {
        $pdo->beginTransaction();

        // ── Duplicate prevention: ISBN13 → ISBN10 → fuzzy title/author ──
        $existing = null;
        if ($dto['isbn13']) { $s = $pdo->prepare("SELECT id FROM books WHERE isbn13=? LIMIT 1"); $s->execute([$dto['isbn13']]); $existing = $s->fetchColumn() ?: null; }
        if (!$existing && $dto['isbn10']) { $s = $pdo->prepare("SELECT id FROM books WHERE isbn10=? LIMIT 1"); $s->execute([$dto['isbn10']]); $existing = $s->fetchColumn() ?: null; }
        if (!$existing) {
            $s = $pdo->prepare("SELECT id FROM books WHERE LOWER(title)=LOWER(?) AND COALESCE(published_year,0) IN (?, 0) LIMIT 1");
            $s->execute([$dto['title'], $dto['published_year'] ?? 0]);
            $existing = $s->fetchColumn() ?: null;
        }

        $authorStr = implode(', ', $dto['authors']);
        if ($existing) {
            // Link: add copies + backfill any missing metadata (don't overwrite curated data)
            $pdo->prepare("UPDATE books SET quantity_total = quantity_total + ?, quantity_available = quantity_available + ?,
                           isbn13 = COALESCE(isbn13, ?), isbn10 = COALESCE(isbn10, ?), cover_url = COALESCE(cover_url, ?),
                           description = COALESCE(description, ?), page_count = COALESCE(page_count, ?),
                           published_year = COALESCE(published_year, ?), publisher = COALESCE(publisher, ?),
                           updated_at = NOW() WHERE id = ?")
                ->execute([$qty, $qty, $dto['isbn13'], $dto['isbn10'], $dto['cover_url'], $dto['description'],
                           $dto['page_count'], $dto['published_year'], $dto['publisher'], $existing]);
            $bookId = (int)$existing; $action = 'linked';
        } else {
            $pdo->prepare("INSERT INTO books
                (title, subtitle, author, isbn, isbn13, isbn10, subject, category, cover_url, description, page_count,
                 published_year, publisher, lang, series, edition, book_format, reading_level, lcc, ddc,
                 source, metadata_score, quantity_total, quantity_available, condition_status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'good')")
                ->execute([$dto['title'], $dto['subtitle'] ?? null, $authorStr ?: null, $dto['isbn13'] ?? $dto['isbn10'], $dto['isbn13'], $dto['isbn10'],
                           $dto['categories'][0] ?? null, $dto['categories'][0] ?? null, $dto['cover_url'], $dto['description'],
                           $dto['page_count'], $dto['published_year'], $dto['publisher'], $dto['lang'],
                           $dto['series'] ?? null, $dto['edition'] ?? null, $dto['book_format'] ?? null, $dto['reading_level'] ?? null,
                           $dto['lcc'] ?? null, $dto['ddc'] ?? null, $dto['source'], $dto['metadata_score'], $qty, $qty]);
            $bookId = (int)$pdo->lastInsertId(); $action = 'inserted';

            foreach ($dto['authors'] as $i => $an) {
                $aid = discoveryUpsertAuthor($pdo, $an);
                $pdo->prepare("INSERT IGNORE INTO book_authors (book_id, author_id, ord) VALUES (?,?,?)")->execute([$bookId, $aid, $i]);
            }
            foreach ($dto['categories'] as $cn) {
                $cid = discoveryUpsertCategory($pdo, $cn);
                $pdo->prepare("INSERT IGNORE INTO book_categories (book_id, category_id) VALUES (?,?)")->execute([$bookId, $cid]);
            }
        }

        if ($dto['source_id']) {
            $pdo->prepare("INSERT INTO book_external_ids (book_id, source, external_id, raw_json) VALUES (?,?,?,?)
                           ON DUPLICATE KEY UPDATE book_id=VALUES(book_id), fetched_at=NOW()")
                ->execute([$bookId, $dto['source'], $dto['source_id'], json_encode($dto)]);
        }
        // Queue recommendation enrichment (Phase 2 worker)
        $pdo->prepare("INSERT IGNORE INTO book_enrich_queue (book_id) VALUES (?)")->execute([$bookId]);

        $pdo->commit();
        logAudit($pdo, 'book_discovered_add', 'books', 'book', $bookId, "{$action}: \"{$dto['title']}\" (+{$qty})");
        sendSuccess(['book_id' => $bookId, 'action' => $action, 'duplicate' => $action === 'linked'],
            $action === 'linked' ? 'Already in catalog — added ' . $qty . ' copy(ies).' : 'Book added to the library.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to add book: ' . dbgMsg($e), 500);
    }
}

// ── Related-book intelligence engine (runs in cron, off the request path) ────

function upsertBookRelation(PDO $pdo, int $bookId, string $type, array $d, float $score): void {
    if (empty($d['isbn13'])) return;   // ISBN-keyed relations only (clean dedup + addable)
    $rel = $pdo->prepare('SELECT id FROM books WHERE isbn13 = ? OR isbn10 = ? LIMIT 1');
    $rel->execute([$d['isbn13'], $d['isbn10'] ?? null]);
    $relBookId = $rel->fetchColumn() ?: null;
    $pdo->prepare(
        "INSERT INTO book_relations (book_id, relation_type, related_isbn13, related_book_id, score, title, author, cover_url, source)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE score = GREATEST(score, VALUES(score)),
                related_book_id = COALESCE(VALUES(related_book_id), related_book_id),
                title = VALUES(title), author = VALUES(author), cover_url = VALUES(cover_url)"
    )->execute([$bookId, $type, $d['isbn13'], $relBookId, $score, $d['title'],
                implode(', ', $d['authors'] ?? []), $d['cover_url'] ?? null, $d['source'] ?? null]);
}

/** Build relations for one book: alt editions, same author, same category. */
function enrichBookRelations(PDO $pdo, int $bookId): int {
    $s = $pdo->prepare('SELECT title, author, isbn13, isbn10 FROM books WHERE id = ? LIMIT 1');
    $s->execute([$bookId]);
    $b = $s->fetch(PDO::FETCH_ASSOC);
    if (!$b) return 0;

    $anchorTitle = mb_strtolower(trim($b['title']));
    $anchorIsbns = array_values(array_filter([$b['isbn13'], $b['isbn10']]));
    $authors = $b['author'] ? array_map('trim', explode(',', $b['author'])) : [];
    $primary = $authors[0] ?? '';
    $cat = $pdo->prepare("SELECT c.name FROM book_categories bc JOIN categories c ON c.id = bc.category_id WHERE bc.book_id = ? LIMIT 1");
    $cat->execute([$bookId]);
    $category = $cat->fetchColumn() ?: '';

    $n = 0;
    // Same author (and alt editions, classified by title match)
    if ($primary !== '') {
        $r = discoveryFetch($pdo, 'keyword', 'inauthor:"' . $primary . '"', 0);
        foreach ($r['results'] as $d) {
            if (empty($d['isbn13']) || in_array($d['isbn13'], $anchorIsbns, true)) continue;
            $sim = 0; similar_text($anchorTitle, mb_strtolower($d['title']), $sim);
            $isAlt = $sim >= 80;
            upsertBookRelation($pdo, $bookId, $isAlt ? 'alt_edition' : 'same_author', $d, $isAlt ? 1.0 : 0.9);
            $n++;
        }
    }
    // Same category
    if ($category !== '') {
        $r = discoveryFetch($pdo, 'keyword', 'subject:"' . $category . '"', 0);
        foreach ($r['results'] as $d) {
            if (empty($d['isbn13']) || in_array($d['isbn13'], $anchorIsbns, true)) continue;
            if (mb_strtolower($d['title']) === $anchorTitle) continue;
            upsertBookRelation($pdo, $bookId, 'same_category', $d, 0.6);
            $n++;
        }
    }
    return $n;
}

/** Drain the enrichment queue (called by cron.php and the admin endpoint). */
function discoveryEnrichDrain(PDO $pdo, int $limit = 5): int {
    $rows = $pdo->query("SELECT book_id FROM book_enrich_queue WHERE status IN ('pending','failed') AND attempts < 3 ORDER BY created_at ASC LIMIT $limit")
        ->fetchAll(PDO::FETCH_COLUMN);
    $done = 0;
    foreach ($rows as $bid) {
        $pdo->prepare("UPDATE book_enrich_queue SET status='running', attempts = attempts + 1 WHERE book_id = ?")->execute([$bid]);
        try {
            enrichBookRelations($pdo, (int)$bid);
            $pdo->prepare("UPDATE book_enrich_queue SET status='done' WHERE book_id = ?")->execute([$bid]);
            $done++;
        } catch (Throwable $e) {
            error_log('[library_sys] enrich ' . $bid . ': ' . $e->getMessage());
            $pdo->prepare("UPDATE book_enrich_queue SET status='failed' WHERE book_id = ?")->execute([$bid]);
        }
    }
    return $done;
}

function handleEnrichDrain(): void {
    global $pdo;
    apiRequireAdmin();
    try { sendSuccess(['processed' => discoveryEnrichDrain($pdo, (int)($_POST['limit'] ?? 10))], 'Enrichment run complete.'); }
    catch (Throwable $e) { sendError('Enrichment failed: ' . dbgMsg($e), 500); }
}

function handleBookSimilar(): void {
    global $pdo;
    apiRequireLogin();
    $bookId = (int)($_GET['book_id'] ?? 0);
    if (!$bookId) sendError('Missing book id.');
    try {
        $stmt = $pdo->prepare(
            "SELECT r.relation_type, r.related_isbn13, r.related_book_id, r.score, r.title, r.author, r.cover_url, r.source,
                    b.quantity_available
             FROM book_relations r
             LEFT JOIN books b ON b.id = r.related_book_id
             WHERE r.book_id = ?
             ORDER BY FIELD(r.relation_type,'alt_edition','same_author','same_category','similar_title','recommended'), r.score DESC
             LIMIT 40"
        );
        $stmt->execute([$bookId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['in_library'] = !empty($r['related_book_id']); }
        unset($r);
        sendSuccess(['relations' => $rows]);
    } catch (Throwable $e) {
        sendError('Failed to load similar books: ' . dbgMsg($e), 500);
    }
}

// ── Favorites / bookmarks (work before and after a book is in the catalog) ───

function handleFavoriteToggle(): void {
    global $pdo;
    apiRequireLogin();
    $uid = (int)currentUserId();
    $isbn13 = preg_replace('/[^0-9]/', '', $_POST['isbn13'] ?? '');
    if (strlen($isbn13) !== 13) sendError('A valid ISBN-13 is required to bookmark.');
    try {
        $ex = $pdo->prepare('SELECT 1 FROM favorites WHERE user_id = ? AND isbn13 = ?');
        $ex->execute([$uid, $isbn13]);
        if ($ex->fetchColumn()) {
            $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND isbn13 = ?')->execute([$uid, $isbn13]);
            sendSuccess(['favorited' => false], 'Removed from bookmarks.');
        }
        $bid = $pdo->prepare('SELECT id FROM books WHERE isbn13 = ? LIMIT 1'); $bid->execute([$isbn13]);
        $pdo->prepare("INSERT INTO favorites (user_id, isbn13, book_id, title, author, cover_url, source)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$uid, $isbn13, $bid->fetchColumn() ?: null,
                       cleanValue($_POST['title'] ?? ''), cleanValue($_POST['author'] ?? ''),
                       cleanValue($_POST['cover_url'] ?? ''), cleanValue($_POST['source'] ?? '')]);
        sendSuccess(['favorited' => true], 'Bookmarked.');
    } catch (Throwable $e) {
        sendError('Failed to update bookmark: ' . dbgMsg($e), 500);
    }
}

function handleFavoritesGet(): void {
    global $pdo;
    apiRequireLogin();
    $uid = (int)currentUserId();
    try {
        $stmt = $pdo->prepare('SELECT isbn13, book_id, title, author, cover_url, source, created_at FROM favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$uid]);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load bookmarks: ' . dbgMsg($e), 500);
    }
}

// ── Catalog enrichment for EXISTING books ────────────────────────────────────
// Backfills cover/description/subjects/etc. for books added before discovery
// existed. Matches by ISBN (exact) or title+author (fuzzy, guarded), only fills
// blanks (never overwrites curated data), then queues relation enrichment.

function discoveryBackfillBooks(PDO $pdo, int $limit = 8): array {
    $rows = $pdo->query(
        "SELECT id, title, author, isbn, isbn13, isbn10 FROM books
         WHERE COALESCE(is_archived,0) = 0 AND (cover_url IS NULL OR cover_url = '' OR description IS NULL OR description = '')
         ORDER BY (isbn13 IS NULL AND isbn IS NULL), id ASC
         LIMIT " . max(1, $limit)
    )->fetchAll(PDO::FETCH_ASSOC);

    $scanned = 0; $enriched = 0;
    foreach ($rows as $b) {
        $scanned++;
        $isbn = $b['isbn13'] ?: ($b['isbn'] ? (isbnNormalize($b['isbn'])['isbn13'] ?? '') : '');
        try {
            if ($isbn) {
                $r = discoveryFetch($pdo, 'isbn', $isbn, 0);
                $dto = $r['results'][0] ?? null;
            } else {
                $r = discoveryFetch($pdo, 'keyword', trim($b['title'] . ' ' . ($b['author'] ?? '')), 0);
                $dto = null;
                // Require a strong title match so we never attach the wrong cover
                foreach ($r['results'] as $cand) {
                    $sim = 0; similar_text(mb_strtolower($b['title']), mb_strtolower($cand['title']), $sim);
                    if ($sim >= 80) { $dto = $cand; break; }
                }
            }
        } catch (Throwable) { $dto = null; }
        if (!$dto) continue;

        $pdo->prepare(
            "UPDATE books SET
                cover_url = COALESCE(NULLIF(cover_url,''), ?), description = COALESCE(NULLIF(description,''), ?),
                page_count = COALESCE(page_count, ?), published_year = COALESCE(published_year, ?),
                publisher = COALESCE(NULLIF(publisher,''), ?), isbn13 = COALESCE(isbn13, ?), isbn10 = COALESCE(isbn10, ?),
                lang = COALESCE(NULLIF(lang,''), ?), subtitle = COALESCE(NULLIF(subtitle,''), ?),
                series = COALESCE(NULLIF(series,''), ?), book_format = COALESCE(NULLIF(book_format,''), ?),
                lcc = COALESCE(NULLIF(lcc,''), ?), ddc = COALESCE(NULLIF(ddc,''), ?),
                source = COALESCE(NULLIF(source,''), ?),
                metadata_score = GREATEST(COALESCE(metadata_score,0), ?), updated_at = NOW()
             WHERE id = ?"
        )->execute([$dto['cover_url'], $dto['description'], $dto['page_count'], $dto['published_year'],
                    $dto['publisher'], $dto['isbn13'], $dto['isbn10'], $dto['lang'], $dto['subtitle'] ?? null,
                    $dto['series'] ?? null, $dto['book_format'] ?? null, $dto['lcc'] ?? null, $dto['ddc'] ?? null,
                    $dto['source'], $dto['metadata_score'], $b['id']]);

        foreach ($dto['categories'] ?? [] as $cn) {
            $cid = discoveryUpsertCategory($pdo, $cn);
            $pdo->prepare("INSERT IGNORE INTO book_categories (book_id, category_id) VALUES (?,?)")->execute([$b['id'], $cid]);
        }
        $pdo->prepare("INSERT IGNORE INTO book_enrich_queue (book_id) VALUES (?)")->execute([$b['id']]);
        $enriched++;
    }
    return ['scanned' => $scanned, 'enriched' => $enriched];
}

function handleBookBackfill(): void {
    global $pdo;
    apiRequireAdmin();
    try {
        $limit = min(50, max(1, (int)($_POST['limit'] ?? 12)));
        $res = discoveryBackfillBooks($pdo, $limit);
        $remaining = (int)$pdo->query("SELECT COUNT(*) FROM books WHERE COALESCE(is_archived,0)=0 AND (cover_url IS NULL OR cover_url='' OR description IS NULL OR description='')")->fetchColumn();
        sendSuccess($res + ['remaining' => $remaining],
            "Enriched {$res['enriched']} of {$res['scanned']} book(s). {$remaining} still need metadata.");
    } catch (Throwable $e) {
        sendError('Backfill failed: ' . dbgMsg($e), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// SECURE FILE SERVING — documents & delivery attachments (auth required)
// ═══════════════════════════════════════════════════════════════
// Files live under storage/ which is denied direct web access at the Apache
// level. They are reachable only through this endpoint, which (a) requires a
// login, (b) only serves paths that belong to a real DB record (no traversal /
// arbitrary reads), and (c) restricts delivery files to staff.

function handleFileServe(): void {
    global $pdo;
    apiRequireLogin();

    $ref = ltrim(str_replace(['..', '\\'], ['', '/'], (string)($_GET['ref'] ?? '')), '/');
    $download = ($_GET['download'] ?? '') === '1';
    if (!str_starts_with($ref, 'storage/attachments/')) { http_response_code(404); exit('Not found.'); }

    $isStaff = in_array($_SESSION['role'] ?? 'viewer', ['admin', 'staff'], true);
    $name = null;

    // DB allowlist: only known file_path values are serveable
    $d = $pdo->prepare("SELECT file_name FROM delivery_documents WHERE file_path = ? LIMIT 1");
    $d->execute([$ref]);
    if ($row = $d->fetch(PDO::FETCH_ASSOC)) {
        if (!$isStaff) { http_response_code(403); exit('Forbidden.'); }   // deliveries are staff-only
        $name = $row['file_name'];
    }
    if ($name === null) {
        $q = $pdo->prepare("SELECT file_name FROM library_documents WHERE file_path = ? AND COALESCE(is_deleted,0) = 0 LIMIT 1");
        $q->execute([$ref]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) $name = $row['file_name'];
    }
    if ($name === null) {
        $q = $pdo->prepare("SELECT file_name FROM library_document_versions WHERE file_path = ? LIMIT 1");
        $q->execute([$ref]);
        if ($row = $q->fetch(PDO::FETCH_ASSOC)) $name = $row['file_name'];
    }
    if ($name === null) { http_response_code(404); exit('Not found.'); }

    // Resolve + confine to the attachments dir (defence in depth against traversal)
    $real = realpath(__DIR__ . '/../' . $ref);
    $base = realpath(__DIR__ . '/../storage/attachments');
    if ($real === false || $base === false || !str_starts_with($real, $base) || !is_file($real)) {
        http_response_code(404); exit('File missing.');
    }

    $ext = strtolower(pathinfo($name ?: $ref, PATHINFO_EXTENSION));
    $inlineOk = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'txt'], true);
    $mime = [
        'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'txt' => 'text/plain',
        'csv' => 'text/csv', 'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ][$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($real));
    header('Content-Disposition: ' . (($download || !$inlineOk) ? 'attachment' : 'inline') . '; filename="' . rawurlencode($name ?: basename($ref)) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($real);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// ISBN LOOKUP — Open Library API
// ═══════════════════════════════════════════════════════════════

function handleIsbnLookup(): void {
    apiRequireLogin();
    $isbn = preg_replace('/[^0-9Xx]/', '', $_GET['isbn'] ?? '');
    $isbn = strtoupper($isbn);
    if (strlen($isbn) < 10) {
        sendError('Please provide a valid ISBN-10 or ISBN-13.');
    }

    $url = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&format=json&jscmd=data";
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'method'  => 'GET',
        'header'  => "User-Agent: SDOLibrarySystem/1.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        sendError('Could not reach Open Library API. Please enter book details manually.');
    }

    $json = json_decode($raw, true);
    $key  = "ISBN:{$isbn}";
    if (empty($json[$key])) {
        sendError('No book found for this ISBN. Please enter details manually.');
    }

    $book      = $json[$key];
    $authors   = implode(', ', array_column($book['authors'] ?? [], 'name'));
    $subjects  = implode(', ', array_slice(
        array_map(fn($s) => is_string($s) ? $s : ($s['name'] ?? ''), $book['subjects'] ?? []), 0, 5
    ));
    $cover     = $book['cover']['medium'] ?? $book['cover']['small'] ?? null;

    $year = 0;
    if (!empty($book['publish_date'])) {
        preg_match('/\d{4}/', $book['publish_date'], $m);
        $year = (int)($m[0] ?? 0);
    }

    $publisher = '';
    if (!empty($book['publishers'])) {
        $p = $book['publishers'][0];
        $publisher = is_array($p) ? ($p['name'] ?? '') : $p;
    }

    $description = '';
    if (!empty($book['excerpts'][0]['text'])) {
        $description = substr($book['excerpts'][0]['text'], 0, 500);
    }

    sendSuccess([
        'title'        => trim($book['title'] ?? ''),
        'author'       => $authors,
        'publisher'    => $publisher,
        'publish_year' => $year ?: null,
        'subjects'     => $subjects,
        'description'  => $description,
        'cover_url'    => $cover,
        'isbn'         => $isbn,
    ]);
}

// ═══════════════════════════════════════════════════════════════
// AUDIT LOGS — View
// ═══════════════════════════════════════════════════════════════

function handleAuditLogsGet(): void {
    global $pdo;
    apiRequireAdmin();

    $limit  = min(200, max(10, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $module = cleanValue($_GET['module'] ?? '');
    $search = cleanValue($_GET['q'] ?? '');

    $conditions = [];
    $params     = [];
    if ($module) { $conditions[] = 'a.module = ?';               $params[] = $module; }
    if ($search) { $conditions[] = '(a.action LIKE ? OR a.description LIKE ? OR u.full_name LIKE ?)';
                   $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $stmt = $pdo->prepare(
        "SELECT a.*, u.full_name AS user_name
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.user_id
         {$where}
         ORDER BY a.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ═══════════════════════════════════════════════════════════════
// SETTINGS
// ═══════════════════════════════════════════════════════════════

function handleSettingsGet(): void {
    global $pdo;
    apiRequireAdmin();

    $stmt = $pdo->query('SELECT setting_key, setting_value FROM library_settings');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    sendSuccess($settings);
}

function handleSettingsSave(): void {
    global $pdo;
    apiRequireAdmin();

    $data = $_POST['settings'] ?? [];
    if (!is_array($data) || empty($data)) {
        sendError('No settings provided.');
    }

    $allowed = [
        'fine_per_day', 'max_borrow_days', 'max_books_per_borrow',
        'library_name', 'library_address', 'library_contact',
        'sms_enabled', 'sms_api_key', 'sms_sender_name',
        'sms_overdue_message', 'sms_borrow_message',
        'auto_fine_enabled', 'reservation_expiry_days',
        'notify_borrow', 'notify_overdue',
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO library_settings (setting_key, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );

    try {
        $pdo->beginTransaction();
        foreach ($data as $key => $val) {
            if (!in_array($key, $allowed, true)) continue;
            $stmt->execute([trim($key), trim((string)$val)]);
        }
        logAudit($pdo, 'settings_updated', 'settings', null, null, 'System settings updated.');
        $pdo->commit();
        sendSuccess([], 'Settings saved successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to save settings: ' . dbgMsg($e), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// BORROWER DASHBOARD
// ═══════════════════════════════════════════════════════════════

function handleBorrowersGet(): void {
    global $pdo;
    apiRequireAdmin();

    $search = cleanValue($_GET['q'] ?? '');
    $type   = cleanValue($_GET['type'] ?? '');

    $conditions = [];
    $params     = [];
    if ($search) {
        $conditions[] = "(b.name LIKE ? OR b.lrn LIKE ? OR b.contact LIKE ? OR b.email LIKE ?)";
        $like = "%$search%";
        $params = array_merge($params, [$like, $like, $like, $like]);
    }
    if ($type) {
        $conditions[] = "b.borrower_type = ?";
        $params[] = $type;
    }
    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Pagination — the default page size 200 preserves the previous hard cap, so
    // existing callers are unaffected; pass page/per_page to walk larger lists.
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min((int) ($_GET['per_page'] ?? 200), 200));
    $offset  = ($page - 1) * $perPage;

    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM library_borrowers b {$where}");
        $cnt->execute($params);
        $total = (int) $cnt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT b.id, b.name, b.borrower_type, b.lrn, b.contact, b.contact_person,
                    b.classification, b.email, b.address, b.created_at, b.updated_at,
                    COUNT(DISTINCT r.id) AS total_borrows,
                    SUM(CASE WHEN r.status = 'borrowed' THEN 1 ELSE 0 END) AS active_borrows,
                    SUM(CASE WHEN r.status = 'borrowed' AND r.due_at IS NOT NULL AND r.due_at < NOW() THEN 1 ELSE 0 END) AS overdue_count,
                    COALESCE(SUM(r.fine_amount), 0) AS total_fines
             FROM library_borrowers b
             LEFT JOIN book_borrow_records r ON r.borrower_id = b.id
             {$where}
             GROUP BY b.id
             ORDER BY b.name ASC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC), 'Success', ['meta' => [
            'page' => $page, 'per_page' => $perPage, 'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]]);
    } catch (Throwable $e) {
        sendError('Failed to load borrowers: ' . dbgMsg($e), 500);
    }
}

function handleBorrowerProfile(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) sendError('Borrower ID required.');

    $stmt = $pdo->prepare('SELECT * FROM library_borrowers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $borrower = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$borrower) sendError('Borrower not found.', 404);

    $hist = $pdo->prepare(
        "SELECT r.id, r.status, r.requested_at, r.borrowed_at, r.due_at, r.returned_at,
                r.fine_amount, r.borrow_type,
                GROUP_CONCAT(bk.title SEPARATOR ', ') AS books_list,
                SUM(i.quantity) AS total_qty
         FROM book_borrow_records r
         LEFT JOIN book_borrow_items i ON i.borrow_id = r.id
         LEFT JOIN books bk ON bk.id = i.book_id
         WHERE r.borrower_id = ?
         GROUP BY r.id
         ORDER BY r.requested_at DESC
         LIMIT 50"
    );
    $hist->execute([$id]);

    sendSuccess(['borrower' => $borrower, 'history' => $hist->fetchAll(PDO::FETCH_ASSOC)]);
}

function handleBorrowersUpdate(): void {
    global $pdo;
    apiRequireAdmin();

    $id             = (int)($_POST['id'] ?? 0);
    $contact        = cleanValue($_POST['contact'] ?? '');
    $email          = cleanValue($_POST['email'] ?? '');
    $address        = cleanValue($_POST['address'] ?? '');
    $classification = cleanValue($_POST['classification'] ?? '');
    $notes          = cleanValue($_POST['notes'] ?? '');

    if (!$id) sendError('Borrower ID required.');

    try {
        $pdo->prepare(
            "UPDATE library_borrowers
             SET contact = ?, email = ?, address = ?, classification = ?, updated_at = NOW()
             WHERE id = ?"
        )->execute([$contact ?: null, $email ?: null, $address ?: null,
                    $classification ?: null, $id]);
        logAudit($pdo, 'borrower_updated', 'members', 'borrower', $id, 'Borrower profile updated.');
        sendSuccess([], 'Borrower updated.');
    } catch (Throwable $e) {
        sendError('Failed to update borrower: ' . dbgMsg($e), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// ADMIN NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════

function handleNotificationsGet(): void {
    global $pdo;
    apiRequireAdmin();

    $unreadOnly = (($_GET['unread_only'] ?? '0') === '1');
    $limit = min(50, max(10, (int)($_GET['limit'] ?? 30)));

    $where = $unreadOnly ? 'WHERE is_read = 0' : '';
    $stmt  = $pdo->prepare(
        "SELECT * FROM admin_notifications {$where} ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0"
    )->fetchColumn();

    sendSuccess(['items' => $rows, 'unread_count' => $unreadCount]);
}

function handleNotificationsMarkRead(): void {
    global $pdo;
    apiRequireAdmin();

    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?")->execute([$id]);
    } else {
        $pdo->exec("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
    }
    sendSuccess([], 'Notifications marked as read.');
}

// ═══════════════════════════════════════════════════════════════
// FINE CALCULATOR
// ═══════════════════════════════════════════════════════════════

function handleCalculateFine(): void {
    global $pdo;
    apiRequireLogin();

    $borrowId = (int)($_GET['id'] ?? 0);
    if (!$borrowId) sendError('Borrow ID required.');

    $rate = (float)($pdo->query(
        "SELECT setting_value FROM library_settings WHERE setting_key = 'fine_per_day'"
    )->fetchColumn() ?: 5);

    $stmt = $pdo->prepare("SELECT status, due_at FROM book_borrow_records WHERE id = ? LIMIT 1");
    $stmt->execute([$borrowId]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$record) sendError('Record not found.', 404);

    $fine = 0.0;
    $overdueDays = 0;
    if ($record['status'] === 'borrowed') {
        $calc = \Lib\Fines::calculate($record['due_at'] ?? null, new DateTime(), $rate);
        $overdueDays = $calc['overdue_days'];
        $fine = $calc['fine_amount'];
    }

    sendSuccess([
        'fine_amount'  => round($fine, 2),
        'overdue_days' => $overdueDays,
        'rate_per_day' => $rate,
        'due_at'       => $record['due_at'],
        'status'       => $record['status'],
    ]);
}

// ═══════════════════════════════════════════════════════════════
// CATEGORY STATS (for Available Books breakdown)
// ═══════════════════════════════════════════════════════════════

function handleCategoryStats(): void {
    global $pdo;

    try {
        $stmt = $pdo->query(
            "SELECT
                COALESCE(NULLIF(TRIM(subject), ''), 'Uncategorized') AS category,
                COUNT(*)                    AS book_titles,
                COALESCE(SUM(quantity_total),     0) AS total_qty,
                COALESCE(SUM(quantity_available), 0) AS available_qty,
                COALESCE(SUM(quantity_damaged),   0) AS damaged_qty,
                COALESCE(SUM(quantity_missing),   0) AS missing_qty
             FROM books
             GROUP BY COALESCE(NULLIF(TRIM(subject), ''), 'Uncategorized')
             ORDER BY total_qty DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total     = (int)array_sum(array_column($rows, 'total_qty'));
        $available = (int)array_sum(array_column($rows, 'available_qty'));

        sendSuccess([
            'categories'    => $rows,
            'grand_total'   => $total,
            'grand_avail'   => $available,
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load category stats: ' . dbgMsg($e), 500);
    }
}

// ═══════════════════════════════════════════════════════════════
// SMS QUEUE HELPER (Semaphore-compatible)
// ═══════════════════════════════════════════════════════════════

function queueSms(PDO $pdo, string $phone, string $message, string $relatedType = '', int $relatedId = 0): void {
    $smsEnabled = ($pdo->query(
        "SELECT setting_value FROM library_settings WHERE setting_key = 'sms_enabled'"
    )->fetchColumn() === '1');
    if (!$smsEnabled) return;

    try {
        $pdo->prepare(
            "INSERT INTO sms_queue (phone, message, related_type, related_id)
             VALUES (?, ?, ?, ?)"
        )->execute([$phone, $message, $relatedType ?: null, $relatedId ?: null]);
    } catch (Throwable) {}
}

function sendSmsNow(PDO $pdo, string $phone, string $message): bool {
    $apiKey = trim($pdo->query(
        "SELECT setting_value FROM library_settings WHERE setting_key = 'sms_api_key'"
    )->fetchColumn() ?: '');
    $sender = trim($pdo->query(
        "SELECT setting_value FROM library_settings WHERE setting_key = 'sms_sender_name'"
    )->fetchColumn() ?: 'LIBRARY');

    if (!$apiKey) return false;

    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (str_starts_with($phone, '0')) $phone = '+63' . substr($phone, 1);

    $payload = http_build_query([
        'apikey'  => $apiKey,
        'number'  => $phone,
        'message' => $message,
        'sendername' => $sender,
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $payload,
        'timeout' => 8,
    ]]);
    $result = @file_get_contents('https://api.semaphore.co/api/v4/messages', false, $ctx);
    return $result !== false;
}

// ══════════════════════════════════════════════════════════════════════════════
// SETTINGS MODULE — User Management
// ══════════════════════════════════════════════════════════════════════════════

function handleUsersList(): void {
    global $pdo;
    apiRequireAdmin();
    try {
        $stmt = $pdo->query(
            "SELECT id, username, full_name, role, is_active, classification, contact, created_at
             FROM users ORDER BY created_at DESC"
        );
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load users: ' . dbgMsg($e), 500);
    }
}

function handleUsersCreate(): void {
    global $pdo;
    apiRequireAdmin();
    $name  = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pw    = $_POST['password'] ?? '';
    $allowedRoles = ['admin','staff','viewer'];
    $allowedCls   = ['child','teen','individual','deped','school','professional','private_institution'];
    $role  = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'viewer';
    $cls   = in_array($_POST['classification'] ?? '', $allowedCls, true) ? $_POST['classification'] : 'individual';
    $active = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;

    if (!$name)  sendError('Full name is required.');
    if (!$email) sendError('Email is required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) sendError('Invalid email address.');
    if (strlen($pw) < 8) sendError('Password must be at least 8 characters.');

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check->execute([$email]);
        if ($check->fetchColumn()) sendError('A user with that email already exists.');

        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, role, classification, is_active, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'approved', NOW())"
        )->execute([$email, password_hash($pw, PASSWORD_BCRYPT), $name, $role, $cls, $active]);

        $newId = (int) $pdo->lastInsertId();
        logAudit($pdo, 'create_user', 'users', 'user', $newId, "Admin created user: {$name} ({$email})");
        sendSuccess(['id' => $newId], 'User created successfully.');
    } catch (Throwable $e) {
        sendError('Failed to create user: ' . dbgMsg($e), 500);
    }
}

function handleUsersUpdate(): void {
    global $pdo;
    apiRequireAdmin();
    $id    = (int) ($_POST['id'] ?? 0);
    $name  = trim($_POST['full_name'] ?? '');
    $allowedRoles = ['admin','staff','viewer'];
    $allowedCls   = ['child','teen','individual','deped','school','professional','private_institution'];
    $role  = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'viewer';
    $cls   = in_array($_POST['classification'] ?? '', $allowedCls, true) ? $_POST['classification'] : 'individual';
    $active = ($_POST['is_active'] ?? '1') === '1' ? 1 : 0;
    $pw    = $_POST['password'] ?? '';

    if (!$id || !$name) sendError('User ID and name are required.');

    try {
        $sql    = "UPDATE users SET full_name=?, role=?, classification=?, is_active=?";
        $params = [$name, $role, $cls, $active];
        if ($pw !== '' && strlen($pw) >= 8) {
            $sql .= ', password=?';
            $params[] = password_hash($pw, PASSWORD_BCRYPT);
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        $pdo->prepare($sql)->execute($params);
        logAudit($pdo, 'update_user', 'users', 'user', $id, "Admin updated user #{$id}: {$name}");
        sendSuccess([], 'User updated successfully.');
    } catch (Throwable $e) {
        sendError('Failed to update user: ' . dbgMsg($e), 500);
    }
}

function handleUsersToggleStatus(): void {
    global $pdo;
    apiRequireAdmin();
    $id     = (int) ($_POST['id'] ?? 0);
    $active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
    if (!$id) sendError('User ID required.');
    if ($id === (int) currentUserId() && !$active) sendError('You cannot deactivate your own account.');
    try {
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$active, $id]);
        logAudit($pdo, $active ? 'activate_user' : 'deactivate_user', 'users', 'user', $id,
            ($active ? 'Activated' : 'Deactivated') . " user #{$id}");
        sendSuccess([], $active ? 'User activated.' : 'User deactivated.');
    } catch (Throwable $e) {
        sendError('Failed to update user status: ' . dbgMsg($e), 500);
    }
}

function handleUsersDelete(): void {
    global $pdo;
    apiRequireAdmin();
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) sendError('User ID required.');
    if ($id === (int) currentUserId()) sendError('You cannot delete your own account.');
    try {
        $row = $pdo->prepare("SELECT role, full_name FROM users WHERE id=? LIMIT 1");
        $row->execute([$id]);
        $user = $row->fetch(PDO::FETCH_ASSOC);
        if (!$user) sendError('User not found.', 404);
        if ($user['role'] === 'admin') {
            $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            if ($adminCount <= 1) sendError('Cannot delete the last admin account.');
        }
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
        logAudit($pdo, 'delete_user', 'users', 'user', $id, "Admin deleted user: {$user['full_name']}");
        sendSuccess([], 'User deleted.');
    } catch (Throwable $e) {
        sendError('Failed to delete user: ' . dbgMsg($e), 500);
    }
}

// ── Borrowing Policies ────────────────────────────────────────────────────────

function handlePoliciesGet(): void {
    global $pdo;
    apiRequireAdmin();
    try {
        $rows = $pdo->query("SELECT * FROM borrowing_policies")->fetchAll(PDO::FETCH_ASSOC);
        $map  = [];
        foreach ($rows as $r) $map[$r['classification']] = $r;
        sendSuccess($map);
    } catch (Throwable $e) {
        sendError('Failed to load policies: ' . dbgMsg($e), 500);
    }
}

function handlePoliciesSave(): void {
    global $pdo;
    apiRequireAdmin();
    $policies = $_POST['policies'] ?? [];
    if (!is_array($policies)) sendError('Invalid payload.');
    $allowed = ['child','teen','individual','deped','school','professional','private_institution'];
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO borrowing_policies
                (classification,max_borrow_days,max_books_per_borrow,fine_per_day,reservation_expiry_days,grace_period_days)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                max_borrow_days=VALUES(max_borrow_days),
                max_books_per_borrow=VALUES(max_books_per_borrow),
                fine_per_day=VALUES(fine_per_day),
                reservation_expiry_days=VALUES(reservation_expiry_days),
                grace_period_days=VALUES(grace_period_days)"
        );
        foreach ($policies as $cls => $vals) {
            if (!in_array($cls, $allowed, true)) continue;
            $stmt->execute([
                $cls,
                max(0, (int)   ($vals['max_borrow_days']        ?? 14)),
                max(1, (int)   ($vals['max_books_per_borrow']   ?? 5)),
                max(0, (float) ($vals['fine_per_day']           ?? 5)),
                max(1, (int)   ($vals['reservation_expiry_days']?? 3)),
                max(0, (int)   ($vals['grace_period_days']      ?? 0)),
            ]);
        }
        logAudit($pdo, 'save_policies', 'settings', null, null, 'Admin updated borrowing policies.');
        sendSuccess([], 'Policies saved successfully.');
    } catch (Throwable $e) {
        sendError('Failed to save policies: ' . dbgMsg($e), 500);
    }
}

// ── Maintenance ───────────────────────────────────────────────────────────────

function handleMaintenanceToggle(): void {
    global $pdo;
    apiRequireAdmin();
    $enabled = ($_POST['enabled'] ?? '0') === '1' ? '1' : '0';
    $message = trim($_POST['message'] ?? 'System is under maintenance. Please check back later.');
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO library_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?"
        );
        $stmt->execute(['maintenance_mode', $enabled, $enabled]);
        $stmt->execute(['maintenance_msg',  $message,  $message]);
        logAudit($pdo, $enabled === '1' ? 'enable_maintenance' : 'disable_maintenance', 'settings', null, null,
            ($enabled === '1' ? 'Enabled' : 'Disabled') . ' maintenance mode.');
        sendSuccess([], $enabled === '1' ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.');
    } catch (Throwable $e) {
        sendError('Failed to toggle maintenance: ' . dbgMsg($e), 500);
    }
}

function handleDbExportCsv(): void {
    global $pdo;
    apiRequireAdmin();
    $type    = $_GET['type'] ?? '';
    $allowed = ['books', 'borrowers', 'transactions'];
    if (!in_array($type, $allowed, true)) sendError('Invalid export type.');

    try {
        switch ($type) {
            case 'books':
                $rows    = $pdo->query(
                    "SELECT id,title,author,subject,category,isbn,grade_level,location_label,
                            quantity_total,quantity_available,quantity_damaged,quantity_missing,
                            condition_status,created_at FROM books ORDER BY id"
                )->fetchAll(PDO::FETCH_ASSOC);
                $headers = ['ID','Title','Author','Subject','Category','ISBN','Grade Level','Location',
                            'Total Qty','Available','Damaged','Missing','Condition','Added'];
                break;
            case 'borrowers':
                $rows    = $pdo->query(
                    "SELECT id,name,contact,email,classification,address,created_at
                     FROM library_borrowers ORDER BY id"
                )->fetchAll(PDO::FETCH_ASSOC);
                $headers = ['ID','Name','Contact','Email','Classification','Address','Registered'];
                break;
            case 'transactions':
                $rows    = $pdo->query(
                    "SELECT bbr.id, lb.name AS borrower,
                            GROUP_CONCAT(b.title ORDER BY b.title SEPARATOR '; ') AS books,
                            bbr.status, bbr.borrowed_at, bbr.due_at, bbr.returned_at, bbr.fine_amount
                     FROM book_borrow_records bbr
                     LEFT JOIN library_borrowers lb ON lb.id = bbr.borrower_id
                     LEFT JOIN book_borrow_items bbi ON bbi.borrow_id = bbr.id
                     LEFT JOIN books b ON b.id = bbi.book_id
                     GROUP BY bbr.id ORDER BY bbr.id"
                )->fetchAll(PDO::FETCH_ASSOC);
                $headers = ['ID','Borrower','Books','Status','Borrowed','Due','Returned','Fine (PHP)'];
                break;
            default:
                sendError('Invalid type.'); return;
        }

        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $type . '_' . date('Y-m-d') . '.csv"');
        ob_end_clean();
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) fputcsv($out, array_values($row));
        fclose($out);
        exit;
    } catch (Throwable $e) {
        sendError('Export failed: ' . dbgMsg($e), 500);
    }
}

function handleDbPurgeTrash(): void {
    global $pdo;
    apiRequireAdmin();
    try {
        $ids = $pdo->query(
            "SELECT id FROM library_documents
             WHERE COALESCE(is_deleted,0)=1
               AND deleted_at IS NOT NULL
               AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $docId) permanentlyDeleteDocument($pdo, (int) $docId);
        $count = count($ids);
        logAudit($pdo, 'purge_trash', 'settings', null, null, "Admin purged {$count} old trash documents.");
        sendSuccess(['count' => $count], "Purged {$count} document(s) from trash.");
    } catch (Throwable $e) {
        sendError('Purge failed: ' . dbgMsg($e), 500);
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// SETTINGS MODULE — My Account (User Self-Service)
// ══════════════════════════════════════════════════════════════════════════════

// ══════════════════════════════════════════════════════════════════
// PROFILE AVATARS — 3-tier: uploaded photo › system avatar › initials
// ══════════════════════════════════════════════════════════════════

// Initials from a name: "Axcel Corpuz" → "AC", "Madonna" → "MA"
function avatarInitials(string $name): string {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '') return 'U';
    $parts = explode(' ', $name);
    if (count($parts) === 1) {
        return strtoupper(mb_substr($parts[0], 0, 2));
    }
    $first = mb_substr($parts[0], 0, 1);
    $last  = mb_substr(end($parts), 0, 1);
    return strtoupper($first . $last);
}

// Deterministic, calm background colour from the name (consistent per user)
function avatarColor(string $seed): string {
    $palette = ['#0F6E56','#185FA5','#534AB7','#993C1D','#72243E','#3B6D11','#854F0B','#444441','#0C447C','#26215C'];
    $h = crc32(mb_strtolower(trim($seed)));
    return $palette[$h % count($palette)];
}

function initialsAvatarSvg(string $name, int $size = 256): string {
    $initials = htmlspecialchars(avatarInitials($name), ENT_QUOTES);
    $bg = avatarColor($name);
    $fs = round($size * 0.42);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-label="' . $initials . '">'
        . '<rect width="100%" height="100%" rx="' . $size . '" fill="' . $bg . '"/>'
        . '<text x="50%" y="50%" dy="0.35em" text-anchor="middle" '
        . 'font-family="Inter,Segoe UI,Helvetica,Arial,sans-serif" font-size="' . $fs . '" font-weight="600" fill="#ffffff">'
        . $initials . '</text></svg>';
}

// Validate "category/file.ext" against the real on-disk library (blocks traversal)
function avatarIdToPath(string $avatarId): ?string {
    if (!preg_match('#^([a-z]+)/([a-z0-9._-]+)\.(png|jpg|jpeg|webp)$#', $avatarId, $m)) return null;
    if (!in_array($m[1], AVATAR_CATEGORIES, true)) return null;
    $rel = AVATAR_LIB_PATH . $avatarId;
    return is_file(__DIR__ . '/../' . $rel) ? $rel : null;
}

function avatarLibrary(): array {
    $lib = [];
    foreach (AVATAR_CATEGORIES as $cat) {
        $dir = AVATAR_LIB_DIR . $cat;
        if (!is_dir($dir)) continue;
        $items = [];
        foreach (glob($dir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [] as $f) {
            $file = basename($f);
            $items[] = ['id' => "$cat/$file", 'url' => AVATAR_LIB_PATH . "$cat/$file"];
        }
        if ($items) $lib[] = ['category' => $cat, 'label' => ucfirst($cat), 'avatars' => $items];
    }
    return $lib;
}

function handleAvatarLibrary(): void {
    apiRequireLogin();
    sendSuccess(avatarLibrary());
}

// Unified avatar resolver for any user id (photo › system avatar › initials)
function resolveUserAvatar(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare('SELECT full_name, profile_image, avatar_id, profile_image_updated_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return ['type' => 'initials', 'name' => 'User'];

    if (!empty($u['profile_image']) && is_file(__DIR__ . '/../' . $u['profile_image'])) {
        return ['type' => 'photo', 'file' => $u['profile_image'], 'name' => $u['full_name']];
    }
    if (!empty($u['avatar_id']) && ($rel = avatarIdToPath($u['avatar_id']))) {
        return ['type' => 'system', 'url' => $rel, 'name' => $u['full_name']];
    }
    return ['type' => 'initials', 'name' => $u['full_name'] ?? 'User'];
}

// GET serve endpoint used everywhere as <img src>. Returns the photo, redirects
// to the static system avatar, or emits an initials SVG — always something valid.
function handleUserAvatar(): void {
    global $pdo;
    $uid = (int) ($_GET['id'] ?? currentUserId());
    $a = resolveUserAvatar($pdo, $uid);

    if ($a['type'] === 'photo') {
        $abs = __DIR__ . '/../' . $a['file'];
        $ext = strtolower(pathinfo($a['file'], PATHINFO_EXTENSION));
        $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        readfile($abs);
        exit;
    }
    if ($a['type'] === 'system') {
        // Stream the curated asset directly (NOT a redirect — a relative Location
        // header resolves against /api/ and 404s; readfile is base-path-agnostic).
        $abs = __DIR__ . '/../' . $a['url'];
        if (is_file($abs)) {
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','bmp'=>'image/bmp'][$ext] ?? 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($abs));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=86400');
            readfile($abs);
            exit;
        }
        // asset missing → fall through to the initials avatar
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=3600');
    echo initialsAvatarSvg($a['name'] ?? 'User');
    exit;
}

function avatarCooldownInfo(PDO $pdo, int $uid): array {
    $stmt = $pdo->prepare('SELECT profile_image_updated_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $last = $stmt->fetchColumn();
    if (!$last) return ['can_upload' => true, 'next' => null];
    $nextTs = strtotime($last) + AVATAR_UPLOAD_COOLDOWN_DAYS * 86400;
    return ['can_upload' => time() >= $nextTs, 'next' => date('Y-m-d', $nextTs)];
}

function handleUserAvatarUpload(): void {
    global $pdo;
    apiRequireLogin();
    $uid = (int) currentUserId();

    $cd = avatarCooldownInfo($pdo, $uid);
    if (!$cd['can_upload']) {
        sendError('You can change your profile photo again on ' . date('M j, Y', strtotime($cd['next'])) . '.');
    }
    if (empty($_FILES['photo']['name']) || ($_FILES['photo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        if (($_FILES['photo']['error'] ?? 0) === UPLOAD_ERR_INI_SIZE) sendError('Image is too large.');
        sendError('No image received.');
    }
    $tmp = $_FILES['photo']['tmp_name'];
    if (filesize($tmp) > 5 * 1024 * 1024) sendError('Image must be 5 MB or smaller.');

    // Real-image validation (no reliance on the client-sent type)
    $info = @getimagesize($tmp);
    $typeMap = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($typeMap[$info[2]])) {
        sendError('Unsupported or corrupted image. Use JPG, PNG, or WebP.');
    }
    $ext = $typeMap[$info[2]];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if (!in_array($finfo->file($tmp), ['image/jpeg','image/png','image/webp'], true)) {
        sendError('File content is not a valid image.');
    }

    if (!is_dir(AVATAR_UPLOAD_DIR)) @mkdir(AVATAR_UPLOAD_DIR, 0755, true);

    try {
        // Remove previous uploaded photo file
        $old = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
        $old->execute([$uid]);
        $oldPath = $old->fetchColumn();
        if ($oldPath && is_file(__DIR__ . '/../' . $oldPath)) @unlink(__DIR__ . '/../' . $oldPath);

        $name = 'u' . $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        if (!move_uploaded_file($tmp, AVATAR_UPLOAD_DIR . $name)) sendError('Failed to store image.', 500);
        $rel = 'storage/attachments/avatars/' . $name;

        $pdo->prepare('UPDATE users SET profile_image = ?, profile_image_updated_at = NOW() WHERE id = ?')
            ->execute([$rel, $uid]);
        $_SESSION['avatar_ver'] = time();
        logAudit($pdo, 'avatar_upload', 'users', 'user', $uid, 'Uploaded a profile photo.');

        $next = date('Y-m-d', time() + AVATAR_UPLOAD_COOLDOWN_DAYS * 86400);
        sendSuccess(['url' => 'api/library_handler.php?action=user_avatar&id=' . $uid . '&v=' . time(), 'avatar_type' => 'photo', 'next_change' => $next],
            'Profile photo updated.');
    } catch (Throwable $e) {
        sendError('Upload failed: ' . dbgMsg($e), 500);
    }
}

function handleUserAvatarSelect(): void {
    global $pdo;
    apiRequireLogin();
    $uid = (int) currentUserId();
    $avatarId = cleanValue($_POST['avatar_id'] ?? '');

    if ($avatarId === '' || !avatarIdToPath($avatarId)) sendError('Invalid avatar selection.');
    try {
        $pdo->prepare('UPDATE users SET avatar_id = ? WHERE id = ?')->execute([$avatarId, $uid]);
        $_SESSION['avatar_ver'] = time();
        // Report whether an uploaded photo is currently overriding the avatar
        $hasPhoto = (bool) $pdo->query('SELECT profile_image FROM users WHERE id = ' . $uid)->fetchColumn();
        sendSuccess([
            'avatar_id' => $avatarId,
            'photo_overrides' => $hasPhoto,
            'avatar_type' => $hasPhoto ? 'photo' : 'system',
            'url' => 'api/library_handler.php?action=user_avatar&id=' . $uid . '&v=' . time(),
        ], $hasPhoto ? 'Avatar saved — remove your uploaded photo to display it.' : 'Avatar updated.');
    } catch (Throwable $e) {
        sendError('Failed to set avatar: ' . dbgMsg($e), 500);
    }
}

function handleUserAvatarRemove(): void {
    global $pdo;
    apiRequireLogin();
    $uid = (int) currentUserId();
    try {
        // Removing the photo reverts to the chosen avatar / initials. The cooldown
        // timestamp is intentionally NOT reset, so this can't be used to bypass it.
        $old = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
        $old->execute([$uid]);
        $oldPath = $old->fetchColumn();
        if ($oldPath && is_file(__DIR__ . '/../' . $oldPath)) @unlink(__DIR__ . '/../' . $oldPath);
        $pdo->prepare('UPDATE users SET profile_image = NULL WHERE id = ?')->execute([$uid]);
        $_SESSION['avatar_ver'] = time();
        logAudit($pdo, 'avatar_remove', 'users', 'user', $uid, 'Removed profile photo.');
        $hasAvatar = (bool) $pdo->query('SELECT avatar_id FROM users WHERE id = ' . $uid)->fetchColumn();
        sendSuccess([
            'url' => 'api/library_handler.php?action=user_avatar&id=' . $uid . '&v=' . time(),
            'avatar_type' => $hasAvatar ? 'system' : 'initials',
        ], 'Profile photo removed.');
    } catch (Throwable $e) {
        sendError('Failed to remove photo: ' . dbgMsg($e), 500);
    }
}

function handleUserProfileGet(): void {
    global $pdo;
    apiRequireLogin();
    $uid = currentUserId();
    try {
        $stmt = $pdo->prepare(
            "SELECT id,username,full_name,role,classification,contact,created_at,
                    profile_image,avatar_id,profile_image_updated_at
             FROM users WHERE id=? LIMIT 1"
        );
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) sendError('User not found.', 404);

        $cd = avatarCooldownInfo($pdo, (int)$uid);
        $user['avatar_url']       = 'api/library_handler.php?action=user_avatar&id=' . $uid . '&v=' . ($_SESSION['avatar_ver'] ?? 0);
        $user['has_photo']        = !empty($user['profile_image']);
        $user['avatar_type']      = !empty($user['profile_image']) ? 'photo' : (!empty($user['avatar_id']) ? 'system' : 'initials');
        $user['can_upload_photo'] = $cd['can_upload'];
        $user['photo_next_change']= $cd['next'];
        $user['initials']         = avatarInitials($user['full_name'] ?? '');
        unset($user['profile_image']);
        sendSuccess($user);
    } catch (Throwable $e) {
        sendError('Failed to load profile: ' . dbgMsg($e), 500);
    }
}

function handleUserProfileUpdate(): void {
    global $pdo;
    apiRequireLogin();
    $uid     = currentUserId();
    $name    = trim($_POST['full_name'] ?? '');
    $contact = trim($_POST['contact']   ?? '');
    if (!$name) sendError('Full name is required.');
    try {
        $pdo->prepare("UPDATE users SET full_name=?, contact=? WHERE id=?")->execute([$name, $contact, $uid]);
        $_SESSION['full_name'] = $name;
        logAudit($pdo, 'update_profile', 'users', 'user', $uid, 'User updated their own profile.');
        sendSuccess(['full_name' => $name], 'Profile updated successfully.');
    } catch (Throwable $e) {
        sendError('Failed to update profile: ' . dbgMsg($e), 500);
    }
}

function handleUserPasswordChange(): void {
    global $pdo;
    apiRequireLogin();
    $uid   = currentUserId();
    $curPw = $_POST['current_password'] ?? '';
    $newPw = $_POST['new_password']     ?? '';
    if (!$curPw || !$newPw)  sendError('Both current and new password are required.');
    if (strlen($newPw) < 8)  sendError('New password must be at least 8 characters.');
    try {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$uid]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($curPw, $hash)) sendError('Current password is incorrect.');
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($newPw, PASSWORD_BCRYPT), $uid]);
        logAudit($pdo, 'change_password', 'users', 'user', $uid, 'User changed their own password.');
        sendSuccess([], 'Password changed successfully.');
    } catch (Throwable $e) {
        sendError('Failed to change password: ' . dbgMsg($e), 500);
    }
}

function handleUserNotifPrefsGet(): void {
    global $pdo;
    apiRequireLogin();
    $uid = currentUserId();
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_notification_prefs WHERE user_id=? LIMIT 1");
        $stmt->execute([$uid]);
        $prefs = $stmt->fetch(PDO::FETCH_ASSOC);
        sendSuccess($prefs ?: [
            'notify_borrow_sms'  => 1, 'notify_overdue_sms' => 1,
            'notify_due_reminder'=> 1, 'notify_announcements'=> 1,
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load preferences: ' . dbgMsg($e), 500);
    }
}

function handleUserNotifPrefsSave(): void {
    global $pdo;
    apiRequireLogin();
    $uid = currentUserId();
    $b   = fn($k) => (($_POST[$k] ?? '0') === '1') ? 1 : 0;
    try {
        $pdo->prepare(
            "INSERT INTO user_notification_prefs
                (user_id,notify_borrow_sms,notify_overdue_sms,notify_due_reminder,notify_announcements)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                notify_borrow_sms=VALUES(notify_borrow_sms),
                notify_overdue_sms=VALUES(notify_overdue_sms),
                notify_due_reminder=VALUES(notify_due_reminder),
                notify_announcements=VALUES(notify_announcements)"
        )->execute([$uid, $b('notify_borrow_sms'), $b('notify_overdue_sms'), $b('notify_due_reminder'), $b('notify_announcements')]);
        sendSuccess([], 'Notification preferences saved.');
    } catch (Throwable $e) {
        sendError('Failed to save preferences: ' . dbgMsg($e), 500);
    }
}

function handleUserActivityGet(): void {
    global $pdo;
    apiRequireLogin();
    $uid = currentUserId();
    try {
        $stmt = $pdo->prepare(
            "SELECT bbr.id, bbr.status, bbr.borrowed_at, bbr.requested_at, bbr.due_at,
                    bbr.returned_at, bbr.fine_amount,
                    GROUP_CONCAT(b.title ORDER BY b.title SEPARATOR ', ') AS book_titles
             FROM book_borrow_records bbr
             LEFT JOIN book_borrow_items bbi ON bbi.borrow_id = bbr.id
             LEFT JOIN books b ON b.id = bbi.book_id
             WHERE bbr.requested_by = ?
             GROUP BY bbr.id
             ORDER BY bbr.requested_at DESC
             LIMIT 60"
        );
        $stmt->execute([$uid]);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load activity: ' . dbgMsg($e), 500);
    }
}

function handleUserSessionsGet(): void {
    global $pdo;
    apiRequireLogin();
    $uid    = currentUserId();
    $curSid = session_id();
    try {
        $pdo->prepare(
            "INSERT INTO user_sessions (id,user_id,ip_address,user_agent,last_active)
             VALUES (?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE last_active=NOW(),ip_address=?,user_agent=?"
        )->execute([
            $curSid, $uid,
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);

        $stmt = $pdo->prepare(
            "SELECT id,ip_address,user_agent,last_active,created_at FROM user_sessions
             WHERE user_id=? AND last_active > DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY last_active DESC"
        );
        $stmt->execute([$uid]);
        $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sessions as &$s) {
            $s['is_current'] = ($s['id'] === $curSid);
            $ua = $s['user_agent'] ?? '';
            $browser = str_contains($ua,'Chrome') && !str_contains($ua,'Edg') ? 'Chrome'
                : (str_contains($ua,'Firefox') ? 'Firefox'
                : (str_contains($ua,'Edg')     ? 'Edge'
                : (str_contains($ua,'Safari')  ? 'Safari' : 'Browser')));
            $device = str_contains($ua,'Mobile') ? 'Mobile' : 'Desktop';
            $s['device'] = "{$device} — {$browser}";
        }
        unset($s);
        sendSuccess($sessions);
    } catch (Throwable $e) {
        sendError('Failed to load sessions: ' . dbgMsg($e), 500);
    }
}

function handleUserSessionsRevokeAll(): void {
    global $pdo;
    apiRequireLogin();
    $uid    = currentUserId();
    $curSid = session_id();
    try {
        $pdo->prepare("DELETE FROM user_sessions WHERE user_id=? AND id != ?")->execute([$uid, $curSid]);
        logAudit($pdo, 'revoke_sessions', 'users', 'user', $uid, 'User revoked all other active sessions.');
        sendSuccess([], 'All other sessions have been logged out.');
    } catch (Throwable $e) {
        sendError('Failed to revoke sessions: ' . dbgMsg($e), 500);
    }
}
