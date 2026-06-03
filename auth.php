<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (($_SESSION['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        exit('Access denied: admin privileges required.');
    }
}

function require_staff(): void {
    require_login();
    $role = $_SESSION['role'] ?? 'viewer';
    if (!in_array($role, ['admin', 'staff'], true)) {
        http_response_code(403);
        exit('Access denied: staff privileges required.');
    }
}

function require_role(array $roles): void {
    require_login();
    $role = $_SESSION['role'] ?? 'viewer';
    if (!in_array($role, $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    } 
}

function getCurrentUser(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role' => $_SESSION['role'] ?? 'viewer',
    ];
}

function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(?string $token): bool {
    return !empty($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
