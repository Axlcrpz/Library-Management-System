<?php
/**
 * Global error safety net — included once from auth.php.
 *
 * Per-endpoint try/catch still handles the common cases; this only catches what
 * slips through (uncaught exceptions, fatals). It logs to a dated file under
 * storage/logs/ (denied from the web by the Apache storage rules) and returns a
 * generic 500 instead of a white screen or a leaked stack trace.
 */

if (PHP_SAPI === 'cli') return;   // cron has its own handling; phpunit must not be hijacked

if (!defined('LMS_LOG_DIR')) {
    define('LMS_LOG_DIR', __DIR__ . '/../storage/logs');
}

/** Append a line to today's app log (and the server log) — best effort, never throws. */
function lms_log(string $level, string $message): void {
    $dir = LMS_LOG_DIR;
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ': ' . $message . "\n";
    @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    error_log('[library_sys] ' . $message);
}

set_exception_handler(static function (\Throwable $e): void {
    lms_log('error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    $isJson = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'application/json') !== false) { $isJson = true; }
    }
    echo $isJson
        ? json_encode(['success' => false, 'message' => 'An unexpected error occurred. Please try again.'])
        : 'An unexpected error occurred. Please try again.';
});

register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        lms_log('fatal', "{$e['message']} @ {$e['file']}:{$e['line']}");
        if (!headers_sent()) { http_response_code(500); }
    }
});
