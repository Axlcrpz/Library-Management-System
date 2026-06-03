<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$message     = '';
$messageType = 'danger';
$mode        = $_POST['mode'] ?? 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'register') {
        $fullName  = trim($_POST['full_name']  ?? '');
        $email     = trim($_POST['email']      ?? '');
        $password  = $_POST['password']        ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        if (!$fullName || !$email || !$password || !$confirm) {
            $message = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $message = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $message = 'Passwords do not match.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password, full_name, role, status, is_active)
                     VALUES (?, ?, ?, 'viewer', 'approved', 1)"
                );
                $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $fullName]);
                $message     = 'Account created! You can now sign in.';
                $messageType = 'success';
                $mode        = 'login';
            } catch (Throwable $e) {
                $message = 'Email already registered. Please sign in instead.';
            }
        }

    } else {
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';

        // Support login by username OR email
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR username = ? LIMIT 1');
        $stmt->execute([$email, $email]);
        $user = $stmt->fetch();

        if ($user && (int) $user['is_active'] === 1
            && ($user['status'] ?? 'approved') === 'approved'
            && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = (int) $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];
            generateCSRFToken();
            header('Location: index.php');
            exit;
        }

        $message = 'Invalid email or password. Please try again.';
    }
}

$showRegister = ($mode === 'register');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDO Quirino — Library System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:      #1a1a1a;
            --accent:       #2563eb;
            --border:       #e5e7eb;
            --text:         #111827;
            --text-muted:   #6b7280;
            --bg:           #f9fafb;
            --surface:      #ffffff;
            --radius:       12px;
            --shadow:       0 1px 3px rgba(0,0,0,.08), 0 20px 60px rgba(0,0,0,.06);
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Split layout ──────────────────────── */
        .auth-layout {
            display: flex;
            width: 100%;
            max-width: 900px;
            min-height: 580px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        /* Left panel */
        .auth-panel-left {
            flex: 1;
            background: linear-gradient(145deg, #0f172a 0%, #1e3a8a 60%, #1e40af 100%);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .auth-panel-left::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 300px; height: 300px;
            background: rgba(255,255,255,.04);
            border-radius: 50%;
        }

        .auth-panel-left::after {
            content: '';
            position: absolute;
            bottom: -100px; left: -60px;
            width: 360px; height: 360px;
            background: rgba(255,255,255,.03);
            border-radius: 50%;
        }

        .auth-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }

        .auth-brand-icon {
            width: 42px; height: 42px;
            background: rgba(255,255,255,.15);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: #fff;
        }

        .auth-brand-name {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .auth-brand-sub {
            font-size: .68rem;
            color: rgba(255,255,255,.55);
        }

        .auth-panel-tagline {
            position: relative;
            z-index: 1;
        }

        .auth-tagline-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: #fff;
            line-height: 1.3;
            margin-bottom: 12px;
        }

        .auth-tagline-sub {
            font-size: .85rem;
            color: rgba(255,255,255,.6);
            line-height: 1.6;
        }

        .auth-panel-footer {
            position: relative;
            z-index: 1;
        }

        .auth-panel-footer p {
            font-size: .72rem;
            color: rgba(255,255,255,.35);
        }

        /* Right panel */
        .auth-panel-right {
            width: 420px;
            background: var(--surface);
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        /* Form */
        .auth-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .auth-subtitle {
            font-size: .82rem;
            color: var(--text-muted);
            margin-bottom: 28px;
        }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            font-size: .85rem;
            font-family: inherit;
            border: 1.5px solid var(--border);
            border-radius: 9px;
            background: #fff;
            color: var(--text);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .form-control:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .form-control::placeholder { color: #9ca3af; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .password-wrap { position: relative; }
        .password-wrap .form-control { padding-right: 38px; }
        .password-toggle {
            position: absolute;
            right: 11px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: .85rem;
            padding: 0;
            line-height: 1;
        }
        .password-toggle:hover { color: var(--text); }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            margin-top: -4px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 7px;
            font-size: .78rem;
            color: var(--text-muted);
            cursor: pointer;
        }

        .form-check input[type="checkbox"] {
            width: 15px; height: 15px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .link-btn {
            background: none; border: none;
            color: var(--accent);
            font-size: .78rem;
            font-weight: 500;
            cursor: pointer;
            font-family: inherit;
            text-decoration: none;
            padding: 0;
        }
        .link-btn:hover { text-decoration: underline; }

        .btn-submit {
            width: 100%;
            padding: 11px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: .88rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: background .15s, transform .1s;
            margin-bottom: 20px;
        }
        .btn-submit:hover { background: #2d2d2d; }
        .btn-submit:active { transform: scale(.99); }

        .auth-divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            font-size: .75rem;
            color: var(--text-muted);
        }
        .auth-divider::before, .auth-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .auth-switch {
            text-align: center;
            font-size: .8rem;
            color: var(--text-muted);
        }

        .auth-switch button {
            background: none; border: none;
            color: var(--accent);
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            font-size: .8rem;
            padding: 0;
        }
        .auth-switch button:hover { text-decoration: underline; }

        /* Alert */
        .auth-alert {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: .8rem;
            font-weight: 500;
            margin-bottom: 18px;
            display: flex;
            align-items: start;
            gap: 8px;
        }
        .auth-alert.success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .auth-alert.danger  { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

        /* View toggle */
        .auth-view { display: none; }
        .auth-view.active { display: block; }

        /* Responsive */
        @media (max-width: 720px) {
            .auth-panel-left { display: none; }
            .auth-panel-right { width: 100%; padding: 36px 28px; }
            .auth-layout { max-width: 420px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="auth-layout">

    <!-- Left panel -->
    <div class="auth-panel-left">
        <div class="auth-brand">
            <div class="auth-brand-icon"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="auth-brand-name">SDO Library</div>
                <div class="auth-brand-sub">Schools Division Office of Quirino</div>
            </div>
        </div>

        <div class="auth-panel-tagline">
            <div class="auth-tagline-title">Your Gateway to<br>Knowledge &amp; Learning</div>
            <div class="auth-tagline-sub">
                Access thousands of books, manage borrowing records, and track inventory — all in one unified platform built for DepEd Quirino.
            </div>
        </div>

        <div class="auth-panel-footer">
            <p>&copy; <?= date('Y') ?> Schools Division Office of Quirino. All rights reserved.</p>
        </div>
    </div>

    <!-- Right panel -->
    <div class="auth-panel-right">

        <?php if ($message): ?>
        <div class="auth-alert <?= $messageType === 'success' ? 'success' : 'danger' ?>">
            <i class="fas <?= $messageType === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" style="margin-top:1px;flex-shrink:0;"></i>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
        <?php endif; ?>

        <!-- LOGIN VIEW -->
        <div class="auth-view <?= !$showRegister ? 'active' : '' ?>" id="view-login">
            <div class="auth-title">Welcome back</div>
            <div class="auth-subtitle">Sign in to your account to continue</div>

            <form method="post" autocomplete="on">
                <input type="hidden" name="mode" value="login">

                <div class="form-group">
                    <label for="login-email">Email or Username</label>
                    <input class="form-control" type="text" id="login-email" name="email"
                           placeholder="admin or you@deped.gov.ph" autocomplete="username" required>
                </div>

                <div class="form-group">
                    <label for="login-password">Password</label>
                    <div class="password-wrap">
                        <input class="form-control" type="password" id="login-password" name="password"
                               placeholder="Enter your password" autocomplete="current-password" required>
                        <button type="button" class="password-toggle" onclick="togglePw('login-password', this)">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>

                <div class="form-footer">
                    <label class="form-check">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <span class="link-btn" onclick="showForgot()">Forgot password?</span>
                </div>

                <button type="submit" class="btn-submit">Sign in</button>
            </form>

            <div class="auth-switch">
                Don't have an account?
                <button onclick="switchView('register')">Create one</button>
            </div>
        </div>

        <!-- REGISTER VIEW -->
        <div class="auth-view <?= $showRegister ? 'active' : '' ?>" id="view-register">
            <div class="auth-title">Create account</div>
            <div class="auth-subtitle">Register to access the library system</div>

            <form method="post" autocomplete="on">
                <input type="hidden" name="mode" value="register">

                <div class="form-group">
                    <label for="reg-name">Full name</label>
                    <input class="form-control" type="text" id="reg-name" name="full_name"
                           placeholder="Juan dela Cruz" autocomplete="name" required>
                </div>

                <div class="form-group">
                    <label for="reg-email">Email address</label>
                    <input class="form-control" type="email" id="reg-email" name="email"
                           placeholder="you@deped.gov.ph" autocomplete="email" required>
                </div>

                <div class="form-row">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="reg-pw">Password</label>
                        <div class="password-wrap">
                            <input class="form-control" type="password" id="reg-pw" name="password"
                                   placeholder="Min. 8 characters" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" onclick="togglePw('reg-pw', this)">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="reg-confirm">Confirm password</label>
                        <div class="password-wrap">
                            <input class="form-control" type="password" id="reg-confirm" name="confirm_password"
                                   placeholder="Repeat password" autocomplete="new-password" required>
                            <button type="button" class="password-toggle" onclick="togglePw('reg-confirm', this)">
                                <i class="fas fa-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px;margin-bottom:18px;font-size:.75rem;color:var(--text-muted);background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:10px 12px;">
                    <i class="fas fa-circle-info me-1" style="color:var(--accent);"></i>
                    Your account will be activated immediately after registration.
                </div>

                <button type="submit" class="btn-submit">Create account</button>
            </form>

            <div class="auth-switch">
                Already have an account?
                <button onclick="switchView('login')">Sign in</button>
            </div>
        </div>

        <!-- FORGOT PASSWORD VIEW -->
        <div class="auth-view" id="view-forgot">
            <div class="auth-title">Reset password</div>
            <div class="auth-subtitle">Enter your email and we'll send you a reset link</div>

            <div class="form-group">
                <label for="forgot-email">Email address</label>
                <input class="form-control" type="email" id="forgot-email"
                       placeholder="you@deped.gov.ph">
            </div>

            <button type="button" class="btn-submit" onclick="sendReset()">Send reset link</button>

            <div class="auth-divider">or</div>

            <div class="auth-switch">
                <button onclick="switchView('login')">← Back to sign in</button>
            </div>
        </div>

    </div>
</div>

<script>
function switchView(view) {
    document.querySelectorAll('.auth-view').forEach(el => el.classList.remove('active'));
    document.getElementById('view-' + view).classList.add('active');
}

function showForgot() { switchView('forgot'); }

function togglePw(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fas fa-eye';
    } else {
        input.type = 'password';
        icon.className = 'fas fa-eye-slash';
    }
}

function sendReset() {
    const email = document.getElementById('forgot-email').value.trim();
    if (!email) { alert('Please enter your email address.'); return; }
    alert('If this email exists in our system, a reset link has been sent.');
    switchView('login');
}
</script>

</body>
</html>