<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid request.');
}

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';
if ($id > 0 && $action === 'approve') {
    $role = $_POST['role'] ?? 'viewer';
    $role = in_array($role, ['admin', 'staff', 'viewer'], true) ? $role : 'viewer';
    $stmt = $pdo->prepare("UPDATE users SET status = 'approved', is_active = 1, role = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$role, $id]);
} elseif ($id > 0 && $action === 'reject') {
    $stmt = $pdo->prepare("UPDATE users SET status = 'rejected', is_active = 0 WHERE id = ? AND status = 'pending'");
    $stmt->execute([$id]);
}
header('Location: index.php');
exit;
