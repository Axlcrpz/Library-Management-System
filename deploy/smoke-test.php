<?php
/**
 * Post-deploy smoke test — run against STAGING before flipping production, and
 * again against production right after go-live. Exercises the real critical path
 * over HTTP (no DB access needed): health → login → CSRF → catalog write/read +
 * pagination → cleanup. Exits 0 on success, non-zero on the first failure.
 *
 *   php deploy/smoke-test.php https://staging.example.org admin@example.org 'password'
 *   SMOKE_USER=admin@example.org SMOKE_PASS=secret php deploy/smoke-test.php https://staging.example.org
 *
 * It creates and then deletes a throwaway "__SMOKE__" book, so it is safe to run
 * against a live system.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only.\n"); }

$base  = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$email = $argv[2] ?? getenv('SMOKE_USER') ?: '';
$pass  = $argv[3] ?? getenv('SMOKE_PASS') ?: '';
if ($email === '' || $pass === '') {
    fwrite(STDERR, "Usage: php deploy/smoke-test.php <base-url> <email> <password>\n");
    exit(2);
}

$jar   = tempnam(sys_get_temp_dir(), 'smoke_');
$fails = 0;

function req(string $base, string $jar, string $method, string $path, array $opts = []): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => $opts['follow'] ?? false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
    ]);
    if (isset($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}

function step(bool $ok, string $label, string $detail = ''): void {
    global $fails;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . ($ok || $detail === '' ? '' : "  — {$detail}") . "\n";
    if (!$ok) { $fails++; }
}

echo "Smoke test → {$base}\n";

// 1) Health
[$c, $b] = req($base, $jar, 'GET', '/health.php');
step($c === 200 && str_contains($b, '"status":"ok"'), 'health endpoint returns ok', "http {$c}");

// 2) Establish a session, then log in
req($base, $jar, 'GET', '/login.php');
[$c, $b] = req($base, $jar, 'POST', '/login.php', [
    'follow'  => true,
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    'body'    => http_build_query(['mode' => 'login', 'email' => $email, 'password' => $pass]),
]);
// A successful login lands on index.php (which carries the CSRF meta tag).
$loggedIn = preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m) === 1;
step($loggedIn, 'login succeeds and returns an authenticated page', "http {$c}");
$csrf = $loggedIn ? $m[1] : '';
if (!$loggedIn) { cleanup($jar); exit($fails ? 1 : 0); }

// 3) Create a throwaway catalog entry
$title = '__SMOKE__ ' . date('Ymd-His');
[$c, $b] = req($base, $jar, 'POST', '/api/library_handler.php', [
    'headers' => ['Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: {$csrf}"],
    'body'    => http_build_query([
        'action' => 'books_add', 'title' => $title,
        'quantity_total' => 2, 'quantity_available' => 2,
    ]),
]);
$add = json_decode($b, true);
$bookId = (int) ($add['data']['id'] ?? 0);
step(($add['success'] ?? false) && $bookId > 0, 'CSRF-protected catalog write (books_add)', "http {$c}");

// 4) Read it back via the new paginated + searchable endpoint (verifies meta too)
[$c, $b] = req($base, $jar, 'GET', '/api/library_handler.php?action=books_get&per_page=5&q=' . rawurlencode('__SMOKE__'));
$list = json_decode($b, true);
$found = false;
foreach (($list['data'] ?? []) as $row) { if ((int) ($row['id'] ?? 0) === $bookId) { $found = true; } }
step(($list['success'] ?? false) && $found, 'paginated search returns the new book', "http {$c}");
step(isset($list['meta']['total'], $list['meta']['page']), 'pagination meta is present', json_encode($list['meta'] ?? null));

// 4b) Inventory aggregate endpoint returns the expected shape
[$c, $b] = req($base, $jar, 'GET', '/api/library_handler.php?action=inventory_stats');
$stats = json_decode($b, true);
step(
    ($stats['success'] ?? false) && isset($stats['data']['totals']['titles'], $stats['data']['categories']['all']),
    'inventory_stats returns totals + categories',
    "http {$c}"
);

// 5) Cleanup — delete the throwaway entry
if ($bookId > 0) {
    [$c, $b] = req($base, $jar, 'POST', '/api/library_handler.php', [
        'headers' => ['Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: {$csrf}"],
        'body'    => http_build_query(['action' => 'books_delete', 'id' => $bookId]),
    ]);
    $del = json_decode($b, true);
    step(($del['success'] ?? false), 'cleanup (books_delete) removes the throwaway book', "http {$c}");
}

cleanup($jar);
echo $fails ? "\n{$fails} check(s) FAILED\n" : "\nALL SMOKE CHECKS PASSED\n";
exit($fails ? 1 : 0);

function cleanup(string $jar): void { @unlink($jar); }
