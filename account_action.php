<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

/*
|--------------------------------------------------------------------------
| SAFE INPUT HANDLING (POST ONLY — consistent with CSRF + redirect flow)
|--------------------------------------------------------------------------
*/
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid user ID.');
}

/*
|--------------------------------------------------------------------------
| APPROVE USER
|--------------------------------------------------------------------------
*/
if ($action === 'approve') {

    $role = $_POST['role'] ?? 'viewer';
    $role = in_array($role, ['admin', 'staff', 'viewer'], true) ? $role : 'viewer';

    $stmt = $pdo->prepare("
        UPDATE users 
        SET status = 'approved', is_active = 1, role = ?
        WHERE id = ? AND status = 'pending'
    ");

    $stmt->execute([$role, $id]);

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| REJECT USER
|--------------------------------------------------------------------------
*/
elseif ($action === 'reject') {

    $stmt = $pdo->prepare("
        UPDATE users 
        SET status = 'rejected', is_active = 0
        WHERE id = ? AND status = 'pending'
    ");

    $stmt->execute([$id]);

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| INVALID ACTION
|--------------------------------------------------------------------------
*/
else {
    http_response_code(400);
    exit('Unknown action.');
}