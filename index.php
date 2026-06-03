<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

$currentUser = getCurrentUser();
$isAdmin = (($_SESSION['role'] ?? 'user') === 'admin');
$userRole = $_SESSION['role'] ?? 'user';
$isStaff = in_array($userRole, ['admin', 'staff'], true);
$themeClass = $isAdmin ? 'theme-sdo-admin' : 'theme-sdo-user';
$csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : ($_SESSION['csrf_token'] ?? bin2hex(random_bytes(16)));

$navItems = [
    ['id' => 'dashboard',        'label' => 'Dashboard',       'icon' => 'fa-gauge',         'admin' => false, 'divider_before' => false],
    ['id' => 'documents',        'label' => 'Documents',        'icon' => 'fa-file-lines',    'admin' => false, 'divider_before' => false],
    ['id' => 'books',            'label' => 'Inventory',        'icon' => 'fa-book',          'admin' => false, 'divider_before' => false],
    ['id' => 'borrowing',        'label' => 'Borrowing',        'icon' => 'fa-hand-holding',  'admin' => false, 'divider_before' => true],
     ['id' => 'reservations',     'label' => 'Reservations',     'icon' => 'fa-bookmark',      'admin' => false, 'divider_before' => false],
    ['id' => 'archive',          'label' => 'Archive',          'icon' => 'fa-box-archive',   'admin' => true,  'divider_before' => true],
    ['id' => 'trash',            'label' => 'Trash',            'icon' => 'fa-trash-can',     'admin' => true,  'divider_before' => false],
    ['id' => 'account-requests', 'label' => 'Account Requests', 'icon' => 'fa-user-check',    'admin' => true,  'divider_before' => false],
];

$userName = $currentUser['full_name'] ?? 'Guest';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDO Quirino — Library System</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <script>
        window.isAdmin   = <?= json_encode((bool) $isAdmin) ?>;
        window.isStaff   = <?= json_encode((bool) $isStaff) ?>;
        window.currentRole = <?= json_encode((string) $userRole) ?>;
        window.currentUser = <?= json_encode($currentUser ?: []) ?>;
        window.subsystem = 'library';
    </script>
    <script src="assets/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($themeClass) ?>">

<div id="toast-container">
    <div id="toast-inner"></div>
</div>

<div style="display:flex;min-height:100vh;align-items:stretch;">

    <!-- ── SIDEBAR ─────────────────────────────────── -->
    <aside class="sidebar" id="mainSidebar">

        <!-- Brand -->
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <div class="sidebar-brand-icon"><i class="fas fa-book-open"></i></div>
                <div>
                    <div class="sidebar-brand-text">SDO Library</div>
                    <div class="sidebar-brand-sub">Quirino Division</div>
                </div>
            </div>
            <button id="sidebarToggle" class="sidebar-toggle-btn" title="Toggle sidebar">
                <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
            </button>
        </div>

        <!-- User -->
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= $userInitial ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
                <div class="sidebar-user-role"><?= ucfirst(htmlspecialchars($userRole)) ?></div>
            </div>
        </div>

        <!-- Nav -->
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Menu</div>
            <?php foreach ($navItems as $item): ?>
                <?php if (!$item['admin'] || $isAdmin): ?>
                    <?php if ($item['divider_before']): ?>
                        <hr class="sidebar-divider">
                        <div class="sidebar-section-label"><?= $item['id'] === 'borrowing' ? 'Circulation' : 'Admin' ?></div>
                    <?php endif; ?>
                    <a class="nav-link <?= $item['id'] === 'dashboard' ? 'active' : '' ?>"
                       href="#" data-tab="<?= $item['id'] ?>" onclick="switchTab(event)"
                       title="<?= htmlspecialchars($item['label']) ?>">
                        <i class="fas <?= $item['icon'] ?>"></i>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <!-- Footer -->
        <div class="sidebar-footer">
            <button type="button" class="theme-toggle theme-toggle-sidebar" data-theme-toggle title="Toggle theme">
                <span data-theme-icon>D</span>
            </button>
            <a class="logout-btn mt-2" href="logout.php" title="Logout">
                <i class="fas fa-right-from-bracket"></i>
                <span class="logout-link-text">Logout</span>
            </a>
        </div>
    </aside>

    <!-- ── MAIN ─────────────────────────────────────── -->
    <div style="flex:1;min-width:0;display:flex;flex-direction:column;">

        <!-- Mobile nav -->
        <nav class="navbar sidebar-mobile px-3 d-md-none">
            <span class="navbar-brand text-white fw-bold" style="font-size:.9rem;">SDO Library</span>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="theme-toggle theme-toggle-compact" data-theme-toggle>
                    <span data-theme-icon>D</span>
                </button>
                <button class="btn btn-sm btn-outline-light" type="button"
                        data-bs-toggle="collapse" data-bs-target="#mobileSidebarMenu">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
        </nav>

        <!-- Mobile menu -->
        <div class="collapse d-md-none px-3 pb-2" id="mobileSidebarMenu"
             style="background:var(--sidebar-bg);">
            <div class="py-2">
                <?php foreach ($navItems as $item): ?>
                    <?php if (!$item['admin'] || $isAdmin): ?>
                        <?php if ($item['divider_before']): ?>
                            <hr style="border-color:rgba(255,255,255,.1);">
                        <?php endif; ?>
                        <a class="nav-link <?= $item['id'] === 'dashboard' ? 'active' : '' ?>"
                           href="#" data-tab="<?= $item['id'] ?>" onclick="switchTab(event)"
                           style="color:rgba(255,255,255,.7);padding:8px 10px;border-radius:7px;margin-bottom:2px;display:flex;align-items:center;gap:9px;font-size:.82rem;font-weight:500;">
                            <i class="fas <?= $item['icon'] ?>" style="width:16px;text-align:center;"></i>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <hr style="border-color:rgba(255,255,255,.1);">
                <a href="logout.php" style="color:rgba(255,255,255,.6);padding:8px 10px;display:flex;align-items:center;gap:9px;font-size:.82rem;text-decoration:none;">
                    <i class="fas fa-right-from-bracket" style="width:16px;text-align:center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Tab content -->
        <main class="main-content" id="mainContent">
            <div class="tab-content active" id="dashboard"><?php require __DIR__ . '/templates/dashboard.php'; ?></div>
            <div class="tab-content" id="documents"><?php require __DIR__ . '/templates/tables.php'; ?></div>
            <div class="tab-content" id="books"><?php require __DIR__ . '/templates/books.php'; ?></div>
            <div class="tab-content" id="borrowing"><?php require __DIR__ . '/templates/book_borrow.php'; ?></div>
            <div class="tab-content" id="reservations"><?php require __DIR__ . '/templates/templates/reservations.php'; ?></div>
            <?php if ($isAdmin): ?>
            <div class="tab-content" id="archive"><?php require __DIR__ . '/templates/archive.php'; ?></div>
            <div class="tab-content" id="trash"><?php require __DIR__ . '/templates/trash.php'; ?></div>
            <div class="tab-content" id="account-requests"><?php require __DIR__ . '/templates/account_requests.php'; ?></div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php require __DIR__ . '/templates/modals.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="assets/js/app.js?v=sdo-redesign-2026"></script>

<!-- sidebar toggle handled in app.js -->
</body>
</html>