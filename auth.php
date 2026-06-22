<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie: HttpOnly blocks JS theft (XSS), SameSite=Lax
    // mitigates CSRF, strict mode prevents fixation. Secure auto-enables only on
    // HTTPS so local HTTP (XAMPP) keeps working.
    if (PHP_SAPI !== 'cli') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        @ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $https,
        ]);
    }
    session_start();
}

// Install the global error safety net + security headers for every entry point
// that includes auth.php (all pages and the JSON API). Both no-op under CLI.
require_once __DIR__ . '/config/error_handler.php';
require_once __DIR__ . '/config/security_headers.php';

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

function userCan(string $action): bool {
    $role = $_SESSION['role'] ?? 'viewer';
    if ($role === 'admin') return true;
    $staffActions = ['view', 'read', 'borrow', 'return', 'approve', 'reject', 'scan'];
    if ($role === 'staff') return in_array($action, $staffActions, true);
    return in_array($action, ['view', 'read'], true);
}