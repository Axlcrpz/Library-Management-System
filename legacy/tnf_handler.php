<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

const TNF_UPLOAD_DIR = __DIR__ . '/../storage/attachments/';
const TNF_UPLOAD_PATH = 'storage/attachments/';

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

function apiRequireTnfAccess(): void {
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

function ensureTnfSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tnf_documents (
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
        INDEX idx_tnf_documents_status (is_deleted, is_archived, is_borrowed),
        INDEX idx_tnf_documents_date (year, month, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tnf_borrowers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        contact VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_tnf_borrower_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tnf_borrowing_records (
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
        INDEX idx_tnf_borrow_document (document_id),
        INDEX idx_tnf_borrower (borrower_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tnf_document_versions (
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
        INDEX idx_tnf_versions_document (document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $borrowerColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tnf_borrowers') as $row) {
        $borrowerColumns[$row['Field']] = true;
    }
    if (!isset($borrowerColumns['contact'])) {
        $pdo->exec('ALTER TABLE tnf_borrowers ADD COLUMN contact VARCHAR(255) NULL AFTER name');
    }
    if (!isset($borrowerColumns['created_at'])) {
        $pdo->exec('ALTER TABLE tnf_borrowers ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    $borrowRecordColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tnf_borrowing_records') as $row) {
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
            $pdo->exec("ALTER TABLE tnf_borrowing_records ADD COLUMN {$name} {$definition}");
        }
    }


    $versionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tnf_document_versions') as $row) {
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
            $pdo->exec("ALTER TABLE tnf_document_versions ADD COLUMN {$name} {$definition}");
        }
    }
    if (isset($versionColumns['version_no']) && !isset($versionColumns['version_number'])) {
        $pdo->exec('ALTER TABLE tnf_document_versions CHANGE version_no version_number INT NOT NULL DEFAULT 1');
    }
    try {
        $pdo->exec('ALTER TABLE tnf_document_versions MODIFY file_path VARCHAR(500) NULL');
    } catch (Throwable $e) {
        // Ignore if the DB does not allow modification because of an existing constraint.
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tnf_documents') as $row) {
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
            $pdo->exec("ALTER TABLE tnf_documents ADD COLUMN {$name} {$definition}");
        }
    }
    $pdo->exec('UPDATE tnf_documents SET deleted_at = COALESCE(updated_at, NOW()) WHERE COALESCE(is_deleted, 0) = 1 AND deleted_at IS NULL');
}

function fetchDocument(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM tnf_documents WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purgeExpiredTrash(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SELECT id, file_path FROM tnf_documents WHERE COALESCE(is_deleted, 0) = 1 AND deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($expired as $doc) {
            permanentlyDeleteDocument($pdo, (int) $doc['id']);
        }
    } catch (Throwable $e) {
        // Keep normal requests working if an older database cannot evaluate the purge yet.
    }
}

function permanentlyDeleteDocument(PDO $pdo, int $id, bool $unlinkFile = true): void {
    $stmt = $pdo->prepare('SELECT file_path FROM tnf_documents WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($unlinkFile && !empty($doc['file_path'])) {
        @unlink(__DIR__ . '/../' . $doc['file_path']);
    }

    $pdo->prepare('DELETE FROM tnf_document_versions WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM tnf_borrowing_records WHERE document_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM tnf_documents WHERE id = ?')->execute([$id]);
}

function nextVersionNo(PDO $pdo, int $documentId): int {
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM tnf_document_versions WHERE document_id = ?');
    $stmt->execute([$documentId]);
    return (int) $stmt->fetchColumn();
}

function saveVersion(PDO $pdo, int $documentId, string $changeType): void {
    $doc = fetchDocument($pdo, $documentId);
    if (!$doc) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO tnf_document_versions
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
         FROM tnf_borrowing_records br
         INNER JOIN tnf_borrowers b ON b.id = br.borrower_id
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

function storeUploadedPdf(int $documentId): void {
    global $pdo;

    if (empty($_FILES['files']['name'][0])) {
        return;
    }

    if (!is_dir(TNF_UPLOAD_DIR)) {
        mkdir(TNF_UPLOAD_DIR, 0755, true);
    }

    $stmt = $pdo->prepare('UPDATE tnf_documents SET file_path = ?, file_name = ?, file_size = ?, file_type = ?, updated_at = NOW() WHERE id = ?');

    foreach ($_FILES['files']['tmp_name'] as $i => $tmpName) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK || !isValidPdf($tmpName)) {
            continue;
        }

        $originalName = basename($_FILES['files']['name'][$i]);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $newName = 'tnf_' . uniqid('', true) . '_' . $safeName;

        if (move_uploaded_file($tmpName, TNF_UPLOAD_DIR . $newName)) {
            $stmt->execute([
                TNF_UPLOAD_PATH . $newName,
                $originalName,
                filesize(TNF_UPLOAD_DIR . $newName),
                strtolower(pathinfo($originalName, PATHINFO_EXTENSION)),
                $documentId,
            ]);
            saveVersion($pdo, $documentId, 'file_upload');
        }
    }
}

apiRequireLogin();
ensureTnfSchema($pdo);
purgeExpiredTrash($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$mutating = ['add', 'update', 'delete', 'restore_deleted', 'permanent_delete', 'permanent_delete_all', 'archive', 'delete_file', 'borrow', 'return'];
if (in_array($action, $mutating, true)) {
    apiRequireValidCsrf();
}

switch ($action) {
    case 'get': handleGet(); break;
    case 'trash': handleTrash(); break;
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
    default: sendError('Unknown action.', 400);
}

function handleGet(): void {
    global $pdo;

    try {
        $stmt = $pdo->query(
            'SELECT d.*, u.full_name AS created_by_name
             FROM tnf_documents d
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
             FROM tnf_documents d
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
            'INSERT INTO tnf_documents
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
            'UPDATE tnf_documents
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
        $stmt = $pdo->prepare('UPDATE tnf_documents SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id = ?');
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
        $stmt = $pdo->prepare('UPDATE tnf_documents SET is_deleted = 0, deleted_at = NULL, updated_at = NOW() WHERE id = ?');
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
        $stmt = $pdo->query('SELECT id FROM tnf_documents WHERE COALESCE(is_deleted, 0) = 1');
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
        $stmt = $pdo->prepare('UPDATE tnf_documents SET is_archived = ?, updated_at = NOW() WHERE id = ?');
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

        $stmt = $pdo->prepare('UPDATE tnf_documents SET file_path = NULL, file_name = NULL, file_size = NULL, file_type = NULL, updated_at = NOW() WHERE id = ?');
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
         FROM tnf_document_versions v
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
         FROM tnf_borrowing_records br
         INNER JOIN tnf_borrowers b ON b.id = br.borrower_id
         INNER JOIN tnf_documents d ON d.id = br.document_id
         {$where}
         ORDER BY br.borrowed_at DESC"
    );
    $stmt->execute($params);
    sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleBorrow(): void {
    global $pdo;
    apiRequireTnfAccess();

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
        $stmt = $pdo->prepare('INSERT INTO tnf_borrowers (name, contact) VALUES (?, ?) ON DUPLICATE KEY UPDATE contact = VALUES(contact)');
        $stmt->execute([$borrowerName, $borrowerContact ?: null]);

        $stmt = $pdo->prepare('SELECT id FROM tnf_borrowers WHERE name = ? LIMIT 1');
        $stmt->execute([$borrowerName]);
        $borrowerId = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            'INSERT INTO tnf_borrowing_records (document_id, borrower_id, borrowed_at, expected_return_date, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$documentId, $borrowerId, $borrowedAt, $expectedReturn, $notes ?: null, currentUserId()]);

        $stmt = $pdo->prepare('UPDATE tnf_documents SET is_borrowed = 1, updated_at = NOW() WHERE id = ?');
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
    apiRequireTnfAccess();

    $documentId = (int) ($_POST['document_id'] ?? 0);
    $returnNotes = cleanValue($_POST['return_notes'] ?? '');
    if (!$documentId) {
        sendError('Missing document id.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE tnf_borrowing_records
             SET returned_at = NOW(), return_notes = ?, returned_by = ?
             WHERE document_id = ? AND returned_at IS NULL
             ORDER BY borrowed_at DESC
             LIMIT 1'
        );
        $stmt->execute([$returnNotes ?: null, currentUserId(), $documentId]);

        $stmt = $pdo->prepare('UPDATE tnf_documents SET is_borrowed = 0, updated_at = NOW() WHERE id = ?');
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
function handleBorrowersSearch(): void {
    global $pdo;
    $query = cleanValue($_GET['q'] ?? '');
    $type  = cleanValue($_GET['type'] ?? '');

    $conditions = [];
    $params = [];

    if ($query !== '') {
        $conditions[] = '(LOWER(name) LIKE LOWER(?) OR lrn LIKE ?)';
        $params[] = '%' . $query . '%';
        $params[] = '%' . $query . '%';
    }
    if ($type !== '') {
        $conditions[] = 'borrower_type = ?';
        $params[] = $type;
    }

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $stmt = $pdo->prepare(
        "SELECT * FROM library_borrowers {$where} ORDER BY name ASC LIMIT 20"
    );
    $stmt->execute($params);
    sendSuccess($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleBorrowersAdd(): void {
    global $pdo;
    apiRequireLogin();

    $name          = cleanValue($_POST['name'] ?? '');
    $type          = in_array($_POST['borrower_type'] ?? '', ['school','individual']) ? $_POST['borrower_type'] : 'individual';
    $lrn           = cleanValue($_POST['lrn'] ?? '');
    $contact       = cleanValue($_POST['contact'] ?? '');
    $contactPerson = cleanValue($_POST['contact_person'] ?? '');

    if ($name === '') {
        sendError('Borrower name is required.');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO library_borrowers (borrower_type, lrn, name, contact, contact_person)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             borrower_type = VALUES(borrower_type),
             lrn = VALUES(lrn),
             contact = VALUES(contact),
             contact_person = VALUES(contact_person)'
        );
        $stmt->execute([
            $type, $lrn ?: null, $name,
            $contact ?: null, $contactPerson ?: null,
        ]);
        $id = (int) $pdo->lastInsertId();
        if (!$id) {
            $id = (int) $pdo->query(
                "SELECT id FROM library_borrowers WHERE name = " . $pdo->quote($name) . " LIMIT 1"
            )->fetchColumn();
        }
        sendSuccess(['id' => $id, 'name' => $name, 'borrower_type' => $type], 'Borrower saved.');
    } catch (Throwable $e) {
        sendError('Failed to save borrower: ' . $e->getMessage(), 500);
    }
}