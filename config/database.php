<?php
// Credentials come from the environment in production (see .env / docker-compose).
// Fallbacks target the scoped `lms_user` (NOT root) so the app never connects with
// super-user rights even if the env is unset.
$host     = getenv('DB_HOST') ?: 'db';
$dbname   = getenv('DB_NAME') ?: 'library_sys';
$username = getenv('DB_USER') ?: 'lms_user';
$password = getenv('DB_PASS') ?: 'lms_pass';
$charset  = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Never leak connection internals (host/user) to the client.
    error_log('[library_sys] DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Service temporarily unavailable. Please try again shortly.');
}
