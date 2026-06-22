<?php
/**
 * Liveness/readiness probe for uptime monitors, load balancers, and the Docker
 * healthcheck. Returns 200 when the app process is up AND the database answers;
 * 503 otherwise. Deliberately exposes nothing sensitive and requires no auth.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$started = microtime(true);

// config/database.php emits a bare 503 + plain text if it cannot connect, which is
// itself a valid "not ready" signal. Suppress that path so we can answer in JSON.
$dbUp = false;
try {
    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'library_sys';
    $user = getenv('DB_USER') ?: 'lms_user';
    $pass = getenv('DB_PASS') ?: 'lms_pass';
    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
    );
    $pdo->query('SELECT 1');
    $dbUp = true;
} catch (Throwable $e) {
    error_log('[health] db check failed: ' . $e->getMessage());
}

http_response_code($dbUp ? 200 : 503);
echo json_encode([
    'status' => $dbUp ? 'ok' : 'degraded',
    'db'     => $dbUp ? 'up' : 'down',
    'ms'     => round((microtime(true) - $started) * 1000),
]);
