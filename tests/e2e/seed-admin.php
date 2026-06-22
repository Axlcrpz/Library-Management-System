<?php
/**
 * Seeds a known admin for the E2E run, using the SAME env-driven PDO the app uses
 * (so the bcrypt hash is produced by the same PHP that will verify it). Idempotent.
 *
 *   DB_HOST=127.0.0.1 DB_NAME=library_sys DB_USER=lms_user DB_PASS=lms_pass \
 *   E2E_USER=admin@e2e.test E2E_PASS='adminadmin!' php tests/e2e/seed-admin.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only.\n"); }

require __DIR__ . '/../../config/database.php';   // dies 503 (non-zero) if DB unreachable

// Ensure the `users` table exists (single statement → safe via PDO::exec), so CI
// needs no mysql client. Everything else is created by ensureLibrarySchema() on the
// first API request.
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

$user = getenv('E2E_USER') ?: 'admin@e2e.test';
$pass = getenv('E2E_PASS') ?: 'adminadmin!';
$hash = password_hash($pass, PASSWORD_DEFAULT);

$pdo->prepare(
    "INSERT INTO users (username, password, full_name, role, status, is_active, classification)
     VALUES (?, ?, 'E2E Admin', 'admin', 'approved', 1, 'individual')
     ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'admin', status = 'approved', is_active = 1"
)->execute([$user, $hash]);

echo "seeded E2E admin: {$user}\n";
