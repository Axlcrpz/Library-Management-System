<?php
/**
 * Global security headers — included once from auth.php so every entry point
 * (pages + the JSON API) emits them. Safe to call before any body output because
 * the entry points start an output buffer (ob_start) first.
 *
 * CSP note: the app uses inline <script>/<style>/onclick and a fixed set of CDNs,
 * so the policy intentionally allows 'unsafe-inline' + those exact CDN origins.
 * Tightening to a nonce-based CSP (dropping 'unsafe-inline') is the recommended
 * follow-up. If a header ever breaks something in the field, set CSP_ENFORCE to
 * false below to switch to report-only mode without removing the protection.
 */

if (PHP_SAPI === 'cli') return;     // no HTTP headers under CLI (cron / phpunit)
if (headers_sent()) return;

const CSP_ENFORCE = true;           // false → Content-Security-Policy-Report-Only

// HTTPS directly, or terminated by a trusted proxy / Cloudflare Tunnel in front.
$lmsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

header('X-Frame-Options: SAMEORIGIN');                       // clickjacking (legacy)
header('X-Content-Type-Options: nosniff');                   // MIME sniffing
header('Referrer-Policy: strict-origin-when-cross-origin');  // referrer leakage
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)'); // QR scanner needs camera

$lmsCsp = "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://unpkg.com; "
    . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
    . "img-src 'self' data: https:; "
    . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
    . "connect-src 'self'; "
    . "worker-src 'self' blob:; "
    . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'; object-src 'none'";

header((CSP_ENFORCE ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ') . $lmsCsp);

if ($lmsHttps) {
    // 1 year; safe because TLS is terminated at the edge in production.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
