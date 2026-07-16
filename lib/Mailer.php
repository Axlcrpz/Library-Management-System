<?php
declare(strict_types=1);

namespace Lib;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Minimal, dependency-free SMTP mailer.
 *
 * Speaks SMTP with STARTTLS (port 587) or implicit TLS (port 465) and
 * AUTH LOGIN — the exact combination Gmail requires with an App Password.
 * Deliberately self-contained: the deployment pipeline does not run
 * `composer install`, so a vendored library would silently go missing
 * from the production image.
 *
 * Configuration resolution order (first non-empty wins per key):
 *   1. Environment variables:   SMTP_HOST, SMTP_PORT, SMTP_SECURE,
 *                               SMTP_USER, SMTP_PASS, SMTP_FROM_EMAIL,
 *                               SMTP_FROM_NAME
 *   2. library_settings table:  smtp_host, smtp_port, smtp_secure,
 *                               smtp_user, smtp_pass, smtp_from_email,
 *                               smtp_from_name  (managed from Settings → Notifications)
 */
final class Mailer
{
    private string $host;
    private int    $port;
    private string $secure;     // 'tls' (STARTTLS) or 'ssl' (implicit TLS)
    private string $user;
    private string $pass;
    private string $fromEmail;
    private string $fromName;
    private int    $timeout;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        string $host, int $port, string $secure,
        string $user, string $pass,
        string $fromEmail = '', string $fromName = '',
        int $timeout = 15
    ) {
        $this->host      = trim($host);
        $this->port      = $port ?: 587;
        $this->secure    = strtolower(trim($secure)) === 'ssl' ? 'ssl' : 'tls';
        $this->user      = trim($user);
        $this->pass      = $pass;
        $this->fromEmail = trim($fromEmail) ?: trim($user);
        $this->fromName  = trim($fromName);
        $this->timeout   = max(5, $timeout);
    }

    /** Build a Mailer from env vars, falling back to library_settings. */
    public static function fromConfig(PDO $pdo): self
    {
        $settings = [];
        try {
            $stmt = $pdo->query(
                "SELECT setting_key, setting_value FROM library_settings
                 WHERE setting_key LIKE 'smtp\\_%'"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[$row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable) {
            // Settings table absent (fresh install mid-migration) — env only.
        }

        $pick = static function (string $env, string $key) use ($settings): string {
            $v = getenv($env);
            if ($v !== false && trim($v) !== '') return trim($v);
            return trim($settings[$key] ?? '');
        };

        return new self(
            $pick('SMTP_HOST', 'smtp_host'),
            (int) ($pick('SMTP_PORT', 'smtp_port') ?: 587),
            $pick('SMTP_SECURE', 'smtp_secure') ?: 'tls',
            $pick('SMTP_USER', 'smtp_user'),
            $pick('SMTP_PASS', 'smtp_pass'),
            $pick('SMTP_FROM_EMAIL', 'smtp_from_email'),
            $pick('SMTP_FROM_NAME', 'smtp_from_name') ?: 'SDO Quirino Library'
        );
    }

    /** True when enough configuration exists to attempt a send. */
    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->pass !== '';
    }

    /**
     * Send a MIME multipart (HTML + plain text) message.
     * Throws RuntimeException with a server-log-safe message on failure.
     */
    public function send(string $toEmail, string $toName, string $subject, string $html, string $text = ''): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SMTP is not configured (missing host, username, or password).');
        }
        $toEmail = self::cleanAddress($toEmail);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Invalid recipient address.');
        }
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags(
                preg_replace('/<br\s*\/?>|<\/p>|<\/div>/i', "\n", $html)
            ), ENT_QUOTES, 'UTF-8'));
        }

        try {
            $this->connect();
            $this->authenticate();

            $this->command('MAIL FROM:<' . self::cleanAddress($this->fromEmail) . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);
            $this->write($this->buildMessage($toEmail, $toName, $subject, $html, $text) . "\r\n.");
            $this->expect([250], 'end of DATA');
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    // ── SMTP conversation ────────────────────────────────────────────────────

    private function connect(): void
    {
        $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'SNI_enabled'      => true,
        ]]);
        $sock = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("SMTP connection to {$this->host}:{$this->port} failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, $this->timeout);
        $this->socket = $sock;

        $this->expect([220], 'greeting');
        $this->ehlo();

        if ($this->secure === 'tls') {
            $this->command('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed (TLS handshake error).');
            }
            $this->ehlo();   // capabilities must be re-requested after TLS
        }
    }

    private function ehlo(): void
    {
        $name = gethostname() ?: 'lms.local';
        $this->command('EHLO ' . $name, [250]);
    }

    private function authenticate(): void
    {
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->user), [334]);
        try {
            $this->command(base64_encode($this->pass), [235]);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                'SMTP authentication failed — check the username and app password. Server said: ' . $e->getMessage()
            );
        }
    }

    private function command(string $line, array $expectCodes): string
    {
        $this->write($line);
        return $this->expect($expectCodes, $line);
    }

    private function write(string $data): void
    {
        if (!$this->socket || @fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('SMTP connection lost while writing.');
        }
    }

    /** Read a (possibly multiline) reply and assert its status code. */
    private function expect(array $codes, string $context): string
    {
        $reply = '';
        do {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = $this->socket ? stream_get_meta_data($this->socket) : ['timed_out' => true];
                throw new RuntimeException(
                    ($meta['timed_out'] ?? false)
                        ? "SMTP timeout waiting for reply to: {$context}"
                        : "SMTP connection closed unexpectedly after: {$context}"
                );
            }
            $reply .= $line;
        } while (isset($line[3]) && $line[3] === '-');   // "250-..." means more lines follow

        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $codes, true)) {
            $detail = trim(preg_replace('/\s+/', ' ', $reply));
            throw new RuntimeException("Unexpected SMTP reply ({$detail})");
        }
        return $reply;
    }

    private function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── Message construction ─────────────────────────────────────────────────

    private function buildMessage(string $toEmail, string $toName, string $subject, string $html, string $text): string
    {
        $boundary = 'b' . bin2hex(random_bytes(16));
        $from     = self::cleanAddress($this->fromEmail);
        $headers  = [
            'Date: ' . date('r'),
            'From: ' . self::formatAddress($from, $this->fromName),
            'To: ' . self::formatAddress($toEmail, $toName),
            'Subject: ' . self::encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'lms.local') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: SDO-LMS',
        ];

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text));
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html));
        $body .= "--{$boundary}--";

        // Dot-stuffing: a leading "." on any line would terminate DATA early.
        $raw = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $raw);
    }

    /** Strip CR/LF (header-injection guard) and surrounding whitespace. */
    private static function cleanAddress(string $addr): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $addr));
    }

    private static function formatAddress(string $email, string $name): string
    {
        $name = trim(str_replace(["\r", "\n", "\0", '"'], '', $name));
        return $name === '' ? $email : self::encodeHeader($name) . ' <' . $email . '>';
    }

    /** RFC 2047 encode when the value contains non-ASCII characters. */
    private static function encodeHeader(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
