<?php
ob_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/Mailer.php';

if (is_logged_in()) { header('Location: index.php'); exit; }

define('PROFILE_DIR',  __DIR__ . '/storage/profiles/');
define('PROFILE_PATH', 'storage/profiles/');

// ── Auto-migrate: add identity columns if not present ─────────────────────────
// Best-effort: a migration hiccup must never take the login page down — the app
// self-heals fully on the next API request (ensureLibrarySchema owns the schema).
(function(PDO $pdo): void {
    try {
    // Guarantee the users table on databases initialized without deploy/seed.sql
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        full_name VARCHAR(255) NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'viewer',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        classification VARCHAR(50) NOT NULL DEFAULT 'individual',
        contact VARCHAR(50) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $r) $cols[$r['Field']] = true;
    foreach ([
        'lrn'              => "VARCHAR(12)  NULL",
        'school_name'      => "VARCHAR(255) NULL",
        'grade_level'      => "VARCHAR(100) NULL",
        'contact'          => "VARCHAR(50)  NULL",
        'profile_image'    => "VARCHAR(500) NULL",
        'classification'   => "VARCHAR(20)  NOT NULL DEFAULT 'personal'",
        'institutional_id' => "VARCHAR(50)  NULL",
    ] as $col => $def) {
        if (isset($cols[$col])) continue;
        try { $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def"); } catch (Throwable) {}
    }
    // Extend library_borrowers if it already exists
    try {
        $bc = [];
        foreach ($pdo->query('SHOW COLUMNS FROM library_borrowers') as $r) $bc[$r['Field']] = true;
        foreach ([
            'user_id'       => 'INT NULL',
            'lrn'           => 'VARCHAR(12) NULL',
            'school_name'   => 'VARCHAR(255) NULL',
            'grade_level'   => 'VARCHAR(100) NULL',
            'profile_image' => 'VARCHAR(500) NULL',
        ] as $col => $def) {
            if (isset($bc[$col])) continue;
            try { $pdo->exec("ALTER TABLE library_borrowers ADD COLUMN `$col` $def"); } catch (Throwable) {}
        }
    } catch (Throwable) {}

    // Brute-force throttle log
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_la_lookup (username, ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable) {}
    } catch (Throwable $e) {
        error_log('[library_sys] login auto-migrate skipped: ' . $e->getMessage());
    }
})($pdo);

// ── Helpers ───────────────────────────────────────────────────────────────────
function isDepEdEmail(string $email): bool {
    $email = strtolower(trim($email));
    foreach (['deped.gov.ph', 'depedqui.com', '.gov.ph', '.edu.ph'] as $d) {
        if (str_ends_with($email, $d)) return true;
    }
    return false;
}

function generateOtp(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Validate the institutional ID for the chosen account type.
 * Returns an error message, or '' when the ID is acceptable.
 *   school       → official 6-digit DepEd School ID, unique in the system
 *   child/teen   → 12-digit Student ID Number (LRN format)
 *   everyone else→ Employee/Institutional ID in the organisation's own format
 *                  (lengths vary between institutions — no fixed digit count)
 */
function validateInstitutionalId(PDO $pdo, string $classification, string $id): string {
    switch ($classification) {
        case 'school':
            if (!preg_match('/^\d{6}$/', $id)) {
                return 'DepEd School ID must contain exactly 6 digits.';
            }
            $chk = $pdo->prepare("SELECT id FROM users WHERE institutional_id = ? AND classification = 'school' LIMIT 1");
            $chk->execute([$id]);
            if ($chk->fetch()) {
                return 'That DepEd School ID is already registered. Please sign in instead, or contact the library.';
            }
            return '';
        case 'child':
        case 'teen':
            return preg_match('/^\d{12}$/', $id) ? '' : 'Student ID Number must contain exactly 12 digits.';
        default:
            // Employee / institutional formats vary — require something plausible,
            // not a fixed length: 3–30 chars of letters, digits, dash, slash, dot.
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/\. ]{2,29}$/', trim($id))
                ? '' : 'Please enter a valid Employee ID Number.';
    }
}

/**
 * Email the 6-digit verification code. Returns true only when the SMTP
 * server accepted the message; failures are logged server-side and must
 * be surfaced to the user honestly (never claim "code sent" on failure).
 */
function sendVerificationEmail(PDO $pdo, string $email, string $name, string $otp): bool {
    try {
        $mailer = \Lib\Mailer::fromConfig($pdo);
        if (!$mailer->isConfigured()) {
            error_log('[library_sys] OTP email not sent: SMTP is not configured. '
                . 'Set SMTP_* environment variables or configure it in Settings → Notifications.');
            return false;
        }
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $digits   = implode('', array_map(
            fn($d) => '<span style="display:inline-block;width:34px;padding:10px 0;margin:0 3px;background:#f0f4ff;'
                    . 'border:1px solid #c7d4f5;border-radius:8px;font-size:22px;font-weight:700;color:#003087;">' . $d . '</span>',
            str_split($otp)
        ));
        $html = '
<div style="margin:0;padding:24px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">
    <div style="background:#003087;padding:22px 28px;">
      <div style="color:#ffffff;font-size:17px;font-weight:700;">SDO Quirino Library Management System</div>
      <div style="color:rgba(255,255,255,.65);font-size:12px;margin-top:2px;">Schools Division Office of Quirino &middot; Department of Education</div>
    </div>
    <div style="padding:28px;">
      <p style="font-size:14px;color:#111827;margin:0 0 6px;">Hi ' . $safeName . ',</p>
      <p style="font-size:14px;color:#374151;line-height:1.6;margin:0 0 20px;">
        Use the verification code below to finish creating your library account.
        This code expires in <strong>15 minutes</strong>.</p>
      <div style="text-align:center;margin:0 0 20px;">' . $digits . '</div>
      <p style="font-size:12px;color:#6b7280;line-height:1.6;margin:0;">
        If you did not request this, you can safely ignore this email &mdash; no account will be created.</p>
    </div>
    <div style="padding:14px 28px;background:#f9fafb;border-top:1px solid #f3f4f6;font-size:11px;color:#9ca3af;">
      This is an automated message from the SDO Quirino Library Management System. Please do not reply.</div>
  </div>
</div>';
        $text = "Hi {$name},\n\nYour SDO Quirino Library verification code is: {$otp}\n"
              . "This code expires in 15 minutes.\n\n"
              . "If you did not request this, you can safely ignore this email.";
        $mailer->send($email, $name, 'Your verification code — SDO Quirino Library', $html, $text);
        return true;
    } catch (Throwable $e) {
        error_log('[library_sys] OTP email to ' . $email . ' failed: ' . $e->getMessage());
        return false;
    }
}

function saveProfileImage(): ?string {
    if (empty($_FILES['profile_image']['name'])) return null;
    $f = $_FILES['profile_image'];
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return null;
    $fi   = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $f['tmp_name']);
    finfo_close($fi);
    $exts = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime])) return null;
    if (!is_dir(PROFILE_DIR)) mkdir(PROFILE_DIR, 0755, true);
    $name = 'p_' . uniqid('', true) . '.' . $exts[$mime];
    return move_uploaded_file($f['tmp_name'], PROFILE_DIR . $name) ? PROFILE_PATH . $name : null;
}

// ── POST handlers ──────────────────────────────────────────────────────────────
$message     = '';
$messageType = 'danger';
$mode        = $_POST['mode'] ?? $_GET['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // LOGIN
    if ($mode === 'login') {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $ip       = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);

        // Brute-force lockout: max 5 failures per (username, IP) in 15 minutes.
        // Scoped per-IP so an attacker can't lock a victim out from elsewhere.
        $LOCK_MAX = 5; $LOCK_WIN = 900;
        $lk = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)");
        $lk->execute([$email, $ip, $LOCK_WIN]);
        $recentFails = (int) $lk->fetchColumn();

        if ($email !== '' && $recentFails >= $LOCK_MAX) {
            $message = 'Too many failed attempts. Please wait about 15 minutes before trying again.';
        } else {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if (!$user) {
                $message = 'No account found with that email.';
            } elseif ((int)($user['is_active'] ?? 0) !== 1) {
                $message = 'Your account is inactive. Please contact the administrator.';
            } elseif (($user['status'] ?? '') === 'pending') {
                $message = 'Your account is pending admin approval.';
            } elseif (($user['status'] ?? '') !== 'approved') {
                $message = 'Account status: ' . htmlspecialchars($user['status'] ?? 'unknown') . '. Contact admin.';
            } elseif (!password_verify($password, $user['password'])) {
                $message = 'Incorrect password. Please try again.';
            } else {
                // Success — clear this identity's failure log and sign in.
                try { $pdo->prepare('DELETE FROM login_attempts WHERE username = ? AND ip = ?')->execute([$email, $ip]); } catch (Throwable) {}
                // Prevent session fixation: issue a brand-new session ID on login.
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role']      = $user['role'];
                generateCSRFToken();
                header('Location: index.php');
                exit;
            }
            // Reached only on a failed attempt → record it for throttling.
            try { $pdo->prepare('INSERT INTO login_attempts (username, ip) VALUES (?, ?)')->execute([$email, $ip]); } catch (Throwable) {}
        }
    }

    // REGISTER
    elseif ($mode === 'register') {
        $fullName       = trim($_POST['full_name']       ?? '');
        $email          = trim($_POST['email']           ?? '');
        $password       = $_POST['password']             ?? '';
        $confirm        = $_POST['confirm_password']     ?? '';
        $lrn            = preg_replace('/\D/', '', $_POST['lrn'] ?? '');
        $schoolName     = trim($_POST['school_name']     ?? '');
        $gradeLevel     = trim($_POST['grade_level']     ?? '');
        $contact        = trim($_POST['contact']         ?? '');
        $isDeped        = isDepEdEmail($email);
        $allowedClassifications = ['child','teen','individual','school','professional','private_institution'];
        $classification = in_array($_POST['classification'] ?? '', $allowedClassifications, true)
            ? ($_POST['classification'])
            : 'individual';
        $institutionalId = trim($_POST['institutional_id'] ?? '');
        $instIdError     = $isDeped ? '' : (
            $institutionalId === ''
                ? 'Please enter your institutional ID number — it is required for identity verification.'
                : validateInstitutionalId($pdo, $classification, $institutionalId)
        );

        if (!$fullName || !$email || !$password || !$confirm) {
            $message = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $message = 'Passwords do not match.';
        } elseif ($isDeped && strlen($lrn) !== 12) {
            $message = 'DepEd accounts require a valid 12-digit School LRN.';
        } elseif ($isDeped && !$schoolName) {
            $message = 'Please enter your school or institution name.';
        } elseif ($instIdError !== '') {
            $message = $instIdError;
        } else {
            $chkEmail = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $chkEmail->execute([$email]);
            if ($chkEmail->fetch()) {
                $message = 'That email is already registered. Please sign in instead.';
            } elseif ($isDeped && $lrn) {
                $chkLrn = $pdo->prepare('SELECT id FROM users WHERE lrn = ? LIMIT 1');
                $chkLrn->execute([$lrn]);
                if ($chkLrn->fetch()) {
                    $message = 'That LRN is already linked to an existing account.';
                }
            }

            if (!$message) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $img  = saveProfileImage();

                if ($isDeped) {
                    try {
                        $pdo->beginTransaction();

                        $pdo->prepare("
                            INSERT INTO users
                              (username, password, full_name, role, status, is_active,
                               lrn, school_name, grade_level, contact, profile_image, classification)
                            VALUES (?, ?, ?, 'viewer', 'approved', 1, ?, ?, ?, ?, ?, 'deped')
                        ")->execute([$email, $hash, $fullName,
                            $lrn ?: null, $schoolName ?: null,
                            $gradeLevel ?: null, $contact ?: null, $img]);

                        $uid = (int) $pdo->lastInsertId();

                        // Auto-create permanent borrower profile linked to this user
                        try {
                            $pdo->prepare("
                                INSERT INTO library_borrowers
                                  (name, contact, user_id, lrn, school_name, grade_level, profile_image)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ")->execute([$fullName, $contact ?: null, $uid,
                                $lrn ?: null, $schoolName ?: null,
                                $gradeLevel ?: null, $img]);
                        } catch (Throwable) {
                            // Name may be duplicate; borrower can be linked manually by staff
                        }

                        $pdo->commit();
                        $message     = 'Account created! Your permanent Borrower ID is LRN <strong>' . htmlspecialchars($lrn) . '</strong>. You may now sign in.';
                        $messageType = 'success';
                        $mode        = 'login';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        $message = 'Registration failed. Please try again.';
                    }
                } else {
                    $otp = generateOtp();
                    if (!sendVerificationEmail($pdo, $email, $fullName, $otp)) {
                        $message = 'We couldn\'t send the verification email right now. Please try again in a few minutes, '
                                 . 'or contact the library administrator if the problem continues.';
                        $mode    = 'register';
                    } else {
                        $_SESSION['_otp_pending'] = [
                            'email'            => $email,
                            'password'         => $hash,
                            'full_name'        => $fullName,
                            'contact'          => $contact,
                            'profile_image'    => $img,
                            'classification'   => $classification,
                            'institutional_id' => $institutionalId,
                            'otp'              => $otp,
                            'expires'          => time() + 900,
                            'attempts'         => 0,
                            'last_sent'        => time(),
                        ];
                        $message     = 'A 6-digit code was sent to <strong>' . htmlspecialchars($email) . '</strong>. Enter it below to continue.';
                        $messageType = 'success';
                        $mode        = 'otp';
                    }
                }
            }
        }
        if ($message && $messageType !== 'success') $mode = 'register';
    }

    // OTP VERIFY
    elseif ($mode === 'verify_otp') {
        $entered = trim($_POST['otp'] ?? '');
        $pending = $_SESSION['_otp_pending'] ?? null;
        if (!$pending) {
            $message = 'Session expired. Please register again.';
            $mode    = 'register';
        } elseif (time() > $pending['expires']) {
            unset($_SESSION['_otp_pending']);
            $message = 'Code expired. Please register again.';
            $mode    = 'register';
        } elseif (($pending['attempts'] ?? 0) >= 5) {
            unset($_SESSION['_otp_pending']);
            $message = 'Too many incorrect attempts. Please register again to receive a new code.';
            $mode    = 'register';
        } elseif (!hash_equals((string) $pending['otp'], $entered)) {
            $_SESSION['_otp_pending']['attempts'] = ($pending['attempts'] ?? 0) + 1;
            $left    = 5 - $_SESSION['_otp_pending']['attempts'];
            $message = $left > 0
                ? 'Incorrect code. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' remaining.'
                : 'Incorrect code. That was your last attempt — please register again.';
            $mode    = 'otp';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO users
                      (username, password, full_name, role, status, is_active,
                       contact, profile_image, classification, institutional_id)
                    VALUES (?, ?, ?, 'viewer', 'pending', 0, ?, ?, ?, ?)
                ")->execute([$pending['email'], $pending['password'], $pending['full_name'],
                    $pending['contact'] ?? null, $pending['profile_image'] ?? null,
                    $pending['classification'] ?? 'individual',
                    $pending['institutional_id'] ?: null]);
                unset($_SESSION['_otp_pending']);
                $message     = 'Email verified! Your account is now awaiting admin approval.';
                $messageType = 'success';
                $mode        = 'login';
            } catch (Throwable) {
                $message = 'Registration failed. Please try again.';
                $mode    = 'otp';
            }
        }
    }

    // RESEND OTP
    elseif ($mode === 'resend_otp') {
        $pending = $_SESSION['_otp_pending'] ?? null;
        if (!$pending) {
            $message = 'Session expired. Please register again.';
            $mode    = 'register';
        } elseif (time() - (int) ($pending['last_sent'] ?? 0) < 60) {
            $wait        = 60 - (time() - (int) ($pending['last_sent'] ?? 0));
            $message     = 'Please wait ' . $wait . ' seconds before requesting another code.';
            $mode        = 'otp';
        } else {
            $otp = generateOtp();
            if (sendVerificationEmail($pdo, $pending['email'], $pending['full_name'], $otp)) {
                $_SESSION['_otp_pending']['otp']       = $otp;
                $_SESSION['_otp_pending']['expires']   = time() + 900;
                $_SESSION['_otp_pending']['attempts']  = 0;
                $_SESSION['_otp_pending']['last_sent'] = time();
                $message     = 'A new code has been sent to <strong>' . htmlspecialchars($pending['email']) . '</strong>.';
                $messageType = 'success';
            } else {
                $message = 'We couldn\'t send a new code right now. Please try again in a few minutes.';
            }
            $mode = 'otp';
        }
    }
}

$showRegister = ($mode === 'register');
$showOtp      = ($mode === 'otp');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SDO Quirino Library Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --blue:#003087;--orange:#E87722;--yellow:#F5C400;--green:#2E7D32;
  --border:#d1d5db;--text:#111827;--muted:#6b7280;--light:#9ca3af;
}
html,body{height:100%;font-family:'Inter',system-ui,sans-serif;-webkit-font-smoothing:antialiased}
body{display:flex;min-height:100vh}
.wrap{display:flex;width:100%;min-height:100vh}
.bp{
  flex:1.1;position:relative;display:flex;flex-direction:column;
  align-items:center;justify-content:center;padding:48px 40px;overflow:hidden;background:var(--blue);
}
.bp::before{
  content:'';position:absolute;inset:0;
  background:radial-gradient(ellipse 80% 60% at 20% 10%,rgba(232,119,34,.18) 0%,transparent 60%),
    radial-gradient(ellipse 60% 80% at 80% 90%,rgba(0,100,200,.25) 0%,transparent 60%),
    linear-gradient(160deg,#001d5e 0%,#003087 45%,#004db3 100%);z-index:0;
}
.bp::after{content:'';position:absolute;width:500px;height:500px;border-radius:50%;border:1px solid rgba(255,255,255,.06);top:-120px;right:-120px;z-index:0;}
.bpc{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.05);z-index:0}
.bpc1{width:320px;height:320px;bottom:-80px;left:-80px}
.bpc2{width:180px;height:180px;top:60px;right:60px;background:rgba(232,119,34,.07)}
.bpc3{width:90px;height:90px;bottom:140px;right:40px;background:rgba(255,255,255,.04)}
.bc{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;text-align:center;max-width:480px;width:100%;}
.logos{display:flex;align-items:center;justify-content:center;gap:22px;margin-bottom:34px;}
.logo-wrap{width:112px;height:112px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:50%;box-shadow:0 8px 28px rgba(0,0,0,.18);}
.logo-img{max-width:88px;max-height:88px;width:auto;height:auto;object-fit:contain;display:block;transition:transform .2s ease;}
.logo-img:hover{transform:scale(1.05);}
.ldiv{width:1px;height:64px;background:linear-gradient(to bottom,transparent,rgba(255,255,255,.3),transparent);}
.bflag{display:inline-flex;align-items:center;gap:8px;background:rgba(232,119,34,.2);border:1px solid rgba(232,119,34,.4);border-radius:99px;padding:5px 14px;margin-bottom:18px;}
.bdot{width:7px;height:7px;border-radius:50%;background:var(--orange);animation:pulse 2s ease infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.6;transform:scale(.85)}}
.bflag span{font-size:.68rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--orange)}
.btitle{font-size:1.85rem;font-weight:700;color:#fff;line-height:1.22;letter-spacing:-.02em;margin-bottom:12px}
.btitle .ac{color:var(--orange)}
.bsub{font-size:.84rem;color:rgba(255,255,255,.62);line-height:1.7;margin-bottom:36px;max-width:380px}
.fpills{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-bottom:36px}
.fpill{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:99px;padding:6px 14px;font-size:.72rem;font-weight:500;color:rgba(255,255,255,.8);}
.fpill i{color:var(--orange);font-size:.7rem}
.bfoot{font-size:.67rem;color:rgba(255,255,255,.3);letter-spacing:.04em}
/* Form panel — scrollable for the register view */
.fp{width:480px;flex-shrink:0;background:#fff;display:flex;flex-direction:column;justify-content:center;padding:44px 44px 80px;position:relative;max-height:100vh;overflow-y:auto;}
.fp::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--blue),var(--orange));z-index:1;}
.fhdr{margin-bottom:24px}
.fey{display:inline-flex;align-items:center;gap:6px;font-size:.68rem;font-weight:600;letter-spacing:.07em;text-transform:uppercase;color:var(--orange);margin-bottom:10px;}
.fey::before{content:'';width:16px;height:2px;background:var(--orange);border-radius:99px}
.ftitle{font-size:1.5rem;font-weight:700;color:var(--text);letter-spacing:-.025em;margin-bottom:5px}
.fsub{font-size:.82rem;color:var(--muted)}
.aalert{display:flex;align-items:flex-start;gap:9px;padding:11px 14px;border-radius:9px;font-size:.8rem;font-weight:500;margin-bottom:20px;line-height:1.5;}
.aalert.s{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.aalert.e{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.aalert i{margin-top:1px;flex-shrink:0}
.fg{margin-bottom:14px}
.fg label{display:block;font-size:.76rem;font-weight:600;color:var(--text);margin-bottom:5px}
.iw{position:relative}
.ii{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--light);font-size:.8rem;pointer-events:none}
.fi{width:100%;padding:10px 12px 10px 36px;font-size:.85rem;font-family:inherit;border:1.5px solid var(--border);border-radius:9px;background:#fff;color:var(--text);outline:none;transition:border-color .15s,box-shadow .15s;}
.fi:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,48,135,.08)}
.fi::placeholder{color:var(--light)}
.pwt{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--light);cursor:pointer;font-size:.82rem;padding:2px;line-height:1;}
.pwt:hover{color:var(--text)}
.fmeta{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.rem{display:flex;align-items:center;gap:7px;font-size:.78rem;color:var(--muted);cursor:pointer}
.rem input{width:15px;height:15px;accent-color:var(--blue);cursor:pointer}
.flink{font-size:.78rem;font-weight:500;color:var(--blue);background:none;border:none;cursor:pointer;font-family:inherit;padding:0}
.flink:hover{text-decoration:underline}
.bsub-btn{width:100%;padding:11px;background:var(--blue);color:#fff;border:none;border-radius:9px;font-size:.88rem;font-weight:600;font-family:inherit;cursor:pointer;transition:background .15s,box-shadow .15s;display:flex;align-items:center;justify-content:center;gap:7px;margin-bottom:18px;}
.bsub-btn:hover{background:#002070;box-shadow:0 4px 16px rgba(0,48,135,.3)}
.bsub-btn:active{transform:scale(.99)}
.fdiv{display:flex;align-items:center;gap:12px;margin-bottom:16px;font-size:.73rem;color:var(--light)}
.fdiv::before,.fdiv::after{content:'';flex:1;height:1px;background:#e5e7eb}
.aswitch{text-align:center;font-size:.8rem;color:var(--muted)}
.aswitch button{background:none;border:none;color:var(--blue);font-weight:600;cursor:pointer;font-family:inherit;font-size:.8rem;padding:0}
.aswitch button:hover{text-decoration:underline}
.fr2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.av{display:none}.av.active{display:block}
.ffoot{position:fixed;bottom:0;right:0;width:480px;background:#fff;border-top:1px solid #f3f4f6;padding:10px 44px;text-align:center;font-size:.67rem;color:var(--light);}
/* DepEd identity card */
.deped-card{background:linear-gradient(135deg,#f0f4ff 0%,#e8effc 100%);border:1.5px solid #c7d4f5;border-radius:12px;padding:16px;margin-bottom:16px;}
.deped-card-hdr{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
.deped-card-icon{width:30px;height:30px;background:var(--blue);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.deped-card-icon i{color:#fff;font-size:.75rem;}
.deped-card-title{font-size:.78rem;font-weight:700;color:#003087;line-height:1.2;}
.deped-card-sub{font-size:.68rem;color:#6b7280;}
/* Photo preview */
.photo-row{display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.75);border:1px solid #dde3f5;border-radius:9px;padding:10px;margin-bottom:12px;}
.photo-circle{width:58px;height:58px;border-radius:50%;background:#e5e7eb;border:2px solid #d1d5db;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:border-color .2s;}
.photo-circle img{width:100%;height:100%;object-fit:cover;}
.photo-circle.has-img{border-color:var(--blue);}
.photo-upload-btn{font-size:.72rem;padding:5px 11px;border:1.5px solid #c7d4f5;border-radius:7px;background:#fff;cursor:pointer;color:#374151;font-family:inherit;font-weight:500;transition:border-color .15s,background .15s;}
.photo-upload-btn:hover{border-color:var(--blue);background:#f0f4ff;}
/* LRN field */
.lrn-input{letter-spacing:.15em;font-weight:700;font-size:.95rem;}
/* OTP boxes */
.otp-row{display:flex;gap:8px;justify-content:center;margin-bottom:20px;}
.otp-box{width:46px;height:54px;text-align:center;font-size:1.4rem;font-weight:700;border:1.5px solid var(--border);border-radius:9px;color:var(--text);font-family:inherit;outline:none;transition:border-color .15s;}
.otp-box:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,48,135,.08);}
@media(max-width:860px){.bp{display:none}.fp{width:100%;max-height:none;overflow-y:visible;}.ffoot{width:100%;}}
@media(max-width:400px){.fp{padding:32px 20px 80px}.fr2{grid-template-columns:1fr}.ffoot{padding:10px 20px;}}
</style>
</head>
<body>
<div class="wrap">

  <!-- BRAND PANEL -->
  <div class="bp">
    <div class="bpc bpc1"></div>
    <div class="bpc bpc2"></div>
    <div class="bpc bpc3"></div>
    <div class="bc">
      <div class="logos">
        <div class="ldiv"></div>
        <div class="logo-wrap"><img src="assets/img/sdo-logo.png" alt="SDO Quirino" class="logo-img"></div>
      </div>
      <div class="bflag"><div class="bdot"></div><span>DepEd &mdash; Schools Division of Quirino</span></div>
      <h1 class="btitle">Library Management<br><span class="ac">System</span></h1>
      <p class="bsub">An integrated digital library platform for managing books, documents, borrowing records, and library resources across SDO Quirino schools.</p>
      <div class="fpills">
        <div class="fpill"><i class="fas fa-book"></i> Book Catalog</div>
        <div class="fpill"><i class="fas fa-hand-holding"></i> Borrowing</div>
        <div class="fpill"><i class="fas fa-bookmark"></i> Reservations</div>
        <div class="fpill"><i class="fas fa-chart-bar"></i> Reports</div>
        <div class="fpill"><i class="fas fa-file-lines"></i> Documents</div>
      </div>
      <div class="bfoot">Republic of the Philippines &nbsp;&middot;&nbsp; Department of Education &nbsp;&middot;&nbsp; <?= date('Y') ?></div>
    </div>
  </div>

  <!-- FORM PANEL -->
  <div class="fp" id="formPanel">

    <?php if ($message): ?>
    <div class="aalert <?= $messageType === 'success' ? 's' : 'e' ?>">
      <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
      <span><?= $message ?></span>
    </div>
    <?php endif; ?>

    <!-- ── LOGIN ─────────────────────────────────────────────────────── -->
    <div class="av <?= (!$showRegister && !$showOtp) ? 'active' : '' ?>" id="view-login">
      <div class="fhdr">
        <div class="fey">Secure Access</div>
        <h2 class="ftitle">Welcome</h2>
        <p class="fsub">Sign in to your library account to continue.</p>
      </div>
      <form method="post" autocomplete="on">
        <input type="hidden" name="mode" value="login">
        <div class="fg">
          <label for="l-email">Email or Username</label>
          <div class="iw">
            <i class="fas fa-user ii"></i>
            <input class="fi" type="text" id="l-email" name="email"
                   placeholder="Enter your email or username" autocomplete="username" required>
          </div>
        </div>
        <div class="fg">
          <label for="l-pw">Password</label>
          <div class="iw">
            <i class="fas fa-lock ii"></i>
            <input class="fi" type="password" id="l-pw" name="password"
                   placeholder="Enter your password" autocomplete="current-password" required>
            <button type="button" class="pwt" onclick="togglePw('l-pw',this)"><i class="fas fa-eye-slash"></i></button>
          </div>
        </div>
        <div class="fmeta">
          <label class="rem"><input type="checkbox" name="remember"> Remember me</label>
          <button type="button" class="flink" onclick="switchView('forgot')">Forgot password?</button>
        </div>
        <button type="submit" class="bsub-btn"><i class="fas fa-right-to-bracket"></i> Sign in</button>
      </form>
      <div class="fdiv">or</div>
      <div class="aswitch">Don't have an account? <button onclick="switchView('register')">Create one</button></div>
    </div>

    <!-- ── REGISTER ──────────────────────────────────────────────────── -->
    <div class="av <?= $showRegister ? 'active' : '' ?>" id="view-register">
      <div class="fhdr">
        <div class="fey">New Account</div>
        <h2 class="ftitle">Create account</h2>
        <p class="fsub">Register to access the library system.</p>
      </div>

      <form method="post" id="regForm" enctype="multipart/form-data" onsubmit="return validateRegister()">
        <input type="hidden" name="mode" value="register">

        <!-- Full name -->
        <div class="fg">
          <label for="r-name">Full Name <span style="color:#ef4444">*</span></label>
          <div class="iw">
            <i class="fas fa-id-card ii"></i>
            <input class="fi" type="text" id="r-name" name="full_name"
                   placeholder="Juan dela Cruz" autocomplete="name" required>
          </div>
        </div>

        <!-- Email -->
        <div class="fg">
          <label for="r-email">Email Address <span style="color:#ef4444">*</span></label>
          <div class="iw">
            <i class="fas fa-envelope ii"></i>
            <input class="fi" type="email" id="r-email" name="email"
                   placeholder="you@deped.gov.ph" autocomplete="email" required
                   oninput="updateEmailNotice(this.value)">
          </div>
        </div>

        <!-- Dynamic email notice -->
        <div id="email-notice" style="margin:-2px 0 14px;padding:10px 12px;border-radius:8px;font-size:.75rem;line-height:1.5;background:#f0f4ff;border:1px solid #c7d4f5;color:#374151;">
          <i class="fas fa-circle-info" style="color:#003087;margin-right:5px;"></i>
          DepEd/gov.ph emails are <strong>instantly approved</strong>. Other emails require email verification and admin approval.
        </div>

        <!-- DepEd Identity Section (shown only when DepEd email is detected) -->
        <div id="deped-section" style="display:none;margin-bottom:16px;">
          <div class="deped-card">

            <div class="deped-card-hdr">
              <div class="deped-card-icon"><i class="fas fa-id-badge"></i></div>
              <div>
                <div class="deped-card-title">DepEd Personnel / Learner Profile</div>
                <div class="deped-card-sub">Required for permanent borrower ID</div>
              </div>
            </div>

            <!-- Profile Photo -->
            <div class="photo-row">
              <div class="photo-circle" id="photo-circle">
                <i class="fas fa-user" style="color:#9ca3af;font-size:1.3rem;"></i>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-size:.74rem;font-weight:600;color:#374151;margin-bottom:5px;">
                  Profile Photo <span style="font-weight:400;color:#9ca3af;">(recommended)</span>
                </div>
                <input type="file" id="r-photo" name="profile_image"
                       accept="image/jpeg,image/png,image/webp"
                       onchange="previewPhoto(this)" style="display:none;">
                <button type="button" class="photo-upload-btn"
                        onclick="document.getElementById('r-photo').click()">
                  <i class="fas fa-camera" style="margin-right:5px;"></i>Upload Photo
                </button>
                <div id="photo-label" style="font-size:.67rem;color:#9ca3af;margin-top:4px;">
                  JPG, PNG or WebP &middot; max 5 MB
                </div>
              </div>
            </div>

            <!-- School LRN -->
            <div class="fg" style="margin-bottom:10px;">
              <label for="r-lrn" style="font-size:.75rem;font-weight:700;color:#003087;">
                School LRN <span style="color:#ef4444;">*</span>
                <span style="font-weight:400;color:#6b7280;font-size:.68rem;">&nbsp;12-digit Learner Reference Number</span>
              </label>
              <div class="iw">
                <i class="fas fa-fingerprint ii" style="color:#003087;"></i>
                <input class="fi lrn-input" type="text" id="r-lrn" name="lrn"
                       placeholder="000000000000" maxlength="12" inputmode="numeric"
                       oninput="this.value=this.value.replace(/\D/g,'').slice(0,12);lrnStatus(this.value)"
                       style="padding-right:90px;">
                <span id="lrn-count" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:.7rem;color:var(--light);pointer-events:none;">0/12</span>
              </div>
              <div id="lrn-msg" style="font-size:.7rem;min-height:15px;margin-top:3px;"></div>
            </div>

            <!-- School name -->
            <div class="fg" style="margin-bottom:10px;">
              <label for="r-school" style="font-size:.75rem;font-weight:600;color:#374151;">
                School / Institution <span style="color:#ef4444;">*</span>
              </label>
              <div class="iw">
                <i class="fas fa-school ii"></i>
                <input class="fi" type="text" id="r-school" name="school_name"
                       placeholder="e.g. Maddela National High School">
              </div>
            </div>

            <!-- Grade/Designation + Contact -->
            <div class="fr2">
              <div class="fg" style="margin-bottom:0;">
                <label for="r-grade" style="font-size:.74rem;font-weight:600;color:#374151;">Grade / Designation</label>
                <div class="iw">
                  <i class="fas fa-layer-group ii"></i>
                  <input class="fi" type="text" id="r-grade" name="grade_level"
                         placeholder="e.g. Grade 10 / Teacher I">
                </div>
              </div>
              <div class="fg" style="margin-bottom:0;">
                <label for="r-contact" style="font-size:.74rem;font-weight:600;color:#374151;">Contact Number</label>
                <div class="iw">
                  <i class="fas fa-mobile-screen ii"></i>
                  <input class="fi" type="tel" id="r-contact" name="contact"
                         placeholder="09XXXXXXXXX"
                         oninput="this.value=this.value.replace(/[^\d+\-\s]/g,'').slice(0,15)">
                </div>
              </div>
            </div>

          </div><!-- /deped-card -->
        </div><!-- /deped-section -->

        <!-- Classification (personal email only) — visual card picker -->
        <div id="classification-section" style="display:none;margin-bottom:14px;">
          <label style="display:block;font-size:.75rem;font-weight:600;color:#374151;margin-bottom:8px;">
            Reader Type <span style="color:#ef4444;">*</span>
          </label>
          <div id="cls-cards" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;margin-bottom:10px;">
            <button type="button" class="cls-card" data-cls="child"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">🧒</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">Child</div>
              <div style="font-size:.62rem;color:#9ca3af;">Ages 0–12</div>
            </button>
            <button type="button" class="cls-card" data-cls="teen"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">🧑‍🎓</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">Teen</div>
              <div style="font-size:.62rem;color:#9ca3af;">Ages 13–17</div>
            </button>
            <button type="button" class="cls-card" data-cls="individual"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">🧑‍💼</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">Adult</div>
              <div style="font-size:.62rem;color:#9ca3af;">18 & above</div>
            </button>
            <button type="button" class="cls-card" data-cls="school"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">🏫</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">School</div>
              <div style="font-size:.62rem;color:#9ca3af;">Institution</div>
            </button>
            <button type="button" class="cls-card" data-cls="professional"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">👩‍⚕️</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">Professional</div>
              <div style="font-size:.62rem;color:#9ca3af;">Licensed</div>
            </button>
            <button type="button" class="cls-card" data-cls="private_institution"
              style="border:1.5px solid #e5e7eb;border-radius:10px;padding:9px 6px;background:#fff;cursor:pointer;transition:all .15s;text-align:center;font-family:inherit;">
              <div style="font-size:1.5rem;line-height:1;margin-bottom:4px;">🏢</div>
              <div style="font-size:.7rem;font-weight:700;color:#374151;">Private Org</div>
              <div style="font-size:.62rem;color:#9ca3af;">Institution</div>
            </button>
          </div>
          <!-- Hidden actual field -->
          <input type="hidden" id="r-classification" name="classification" value="individual">
          <div id="cls-hint" style="font-size:.72rem;color:#6b7280;padding:6px 10px;border-radius:7px;background:#f9fafb;border:1px solid #f3f4f6;display:none;"></div>

          <!-- Institutional ID — label/placeholder/rules follow the account type -->
          <div class="fg" style="margin-top:12px;margin-bottom:0;">
            <label for="r-instid" id="instid-label" style="font-size:.75rem;font-weight:600;color:#374151;">
              Institutional ID Number <span style="color:#ef4444">*</span>
            </label>
            <div class="iw">
              <i class="fas fa-id-badge ii"></i>
              <input class="fi" type="text" id="r-instid" name="institutional_id"
                     placeholder="Your official ID number" maxlength="30" autocomplete="off"
                     oninput="instIdInput(this)">
            </div>
            <div id="instid-msg" style="font-size:.7rem;min-height:15px;margin-top:3px;"></div>
          </div>
        </div>
        <style>
          .cls-card.selected{border-color:var(--blue)!important;background:#f0f4ff!important;}
          .cls-card:hover:not(.selected){border-color:#d1d5db!important;background:#f9fafb!important;}
        </style>
        <script>
        // ── Institutional ID rules per account type ─────────────────────────────
        // school      → official 6-digit DepEd School ID (numbers only, unique)
        // child/teen  → 12-digit Student ID Number (numbers only)
        // others      → Employee/Institutional ID in the organisation's own format
        const INST_ID_RULES = {
          school: {
            label: 'DepEd School ID', placeholder: 'e.g. 104300', digitsOnly: true, maxlen: 6,
            hint: 'The school’s official 6-digit DepEd School ID.',
            test: v => /^\d{6}$/.test(v), error: 'DepEd School ID must contain exactly 6 digits.',
          },
          child: {
            label: 'Student ID Number', placeholder: 'e.g. 202512345678', digitsOnly: true, maxlen: 12,
            hint: 'The learner’s official 12-digit Student ID Number.',
            test: v => /^\d{12}$/.test(v), error: 'Student ID Number must contain exactly 12 digits.',
          },
          teen: {
            label: 'Student ID Number', placeholder: 'e.g. 202512345678', digitsOnly: true, maxlen: 12,
            hint: 'The learner’s official 12-digit Student ID Number.',
            test: v => /^\d{12}$/.test(v), error: 'Student ID Number must contain exactly 12 digits.',
          },
          individual: {
            label: 'Employee ID Number', placeholder: 'Your official employee ID', digitsOnly: false, maxlen: 30,
            hint: 'Your official Employee ID as issued by your institution (formats vary).',
            test: v => /^[A-Za-z0-9][A-Za-z0-9\-\/\. ]{2,29}$/.test(v.trim()), error: 'Please enter a valid Employee ID Number.',
          },
          professional: {
            label: 'Employee ID Number', placeholder: 'Your official employee ID', digitsOnly: false, maxlen: 30,
            hint: 'Your official Employee ID as issued by your institution (formats vary).',
            test: v => /^[A-Za-z0-9][A-Za-z0-9\-\/\. ]{2,29}$/.test(v.trim()), error: 'Please enter a valid Employee ID Number.',
          },
          private_institution: {
            label: 'Institutional ID Number', placeholder: 'Your organization’s official ID', digitsOnly: false, maxlen: 30,
            hint: 'Your organization’s official identification / registration number.',
            test: v => /^[A-Za-z0-9][A-Za-z0-9\-\/\. ]{2,29}$/.test(v.trim()), error: 'Please enter a valid Institutional ID Number.',
          },
        };
        function instIdRule() {
          return INST_ID_RULES[document.getElementById('r-classification')?.value] || INST_ID_RULES.individual;
        }
        function updateInstIdField() {
          const r = instIdRule();
          const label = document.getElementById('instid-label');
          const input = document.getElementById('r-instid');
          if (label) label.innerHTML = r.label + ' <span style="color:#ef4444">*</span>';
          if (input) {
            input.placeholder = r.placeholder;
            input.maxLength = r.maxlen;
            input.inputMode = r.digitsOnly ? 'numeric' : 'text';
            if (r.digitsOnly) input.value = input.value.replace(/\D/g, '').slice(0, r.maxlen);
            instIdValidate(input.value, false);
          }
          const msg = document.getElementById('instid-msg');
          if (msg && !input.value) { msg.style.color = '#6b7280'; msg.textContent = r.hint; }
        }
        function instIdInput(el) {
          const r = instIdRule();
          if (r.digitsOnly) el.value = el.value.replace(/\D/g, '').slice(0, r.maxlen);
          instIdValidate(el.value, true);
        }
        function instIdValidate(v, showOk) {
          const r = instIdRule();
          const msg = document.getElementById('instid-msg');
          if (!msg) return !!v && r.test(v);
          if (!v) { msg.style.color = '#6b7280'; msg.textContent = r.hint; return false; }
          if (r.test(v)) {
            if (showOk) { msg.style.color = '#15803d'; msg.innerHTML = '<i class="fas fa-circle-check" style="margin-right:4px;"></i>Looks good.'; }
            return true;
          }
          msg.style.color = '#b91c1c'; msg.textContent = r.error;
          return false;
        }
        (function(){
          const hints={
            child:     '🌟 Fun, colorful interface with learning activities!',
            teen:      '✨ Modern, stylish interface for teens.',
            individual:'📚 Standard library interface for adult readers.',
            school:    '🏫 Institution access with group borrowing features.',
            professional:'💼 Professional access with extended borrowing periods.',
            private_institution:'🏢 Organization-level access and reporting.',
          };
          document.querySelectorAll('.cls-card').forEach(btn=>{
            btn.addEventListener('click',function(){
              document.querySelectorAll('.cls-card').forEach(b=>b.classList.remove('selected'));
              this.classList.add('selected');
              document.getElementById('r-classification').value=this.dataset.cls;
              const h=document.getElementById('cls-hint');
              h.textContent=hints[this.dataset.cls]||'';
              h.style.display='block';
              updateInstIdField();
            });
          });
          // Default selection
          document.querySelector('.cls-card[data-cls="individual"]')?.classList.add('selected');
          updateInstIdField();
        })();
        </script>

        <!-- Passwords -->
        <div class="fr2">
          <div class="fg" style="margin-bottom:0;">
            <label for="r-pw">Password <span style="color:#ef4444">*</span></label>
            <div class="iw">
              <i class="fas fa-lock ii"></i>
              <input class="fi" type="password" id="r-pw" name="password"
                     placeholder="Min. 8 chars" autocomplete="new-password" required>
              <button type="button" class="pwt" onclick="togglePw('r-pw',this)"><i class="fas fa-eye-slash"></i></button>
            </div>
          </div>
          <div class="fg" style="margin-bottom:0;">
            <label for="r-confirm">Confirm <span style="color:#ef4444">*</span></label>
            <div class="iw">
              <i class="fas fa-lock ii"></i>
              <input class="fi" type="password" id="r-confirm" name="confirm_password"
                     placeholder="Repeat" autocomplete="new-password" required>
              <button type="button" class="pwt" onclick="togglePw('r-confirm',this)"><i class="fas fa-eye-slash"></i></button>
            </div>
          </div>
        </div>

        <div style="margin-top:16px;">
          <button type="submit" class="bsub-btn">
            <i class="fas fa-user-plus"></i> Create Account
          </button>
        </div>
      </form>
      <div class="aswitch">Already have an account? <button onclick="switchView('login')">Sign in</button></div>
    </div>

    <!-- ── OTP VERIFY ─────────────────────────────────────────────────── -->
    <div class="av <?= $showOtp ? 'active' : '' ?>" id="view-otp">
      <div class="fhdr">
        <div class="fey">Email Verification</div>
        <h2 class="ftitle">Check your email</h2>
        <p class="fsub">Enter the 6-digit code sent to your email address.</p>
      </div>
      <form method="post" id="otpForm">
        <input type="hidden" name="mode" value="verify_otp">
        <input type="hidden" name="otp" id="otpHidden">
        <div class="otp-row">
          <?php for ($i = 0; $i < 6; $i++): ?>
          <input type="text" class="otp-box" maxlength="1" inputmode="numeric" oninput="otpInput(this)">
          <?php endfor; ?>
        </div>
        <button type="submit" class="bsub-btn" id="otpBtn" disabled>
          <i class="fas fa-shield-check"></i> Verify Code
        </button>
      </form>
      <div style="text-align:center;font-size:.78rem;color:var(--muted);">
        Didn't receive it?
        <form method="post" style="display:inline;">
          <input type="hidden" name="mode" value="resend_otp">
          <button type="submit" class="flink">Resend code</button>
        </form>
        &nbsp;&middot;&nbsp;
        <button class="flink" onclick="switchView('register')">Start over</button>
      </div>
    </div>

    <!-- ── FORGOT ─────────────────────────────────────────────────────── -->
    <div class="av" id="view-forgot">
      <div class="fhdr">
        <div class="fey">Password Reset</div>
        <h2 class="ftitle">Reset password</h2>
        <p class="fsub">Contact your system administrator to reset your password.</p>
      </div>
      <div style="padding:18px;background:#f0f4ff;border:1px solid #c7d4f5;border-radius:10px;margin-bottom:24px;">
        <div style="display:flex;gap:10px;align-items:flex-start;">
          <i class="fas fa-headset" style="color:#003087;margin-top:2px;flex-shrink:0;"></i>
          <div>
            <div style="font-size:.82rem;font-weight:600;color:#111827;margin-bottom:4px;">Contact SDO IT Support</div>
            <div style="font-size:.78rem;color:#6b7280;line-height:1.6;">For password reset assistance, please contact the SDO Quirino system administrator or your school's designated IT officer.</div>
          </div>
        </div>
      </div>
      <button type="button" class="bsub-btn" onclick="switchView('login')">
        <i class="fas fa-arrow-left"></i> Back to Sign In
      </button>
    </div>

    <div class="ffoot">&copy; <?= date('Y') ?> Schools Division Office of Quirino &nbsp;&middot;&nbsp; Department of Education</div>

  </div><!-- /fp -->
</div><!-- /wrap -->

<script>
const DEPED = ['deped.gov.ph','depedqui.com','.gov.ph','.edu.ph'];
function isDepEd(e){ e=e.toLowerCase(); return DEPED.some(d=>e.endsWith(d)); }

// ── Email notice + DepEd section toggle ───────────────────────────────────────
function updateEmailNotice(val) {
  const box  = document.getElementById('email-notice');
  const sec  = document.getElementById('deped-section');
  const lrn  = document.getElementById('r-lrn');
  const sch  = document.getElementById('r-school');
  const deped = val && isDepEd(val);

  if (sec)  sec.style.display = deped ? 'block' : 'none';
  if (lrn)  lrn.required = deped;
  if (sch)  sch.required = deped;

  const classSection = document.getElementById('classification-section');
  if (classSection) classSection.style.display = (!deped && val) ? 'block' : 'none';

  if (!box) return;
  if (deped) {
    box.style.cssText = 'margin:-2px 0 14px;padding:10px 12px;border-radius:8px;font-size:.75rem;line-height:1.5;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;';
    box.innerHTML = '<i class="fas fa-circle-check" style="margin-right:5px;"></i>DepEd/government email — <strong>instant approval</strong>. Your LRN will serve as your permanent Borrower ID.';
  } else if (val) {
    box.style.cssText = 'margin:-2px 0 14px;padding:10px 12px;border-radius:8px;font-size:.75rem;line-height:1.5;background:#fffbeb;border:1px solid #fde68a;color:#92400e;';
    box.innerHTML = '<i class="fas fa-envelope" style="margin-right:5px;"></i>Personal email — you will receive a verification code, then an admin must approve your account.';
  } else {
    box.style.cssText = 'margin:-2px 0 14px;padding:10px 12px;border-radius:8px;font-size:.75rem;line-height:1.5;background:#f0f4ff;border:1px solid #c7d4f5;color:#374151;';
    box.innerHTML = '<i class="fas fa-circle-info" style="color:#003087;margin-right:5px;"></i>DepEd/gov.ph emails are <strong>instantly approved</strong>. Other emails require verification and admin approval.';
  }
}

// ── LRN counter + status ──────────────────────────────────────────────────────
function lrnStatus(val) {
  const count = document.getElementById('lrn-count');
  const msg   = document.getElementById('lrn-msg');
  if (count) count.textContent = val.length + '/12';
  if (!msg) return;
  if (!val) { msg.textContent = ''; return; }
  if (val.length < 12) {
    msg.style.color = '#92400e';
    msg.innerHTML   = '<i class="fas fa-circle" style="font-size:.45rem;vertical-align:middle;margin-right:4px;"></i>' + val.length + ' of 12 digits entered';
  } else {
    msg.style.color = '#15803d';
    msg.innerHTML   = '<i class="fas fa-circle-check" style="margin-right:4px;"></i>LRN complete &mdash; this will be your permanent Borrower ID';
  }
}

// ── Profile photo preview ─────────────────────────────────────────────────────
function previewPhoto(input) {
  if (!input.files || !input.files[0]) return;
  const file  = input.files[0];
  const label = document.getElementById('photo-label');
  const circle= document.getElementById('photo-circle');
  if (label)  label.textContent = file.name + ' (' + (file.size/1024/1024).toFixed(1) + ' MB)';
  if (circle) circle.classList.add('has-img');
  const reader = new FileReader();
  reader.onload = e => {
    if (circle) circle.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
  };
  reader.readAsDataURL(file);
}

// ── Register validation (client-side) ────────────────────────────────────────
function validateRegister() {
  const email = (document.getElementById('r-email')?.value || '').trim();
  if (!isDepEd(email)) {
    // Personal-email accounts must supply a valid institutional ID for the
    // selected account type (server re-validates authoritatively).
    const idInput = document.getElementById('r-instid');
    if (!instIdValidate(idInput?.value || '', true)) {
      idInput?.focus();
      return false;
    }
    return true;
  }

  const lrn = (document.getElementById('r-lrn')?.value || '').replace(/\D/g,'');
  if (lrn.length !== 12) {
    const msg = document.getElementById('lrn-msg');
    if (msg) { msg.style.color='#b91c1c'; msg.innerHTML='<i class="fas fa-exclamation-circle" style="margin-right:4px;"></i>LRN must be exactly 12 digits.'; }
    document.getElementById('r-lrn')?.focus();
    return false;
  }
  const school = (document.getElementById('r-school')?.value || '').trim();
  if (!school) {
    document.getElementById('r-school')?.focus();
    return false;
  }
  return true;
}

// ── Shared utilities ──────────────────────────────────────────────────────────
function switchView(v) {
  document.querySelectorAll('.av').forEach(e => e.classList.remove('active'));
  document.getElementById('view-' + v).classList.add('active');
  document.getElementById('formPanel')?.scrollTo(0, 0);
}

function togglePw(id, btn) {
  const i  = document.getElementById(id);
  const ic = btn.querySelector('i');
  if (i.type === 'password') { i.type = 'text';     ic.className = 'fas fa-eye'; }
  else                       { i.type = 'password'; ic.className = 'fas fa-eye-slash'; }
}

function otpInput(el) {
  el.value = el.value.replace(/\D/,'');
  const boxes = [...document.querySelectorAll('.otp-box')];
  const idx   = boxes.indexOf(el);
  if (el.value && idx < boxes.length - 1) boxes[idx+1].focus();
  const val = boxes.map(b=>b.value).join('');
  document.getElementById('otpHidden').value = val;
  document.getElementById('otpBtn').disabled = val.length < 6;
}
document.addEventListener('keydown', e => {
  if (!e.target.classList.contains('otp-box')) return;
  if (e.key === 'Backspace' && !e.target.value) {
    const boxes = [...document.querySelectorAll('.otp-box')];
    const idx   = boxes.indexOf(e.target);
    if (idx > 0) { boxes[idx-1].value=''; boxes[idx-1].focus(); }
  }
});

// Re-apply notice if form was returned with an error
document.addEventListener('DOMContentLoaded', () => {
  const emailEl = document.getElementById('r-email');
  if (emailEl && emailEl.value) updateEmailNotice(emailEl.value);
});
</script>
</body>
</html>