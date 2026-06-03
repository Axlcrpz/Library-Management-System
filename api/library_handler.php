<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

const LIBRARY_UPLOAD_DIR = __DIR__ . '/../storage/attachments/';
const LIBRARY_UPLOAD_PATH = 'storage/attachments/';

function sendJson(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function sendSuccess(array $data = [], string $message = 'Success'): void {
    sendJson(['success' => true, 'message' => $message, 'data' => $data]);
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


function isValidPdf(string $tmpFile): bool {
    if (!is_uploaded_file($tmpFile)) {
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return false;
    }

    $mime = finfo_file($finfo, $tmpFile);
    finfo_close($finfo);

    if ($mime === 'application/pdf') {
        return true;
    }

    $handle = @fopen($tmpFile, 'rb');
    if (!$handle) {
        return false;
    }
    $header = fread($handle, 4);
    fclose($handle);
    return $header === '%PDF';
}

function storeUploadedPdf(int $documentId): void {
    global $pdo;

    if (empty($_FILES['files']['name'][0])) {
        return;
    }

    if (!is_dir(LIBRARY_UPLOAD_DIR)) {
        mkdir(LIBRARY_UPLOAD_DIR, 0755, true);
    }

    $stmt = $pdo->prepare('UPDATE library_documents SET file_path = ?, file_name = ?, file_size = ?, file_type = ?, updated_at = NOW() WHERE id = ?');

    foreach ($_FILES['files']['tmp_name'] as $i => $tmpName) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || !isValidPdf($tmpName)) {
            continue;
        }

        $originalName = basename($_FILES['files']['name'][$i]);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $newName = 'library_' . uniqid('', true) . '_' . $safeName;

        if (move_uploaded_file($tmpName, LIBRARY_UPLOAD_DIR . $newName)) {
            $stmt->execute([
                LIBRARY_UPLOAD_PATH . $newName,
                $originalName,
                filesize(LIBRARY_UPLOAD_DIR . $newName),
                strtolower(pathinfo($originalName, PATHINFO_EXTENSION)),
                $documentId,
            ]);
            saveVersion($pdo, $documentId, 'file_upload');
        }
    }
}

apiRequireLogin();
ensureLibrarySchema($pdo);
purgeExpiredTrash($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$mutating = [
    'add', 'update', 'delete', 'restore_deleted',
    'permanent_delete', 'permanent_delete_all',
    'archive', 'delete_file', 'borrow', 'return',
    'books_add', 'books_update', 'books_delete',
    'delivery_add', 'announcements_add', 'announcements_delete',
    'borrowers_add',
    'book_borrow_request_add', 'book_borrow_approve',
    'book_borrow_reject', 'book_borrow_cancel', 'book_borrow_return',
    'create_reservation', 'cancel_reservation', 'notify_next_in_queue',
];
if (in_array($action, $mutating, true)) {
    apiRequireValidCsrf();
}

switch ($action) {
    case 'get': handleGet(); break;
    case 'trash': handleTrash(); break;
    case 'books_get': handleBooksGet(); break;
    case 'books_add': handleBooksAdd(); break;
    case 'books_update': handleBooksUpdate(); break;
    case 'books_delete': handleBooksDelete(); break;
    case 'book_stats': handleBookStats(); break;
    case 'book_reports': handleBookReports(); break;
    case 'book_borrow_requests_get': handleBookBorrowRequestsGet(); break;
    case 'book_borrow_request_add': handleBookBorrowRequestAdd(); break;
    case 'book_borrow_approve': handleBookBorrowApprove(); break;
    case 'book_borrow_reject': handleBookBorrowReject(); break;
    case 'book_borrow_cancel': handleBookBorrowCancel(); break;
    case 'book_borrow_return': handleBookBorrowReturn(); break;
    case 'delivery_add': handleDeliveryAdd(); break;
    case 'delivery_get': handleDeliveryGet(); break;
    case 'borrowers_search': handleBorrowersSearch(); break;
    case 'borrowers_add': handleBorrowersAdd(); break;
    case 'announcements_get': handleAnnouncementsGet(); break;
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
    default: sendError('Unknown action.', 400);
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
        sendError('Failed to load documents: ' . $e->getMessage(), 500);
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
        sendError('Failed to load trash: ' . $e->getMessage(), 500);
    }
}

function handleAdd(): void {
    global $pdo;
    apiRequireAdmin();

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
        storeUploadedPdf($documentId);

        $pdo->commit();
        sendSuccess(['document_id' => $documentId], 'Document saved successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to save document: ' . $e->getMessage(), 500);
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
        storeUploadedPdf($id);

        $pdo->commit();
        sendSuccess([], 'Document updated successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to update document: ' . $e->getMessage(), 500);
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
        sendError('Failed to delete document: ' . $e->getMessage(), 500);
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
        sendError('Failed to restore document: ' . $e->getMessage(), 500);
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
        foreach ($ids as $id) {
            permanentlyDeleteDocument($pdo, $id);
        }
        $pdo->commit();
        sendSuccess([], 'Selected trash documents permanently deleted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        sendError('Failed to permanently delete documents: ' . $e->getMessage(), 500);
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
        sendError('Failed to empty trash: ' . $e->getMessage(), 500);
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
        sendError('Failed to update archive status: ' . $e->getMessage(), 500);
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
        sendError('Failed to remove file: ' . $e->getMessage(), 500);
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

    $documentId = (int) ($_POST['document_id'] ?? 0);
    $borrowerName = cleanValue($_POST['borrower_name'] ?? '');
    $borrowerContact = cleanValue($_POST['borrower_contact'] ?? '');
    $borrowedAt = cleanValue($_POST['borrowed_at'] ?? '') ?: date('Y-m-d H:i:s');
    $expectedReturn = cleanValue($_POST['expected_return_date'] ?? '') ?: null;
    $notes = cleanValue($_POST['notes'] ?? '');

    if (!$documentId || $borrowerName === '') {
        sendError('Document and borrower name are required.');
    }
    if (activeBorrow($pdo, $documentId)) {
        sendError('This document is already borrowed.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO library_borrowers (name, contact) VALUES (?, ?) ON DUPLICATE KEY UPDATE contact = VALUES(contact)');
        $stmt->execute([$borrowerName, $borrowerContact ?: null]);

        $stmt = $pdo->prepare('SELECT id FROM library_borrowers WHERE name = ? LIMIT 1');
        $stmt->execute([$borrowerName]);
        $borrowerId = (int) $stmt->fetchColumn();

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
        sendError('Failed to borrow document: ' . $e->getMessage(), 500);
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
        sendError('Failed to return document: ' . $e->getMessage(), 500);
    }
}

function handleBooksGet(): void {
    global $pdo;
    try {
        $stmt = $pdo->query(
            'SELECT * FROM books ORDER BY subject ASC, grade_level ASC, title ASC'
        );
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load books: ' . $e->getMessage(), 500);
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
        sendSuccess(['id' => (int) $pdo->lastInsertId()], 'Book added successfully.');
    } catch (Throwable $e) {
        sendError('Failed to add book: ' . $e->getMessage(), 500);
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
        sendError('Failed to update book: ' . $e->getMessage(), 500);
    }
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
        sendError('Failed to delete book: ' . $e->getMessage(), 500);
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
        sendError('Failed to load deliveries: ' . $e->getMessage(), 500);
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
        }

        $pdo->commit();
        sendSuccess(['delivery_id' => $deliveryId], 'Delivery logged successfully.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to log delivery: ' . $e->getMessage(), 500);
    }
}

// ── ANNOUNCEMENTS ────────────────────────────────

function handleAnnouncementsGet(): void {
    global $pdo;
    try {
        $isAdmin = (($_SESSION['role'] ?? 'user') === 'admin');
        $where = $isAdmin ? '' : 'WHERE is_active = 1';
        $stmt = $pdo->query(
            "SELECT a.*, u.full_name AS posted_by_name
             FROM announcements a
             LEFT JOIN users u ON u.id = a.posted_by
             {$where}
             ORDER BY a.created_at DESC"
        );
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load announcements: ' . $e->getMessage(), 500);
    }
}

function handleAnnouncementsAdd(): void {
    global $pdo;
    apiRequireStaff();

    $title    = cleanValue($_POST['title'] ?? '');
    $body     = cleanValue($_POST['body'] ?? '');
    $isActive = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;

    if ($title === '' || $body === '') {
        sendError('Title and body are required.');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO announcements (title, body, posted_by, is_active)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$title, $body, currentUserId(), $isActive]);
        sendSuccess(['id' => (int) $pdo->lastInsertId()], 'Announcement posted successfully.');
    } catch (Throwable $e) {
        sendError('Failed to post announcement: ' . $e->getMessage(), 500);
    }
}

function handleAnnouncementsDelete(): void {
    global $pdo;
    apiRequireStaff();

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) {
        sendError('Missing announcement id.');
    }

    try {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        sendSuccess([], 'Announcement deleted.');
    } catch (Throwable $e) {
        sendError('Failed to delete announcement: ' . $e->getMessage(), 500);
    }
}

function apiRequireStaff(): void {
    apiRequireLogin();
    $role = $_SESSION['role'] ?? 'viewer';
    if (!in_array($role, ['admin', 'staff'], true)) {
        sendError('Access denied: staff privileges required.', 403);
    }
}

function findOrCreateBorrower(PDO $pdo, string $name, string $contact): int {
    $stmt = $pdo->prepare('INSERT INTO library_borrowers (name, contact) VALUES (?, ?) ON DUPLICATE KEY UPDATE contact = VALUES(contact)');
    $stmt->execute([$name, $contact ?: null]);
    $stmt = $pdo->prepare('SELECT id FROM library_borrowers WHERE name = ? LIMIT 1');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn();
}

function handleBookStats(): void {
    global $pdo;
    try {
        $row = $pdo->query('SELECT COALESCE(SUM(quantity_total), 0) AS total_qty, COALESCE(SUM(quantity_available), 0) AS available_qty FROM books')->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total_qty'] ?? 0);
        $available = (int) ($row['available_qty'] ?? 0);
        $borrowed = max(0, $total - $available);

        $overdue = (int) $pdo->query("SELECT COUNT(*) FROM book_borrow_records WHERE status = 'borrowed' AND due_at IS NOT NULL AND due_at < NOW()")->fetchColumn();
        $today = (int) $pdo->query("SELECT COUNT(*) FROM book_borrow_records WHERE (borrowed_at IS NOT NULL AND DATE(borrowed_at) = CURDATE()) OR (returned_at IS NOT NULL AND DATE(returned_at) = CURDATE())")->fetchColumn();

        sendSuccess([
            'total_qty' => $total,
            'available_qty' => $available,
            'borrowed_qty' => $borrowed,
            'overdue_count' => $overdue,
            'today_transactions' => $today,
        ]);
    } catch (Throwable $e) {
        sendError('Failed to load book stats: ' . $e->getMessage(), 500);
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
    $stmt = $pdo->prepare(
        "SELECT r.*, b.name AS borrower_name, b.contact AS borrower_contact,
                u.full_name AS requested_by_name, u2.full_name AS reviewed_by_name
         FROM book_borrow_records r
         INNER JOIN library_borrowers b ON b.id = r.borrower_id
         LEFT JOIN users u ON u.id = r.requested_by
         LEFT JOIN users u2 ON u2.id = r.reviewed_by
         {$where}
         ORDER BY r.requested_at DESC, r.id DESC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtItems = $pdo->prepare(
        'SELECT i.*, bk.title, bk.subject, bk.grade_level
         FROM book_borrow_items i
         LEFT JOIN books bk ON bk.id = i.book_id
         WHERE i.borrow_id = ?'
    );

    foreach ($rows as &$row) {
        $stmtItems->execute([(int) $row['id']]);
        $row['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($row);

    sendSuccess($rows);
}

function handleBookBorrowRequestAdd(): void {
    global $pdo;
    apiRequireLogin();

    $borrowerName = cleanValue($_POST['borrower_name'] ?? '');
    $borrowerContact = cleanValue($_POST['borrower_contact'] ?? '');
    $borrowType = cleanValue($_POST['borrow_type'] ?? '');
    $timeAllowed = isset($_POST['time_allowed_minutes']) ? (int) $_POST['time_allowed_minutes'] : null;
    $items = $_POST['items'] ?? [];

    if ($borrowerName === '') {
        sendError('Borrower name is required.');
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

        $borrowerId = findOrCreateBorrower($pdo, $borrowerName, $borrowerContact);
        if (!$borrowerId) {
            throw new RuntimeException('Unable to create borrower.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO book_borrow_records (borrower_id, requested_by, status, borrow_type, time_allowed_minutes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$borrowerId, currentUserId(), 'pending', $borrowType ?: null, $timeAllowed]);
        $borrowId = (int) $pdo->lastInsertId();

        $stmtItem = $pdo->prepare('INSERT INTO book_borrow_items (borrow_id, book_id, quantity) VALUES (?, ?, ?)');
        foreach ($cleanItems as $item) {
            $stmtItem->execute([$borrowId, $item['book_id'], $item['quantity']]);
        }

        $pdo->commit();
        sendSuccess(['id' => $borrowId], 'Borrow request submitted.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to submit borrow request: ' . $e->getMessage(), 500);
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

        $stmtBook = $pdo->prepare('SELECT quantity_available FROM books WHERE id = ? FOR UPDATE');
        $stmtUpdateBook = $pdo->prepare('UPDATE books SET quantity_available = quantity_available - ?, updated_at = NOW() WHERE id = ?');

        foreach ($items as $item) {
            $bookId = (int) $item['book_id'];
            $qty = (int) $item['quantity'];
            $stmtBook->execute([$bookId]);
            $available = (int) $stmtBook->fetchColumn();
            if ($available < $qty) {
                throw new RuntimeException('Not enough available copies for one or more books.');
            }
        }

        foreach ($items as $item) {
            $bookId = (int) $item['book_id'];
            $qty = (int) $item['quantity'];
            $stmtUpdateBook->execute([$qty, $bookId]);
        }

        $computedDue = $dueAt !== '' ? $dueAt : null;
        if ($computedDue === null && !empty($record['time_allowed_minutes'])) {
            $minutes = (int) $record['time_allowed_minutes'];
            $computedDue = date('Y-m-d H:i:s', strtotime($borrowedAt . " +{$minutes} minutes"));
        }

        $stmt = $pdo->prepare(
            "UPDATE book_borrow_records
             SET status = 'borrowed',
                 reviewed_by = ?, reviewed_at = NOW(),
                 borrowed_at = ?, due_at = ?
             WHERE id = ?"
        );
        $stmt->execute([currentUserId(), $borrowedAt, $computedDue, $borrowId]);

        $pdo->commit();
        sendSuccess([], 'Borrow request approved.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to approve borrow request: ' . $e->getMessage(), 500);
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
        sendError('Failed to reject borrow request: ' . $e->getMessage(), 500);
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
            $stmt = $pdo->prepare("UPDATE book_borrow_records SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
            $stmt->execute([$borrowId]);
            sendSuccess([], 'Borrow request cancelled.');
            return;
        }

        $stmt = $pdo->prepare("UPDATE book_borrow_records SET status = 'cancelled' WHERE id = ? AND requested_by = ? AND status = 'pending'");
        $stmt->execute([$borrowId, currentUserId()]);
        sendSuccess([], 'Borrow request cancelled.');
    } catch (Throwable $e) {
        sendError('Failed to cancel borrow request: ' . $e->getMessage(), 500);
    }
}

function handleBookBorrowReturn(): void {
    global $pdo;
    apiRequireStaff();

    $borrowId = (int) ($_POST['id'] ?? 0);
    $returnNotes = cleanValue($_POST['return_notes'] ?? '');
    $fineAmount = isset($_POST['fine_amount']) && is_numeric($_POST['fine_amount']) ? (float) $_POST['fine_amount'] : null;
    $items = $_POST['items'] ?? [];

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
        }
        if ($allReturned) {
            foreach ($borrowItems as $bi) {
                $bookId = (int)$bi['book_id'];
                $nextWaiter = $pdo->prepare(
                    "SELECT id FROM reservations
                     WHERE book_id = ? AND status = 'waiting'
                     ORDER BY queue_position ASC LIMIT 1"
                );
                $nextWaiter->execute([$bookId]);
                $waiter = $nextWaiter->fetch(PDO::FETCH_ASSOC);
                if ($waiter) {
                    $expDays = (int)($pdo->query(
                        "SELECT setting_value FROM library_settings
                         WHERE setting_key = 'reservation_expiry_days'"
                    )->fetchColumn() ?: 3);
                    $expires = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));
                    $pdo->prepare(
                        "UPDATE reservations
                         SET status = 'ready', notified_at = NOW(), expires_at = ?
                         WHERE id = ?"
                    )->execute([$expires, $waiter['id']]);
                }
            }
        }

        $pdo->commit();
        sendSuccess(['completed' => $allReturned], $allReturned ? 'Books returned successfully.' : 'Return saved (partial).');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('Failed to return books: ' . $e->getMessage(), 500);
    }
}
// ═══════════════════════════════════════════════════════════════
// RESERVATION FUNCTIONS — Module 08
// ═══════════════════════════════════════════════════════════════

function reorderQueue(PDO $pdo, int $bookId): void {
    $stmt = $pdo->prepare(
        "SELECT id FROM reservations
         WHERE book_id = ? AND status IN ('waiting','ready')
         ORDER BY queue_position ASC, created_at ASC"
    );
    $stmt->execute([$bookId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare("UPDATE reservations SET queue_position = ? WHERE id = ?");
    foreach ($rows as $i => $row) {
        $update->execute([$i + 1, $row['id']]);
    }
}

function handleGetReservations(): void {
    global $pdo;
    apiRequireLogin();
    $isAdmin = ($_SESSION['role'] ?? 'viewer') === 'admin';

    try {
        if ($isAdmin) {
            $stmt = $pdo->prepare(
                "SELECT r.*, u.full_name AS user_name, u.username AS user_email,
                        b.title AS book_title
                 FROM reservations r
                 JOIN users u ON u.id = r.user_id
                 JOIN books b ON b.id = r.book_id
                 ORDER BY r.created_at DESC"
            );
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare(
                "SELECT r.*, b.title AS book_title
                 FROM reservations r
                 JOIN books b ON b.id = r.book_id
                 WHERE r.user_id = ?
                 ORDER BY r.created_at DESC"
            );
            $stmt->execute([currentUserId()]);
        }
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        sendError('Failed to load reservations: ' . $e->getMessage(), 500);
    }
}

function handleCreateReservation(): void {
    global $pdo;
    apiRequireLogin();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookId = (int)($input['book_id'] ?? $_POST['book_id'] ?? 0);
    $userId = (int)currentUserId();

    if (!$bookId) { sendError('Book ID required.'); }

    try {
        // Prevent duplicate active reservation
        $check = $pdo->prepare(
            "SELECT id FROM reservations
             WHERE user_id = ? AND book_id = ? AND status IN ('waiting','ready')"
        );
        $check->execute([$userId, $bookId]);
        if ($check->fetch()) {
            sendError('You already have an active reservation for this book.');
        }

        // Next queue position
        $pos = $pdo->prepare(
            "SELECT COALESCE(MAX(queue_position), 0) + 1
             FROM reservations WHERE book_id = ? AND status IN ('waiting','ready')"
        );
        $pos->execute([$bookId]);
        $nextPos = (int)$pos->fetchColumn();

        $pdo->prepare(
            "INSERT INTO reservations (user_id, book_id, queue_position, status)
             VALUES (?, ?, ?, 'waiting')"
        )->execute([$userId, $bookId, $nextPos]);

        sendSuccess(['position' => $nextPos], 'Reservation added. You are #' . $nextPos . ' in the queue.');
    } catch (Throwable $e) {
        sendError('Failed to create reservation: ' . $e->getMessage(), 500);
    }
}

function handleCancelReservation(): void {
    global $pdo;
    apiRequireLogin();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $resId  = (int)($input['id'] ?? $_POST['id'] ?? 0);
    $userId = (int)currentUserId();
    $isAdmin = ($_SESSION['role'] ?? 'viewer') === 'admin';

    if (!$resId) { sendError('Reservation ID required.'); }

    try {
        if ($isAdmin) {
            $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
            $stmt->execute([$resId]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND user_id = ?");
            $stmt->execute([$resId, $userId]);
        }
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$res) { sendError('Reservation not found.', 404); }

        $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")
            ->execute([$resId]);

        reorderQueue($pdo, (int)$res['book_id']);
        sendSuccess([], 'Reservation cancelled.');
    } catch (Throwable $e) {
        sendError('Failed to cancel reservation: ' . $e->getMessage(), 500);
    }
}

function handleNotifyNextInQueue(): void {
    global $pdo;
    apiRequireStaff();
    apiRequireValidCsrf();

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $bookId = (int)($input['book_id'] ?? $_POST['book_id'] ?? 0);
    if (!$bookId) { sendError('Book ID required.'); }

    try {
        $expDays = (int)($pdo->query(
            "SELECT setting_value FROM library_settings
             WHERE setting_key = 'reservation_expiry_days'"
        )->fetchColumn() ?: 3);

        $next = $pdo->prepare(
            "SELECT r.*, u.full_name, u.username AS email
             FROM reservations r
             JOIN users u ON u.id = r.user_id
             WHERE r.book_id = ? AND r.status = 'waiting'
             ORDER BY r.queue_position ASC LIMIT 1"
        );
        $next->execute([$bookId]);
        $waiter = $next->fetch(PDO::FETCH_ASSOC);

        if (!$waiter) {
            sendSuccess([], 'No waiters in queue.');
            return;
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expDays} days"));
        $pdo->prepare(
            "UPDATE reservations
             SET status = 'ready', notified_at = NOW(), expires_at = ?
             WHERE id = ?"
        )->execute([$expiresAt, $waiter['id']]);

        sendSuccess(['user' => $waiter], 'Next user notified: ' . $waiter['full_name']);
    } catch (Throwable $e) {
        sendError('Failed to notify next user: ' . $e->getMessage(), 500);
    }
}

function handleExpireReservations(): void {
    global $pdo;
    apiRequireAdmin();

    try {
        $expired = $pdo->prepare(
            "SELECT * FROM reservations WHERE status = 'ready' AND expires_at < NOW()"
        );
        $expired->execute();
        $rows = $expired->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $pdo->prepare("UPDATE reservations SET status = 'expired' WHERE id = ?")
                ->execute([$r['id']]);
            reorderQueue($pdo, (int)$r['book_id']);
        }

        sendSuccess(['expired' => count($rows)], count($rows) . ' reservation(s) expired.');
    } catch (Throwable $e) {
        sendError('Failed to expire reservations: ' . $e->getMessage(), 500);
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
        sendError('Failed to load book reports: ' . $e->getMessage(), 500);
    }
}

// Fix 3: handleBorrowersSearch() and handleBorrowersAdd() belong in library_handler,
//         not only in tnf_handler. These were missing from library_handler.php.

function handleBorrowersSearch(): void {
    global $pdo;
    $query = cleanValue($_GET['q']    ?? '');
    $type  = cleanValue($_GET['type'] ?? '');

    $conditions = [];
    $params     = [];

    if ($query !== '') {
        $conditions[] = '(LOWER(name) LIKE LOWER(?) OR (lrn IS NOT NULL AND lrn LIKE ?))';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }
    if ($type !== '') {
        $conditions[] = 'borrower_type = ?';
        $params[] = $type;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Gracefully handle tables that may not yet have borrower_type / lrn columns
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM library_borrowers {$where} ORDER BY name ASC LIMIT 20"
        );
        $stmt->execute($params);
        sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        // Fallback: simpler query without extended columns
        try {
            $fallbackWhere = ($query !== '') ? 'WHERE LOWER(name) LIKE LOWER(?)' : '';
            $fallbackParams = ($query !== '') ? ['%' . $query . '%'] : [];
            $stmt = $pdo->prepare(
                "SELECT id, name, contact FROM library_borrowers {$fallbackWhere} ORDER BY name ASC LIMIT 20"
            );
            $stmt->execute($fallbackParams);
            sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $e2) {
            sendError('Failed to search borrowers: ' . $e2->getMessage(), 500);
        }
    }
}

function handleBorrowersAdd(): void {
    global $pdo;
    apiRequireLogin();

    $name          = cleanValue($_POST['name']           ?? '');
    $type          = in_array($_POST['borrower_type'] ?? '', ['school','individual'])
                        ? $_POST['borrower_type'] : 'individual';
    $lrn           = cleanValue($_POST['lrn']            ?? '');
    $contact       = cleanValue($_POST['contact']        ?? '');
    $contactPerson = cleanValue($_POST['contact_person'] ?? '');

    if ($name === '') {
        sendError('Borrower name is required.');
    }

    // Detect which columns actually exist (the table may be minimal)
    $existingCols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM library_borrowers') as $row) {
        $existingCols[$row['Field']] = true;
    }

    // Ensure extended columns exist; add them if not
    $extensions = [
        'borrower_type'  => "VARCHAR(20) NOT NULL DEFAULT 'individual'",
        'lrn'            => 'VARCHAR(50) NULL',
        'contact_person' => 'VARCHAR(255) NULL',
    ];
    foreach ($extensions as $col => $def) {
        if (!isset($existingCols[$col])) {
            try {
                $pdo->exec("ALTER TABLE library_borrowers ADD COLUMN {$col} {$def}");
            } catch (Throwable $ignored) {}
        }
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
        sendError('Failed to save borrower: ' . $e->getMessage(), 500);
    }
}
