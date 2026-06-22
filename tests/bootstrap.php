<?php
/**
 * PHPUnit bootstrap.
 *
 * Starts a CLI session BEFORE requiring auth.php so that auth.php's own
 * session_start() is skipped (it only starts one when none is active) — this
 * keeps the global auth functions available for testing without any "headers
 * already sent" noise. config/error_handler.php and config/security_headers.php
 * both no-op under CLI, so requiring auth.php here is side-effect free.
 */

require __DIR__ . '/../vendor/autoload.php';

@ini_set('session.save_path', sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../auth.php';
