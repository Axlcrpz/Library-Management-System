<?php
/**
 * Maintenance sweep — run every 5 minutes via system scheduler.
 *
 * Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\library_sys\cron.php
 *   Trigger:   every 5 minutes
 *
 * Linux/macOS crontab:
 *   * /5 * * * * /usr/bin/php /path/to/library_sys/cron.php >> /var/log/library_cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/config/database.php';

// Pull in function definitions only — the CLI guard in library_handler.php
// stops execution before the HTTP bootstrap runs.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/api/library_handler.php';

try {
    $t = microtime(true);
    ensureLibrarySchema($pdo);
    purgeExpiredTrash($pdo);
    resMaintenanceSweep($pdo);
    $enriched = discoveryEnrichDrain($pdo, 10);   // build related-book intelligence
    $back = discoveryBackfillBooks($pdo, 8);      // backfill metadata for existing books
    try { $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable) {}
    $ms = round((microtime(true) - $t) * 1000);
    echo '[' . date('Y-m-d H:i:s') . "] Sweep OK ({$ms}ms, enriched {$enriched}, backfilled {$back['enriched']})\n";
} catch (Throwable $e) {
    echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}
