<?php
/**
 * Borrow-lifecycle E2E — runs in CI against a disposable MySQL + a `php -S` instance
 * of the app. Asserts the core circulation invariants end-to-end:
 *   authentication · request (no stock change) · approval (stock decrement) ·
 *   due-date assignment · return (stock restoration) · overdue fine accrual ·
 *   insufficient-stock guard. Cleans up its own test data; exits non-zero on the
 *   first violated invariant.
 *
 *   php tests/e2e/borrow_lifecycle.php http://127.0.0.1:8080 admin@e2e.test 'adminadmin!'
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit("CLI only.\n"); }

$base  = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$email = $argv[2] ?? getenv('E2E_USER') ?: 'admin@e2e.test';
$pass  = $argv[3] ?? getenv('E2E_PASS') ?: 'adminadmin!';

$jar   = tempnam(sys_get_temp_dir(), 'e2e_');
$fails = 0;
$csrf  = '';

function http(string $base, string $jar, string $method, string $path, array $opt = []): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $opt['follow'] ?? false, CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $opt['headers'] ?? [],
    ]);
    if (isset($opt['body'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['body']); }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string) $body];
}
function api(string $base, string $jar, string $csrf, string $action, array $fields): array {
    [, $b] = http($base, $jar, 'POST', '/api/library_handler.php', [
        'headers' => ['Content-Type: application/x-www-form-urlencoded', "X-CSRF-Token: {$csrf}"],
        'body'    => http_build_query(array_merge(['action' => $action], $fields)),
    ]);
    return json_decode($b, true) ?: [];
}
function getJson(string $base, string $jar, string $qs): array {
    [, $b] = http($base, $jar, 'GET', '/api/library_handler.php?' . $qs);
    return json_decode($b, true) ?: [];
}
function ok(bool $cond, string $label, string $detail = ''): void {
    global $fails;
    echo ($cond ? '  PASS  ' : '  FAIL  ') . $label . ($cond || $detail === '' ? '' : "  — {$detail}") . "\n";
    if (!$cond) { $fails++; }
}
function availableOf(string $base, string $jar, int $bookId, string $q): ?int {
    $list = getJson($base, $jar, 'action=books_get&per_page=50&q=' . rawurlencode($q));
    foreach (($list['data'] ?? []) as $r) {
        if ((int) ($r['id'] ?? 0) === $bookId) { return (int) $r['quantity_available']; }
    }
    return null;
}
function findRecord(string $base, string $jar, int $id): ?array {
    $list = getJson($base, $jar, 'action=book_borrow_requests_get');
    foreach (($list['data'] ?? []) as $r) {
        if ((int) ($r['id'] ?? 0) === $id) { return $r; }
    }
    return null;
}

echo "Borrow lifecycle E2E → {$base}\n";

// ── Authenticate ──
http($base, $jar, 'GET', '/login.php');
[$c, $b] = http($base, $jar, 'POST', '/login.php', [
    'follow'  => true,
    'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    'body'    => http_build_query(['mode' => 'login', 'email' => $email, 'password' => $pass]),
]);
if (preg_match('/name="csrf-token" content="([^"]+)"/', $b, $m)) { $csrf = $m[1]; }
ok($csrf !== '', 'authenticate as admin', "http {$c}");
if ($csrf === '') { @unlink($jar); exit(1); }

$tag = '__E2E__ ' . date('His') . ' ' . bin2hex(random_bytes(2));

// ── Main path: request → approve → return ──
$add    = api($base, $jar, $csrf, 'books_add', ['title' => $tag, 'quantity_total' => 5, 'quantity_available' => 5]);
$bookId = (int) ($add['data']['id'] ?? 0);
ok($bookId > 0, 'create test book (qty 5)');
ok(availableOf($base, $jar, $bookId, $tag) === 5, 'initial available = 5');

$req      = api($base, $jar, $csrf, 'book_borrow_request_add', [
    'borrower_name' => 'E2E Borrower',
    'items'         => [['book_id' => $bookId, 'quantity' => 2]],
]);
$borrowId = (int) ($req['data']['id'] ?? 0);
ok($borrowId > 0, 'create borrow request (qty 2)', $req['message'] ?? '');
ok(availableOf($base, $jar, $bookId, $tag) === 5, 'INVARIANT: request does not change stock');

$due  = date('Y-m-d H:i:s', strtotime('+7 days'));
$appr = api($base, $jar, $csrf, 'book_borrow_approve', ['id' => $borrowId, 'due_at' => $due]);
ok(($appr['success'] ?? false), 'approve borrow', $appr['message'] ?? '');
ok(availableOf($base, $jar, $bookId, $tag) === 3, 'INVARIANT: approval decrements stock (5 → 3)');

$rec = findRecord($base, $jar, $borrowId);
ok($rec && !empty($rec['due_at']), 'INVARIANT: due date assigned on approval', $rec['due_at'] ?? 'none');

$ret = api($base, $jar, $csrf, 'book_borrow_return', [
    'id' => $borrowId, 'items' => [['book_id' => $bookId, 'returned_quantity' => 2]],
]);
ok(($ret['success'] ?? false), 'process return', $ret['message'] ?? '');
ok(availableOf($base, $jar, $bookId, $tag) === 5, 'INVARIANT: return restores stock (3 → 5)');

// ── Fine path: an overdue return must accrue a fine ──
$tag2  = $tag . ' B';
$add2  = api($base, $jar, $csrf, 'books_add', ['title' => $tag2, 'quantity_total' => 1, 'quantity_available' => 1]);
$book2 = (int) ($add2['data']['id'] ?? 0);
$req2  = api($base, $jar, $csrf, 'book_borrow_request_add', [
    'borrower_name' => 'E2E Borrower', 'items' => [['book_id' => $book2, 'quantity' => 1]],
]);
$borrow2 = (int) ($req2['data']['id'] ?? 0);
api($base, $jar, $csrf, 'book_borrow_approve', ['id' => $borrow2, 'due_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
api($base, $jar, $csrf, 'book_borrow_return', ['id' => $borrow2, 'items' => [['book_id' => $book2, 'returned_quantity' => 1]]]);
$rec2 = findRecord($base, $jar, $borrow2);
ok($rec2 && (float) ($rec2['fine_amount'] ?? 0) > 0, 'INVARIANT: overdue return accrues a fine', 'fine=' . ($rec2['fine_amount'] ?? 'null'));

// ── Guard: approval must be blocked when stock is insufficient ──
$req3    = api($base, $jar, $csrf, 'book_borrow_request_add', [
    'borrower_name' => 'E2E Borrower', 'items' => [['book_id' => $bookId, 'quantity' => 999]],
]);
$borrow3 = (int) ($req3['data']['id'] ?? 0);
$appr3   = api($base, $jar, $csrf, 'book_borrow_approve', ['id' => $borrow3, 'due_at' => $due]);
ok(!($appr3['success'] ?? true), 'INVARIANT: approval blocked when stock insufficient', $appr3['message'] ?? '');
ok(availableOf($base, $jar, $bookId, $tag) === 5, 'INVARIANT: blocked approval left stock unchanged');

// ── Cleanup (best-effort; the whole DB is disposable anyway) ──
api($base, $jar, $csrf, 'book_borrow_reject', ['id' => $borrow3]);
$del = api($base, $jar, $csrf, 'books_delete', ['id' => $bookId]);
api($base, $jar, $csrf, 'books_delete', ['id' => $book2]);
ok(($del['success'] ?? false), 'cleanup: delete endpoint works');

@unlink($jar);
echo $fails ? "\n{$fails} invariant(s) FAILED\n" : "\nALL BORROW-LIFECYCLE INVARIANTS HELD\n";
exit($fails ? 1 : 0);
