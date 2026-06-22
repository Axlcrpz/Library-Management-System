<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config/database.php';

require_login();

$currentUser = getCurrentUser();
$isAdmin = (($_SESSION['role'] ?? 'user') === 'admin');
$userRole = $_SESSION['role'] ?? 'user';
$isStaff = in_array($userRole, ['admin', 'staff'], true);
$csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : ($_SESSION['csrf_token'] ?? bin2hex(random_bytes(16)));

// Determine UI tier from role + classification
$userClassification = 'individual';
try {
    $clsStmt = $pdo->prepare("SELECT classification FROM users WHERE id = ? LIMIT 1");
    $clsStmt->execute([$_SESSION['user_id']]);
    $clsRow  = $clsStmt->fetch(PDO::FETCH_ASSOC);
    $userClassification = $clsRow['classification'] ?? 'individual';
} catch (Throwable) {}

if ($isAdmin || $userRole === 'staff') {
    $uiTier = 'admin';
} elseif ($userClassification === 'child') {
    $uiTier = 'child';
} elseif ($userClassification === 'teen') {
    $uiTier = 'teen';
} else {
    $uiTier = 'adult';
}
$themeClass = 'ui-' . $uiTier;

$navItems = [
    ['id' => 'dashboard',        'label' => 'Dashboard',        'icon' => 'fa-gauge',          'admin' => false, 'divider_before' => false],
    ['id' => 'documents',        'label' => 'Documents',         'icon' => 'fa-file-lines',     'admin' => false, 'divider_before' => false],
    ['id' => 'books',            'label' => 'Inventory',         'icon' => 'fa-book',           'admin' => false, 'divider_before' => false],
    ['id' => 'borrowing',        'label' => 'Borrowing',         'icon' => 'fa-hand-holding',   'admin' => false, 'divider_before' => true],
    ['id' => 'reservations',     'label' => 'Reservations',      'icon' => 'fa-bookmark',       'admin' => false, 'divider_before' => false],
    ['id' => 'members',          'label' => 'Members',           'icon' => 'fa-users',          'admin' => true,  'divider_before' => false],
    ['id' => 'delivery-log',     'label' => 'Delivery Log',      'icon' => 'fa-truck-ramp-box', 'admin' => true,  'divider_before' => true],
    ['id' => 'archive',          'label' => 'Archive',           'icon' => 'fa-box-archive',    'admin' => false, 'divider_before' => false],
    ['id' => 'trash',            'label' => 'Trash',             'icon' => 'fa-trash-can',      'admin' => true,  'divider_before' => false],
    ['id' => 'account-requests', 'label' => 'Account Requests',  'icon' => 'fa-user-check',     'admin' => true,  'divider_before' => false],
    ['id' => 'audit-logs',       'label' => 'Audit Logs',        'icon' => 'fa-clipboard-list', 'admin' => true,  'divider_before' => false],
    ['id' => 'settings',         'label' => 'Settings',          'icon' => 'fa-gear',           'admin' => true,  'divider_before' => false],
    ['id' => 'my-account',      'label' => 'My Account',        'icon' => 'fa-circle-user',    'admin' => false, 'divider_before' => true],
];

$userName = $currentUser['full_name'] ?? 'Guest';
$userInitial = strtoupper(substr($userName, 0, 1));
$avatarVer = $_SESSION['avatar_ver'] ?? 0;
$avatarUrl = 'api/library_handler.php?action=user_avatar&id=' . (int)($currentUser['id'] ?? 0) . '&v=' . $avatarVer;
// Resolve how to fit the avatar: photos fill, illustrations are framed, initials fill
$avType = 'initials';
try {
    $avq = $pdo->prepare('SELECT profile_image, avatar_id FROM users WHERE id = ?');
    $avq->execute([(int)($currentUser['id'] ?? 0)]);
    if ($avr = $avq->fetch(PDO::FETCH_ASSOC)) {
        $avType = !empty($avr['profile_image']) ? 'photo' : (!empty($avr['avatar_id']) ? 'system' : 'initials');
    }
} catch (Throwable $e) {}
$avFitClass = 'av-fit-' . $avType;
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
        window.uiTier    = <?= json_encode($uiTier) ?>;
        window.userClassification = <?= json_encode($userClassification) ?>;
    </script>
    <script src="assets/js/theme.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <?php if ($isStaff): ?>
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
    <?php endif; ?>
    <link href="assets/css/style.css?v=sdo-2026-uiv9" rel="stylesheet">
    <?php if ($uiTier === 'child'): ?>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link href="assets/css/theme-child.css" rel="stylesheet">
    <?php elseif ($uiTier === 'teen'): ?>
    <link href="assets/css/theme-teen.css" rel="stylesheet">
    <?php endif; ?>
    <link href="assets/css/theme.css" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($themeClass) ?>">

<div id="toast-container">
    <div id="toast-inner"></div>
</div>

<div class="app-shell">

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

        <!-- Nav -->
        <nav class="sidebar-nav">
            <div class="sidebar-section-label">Menu</div>
            <?php foreach ($navItems as $item): ?>
                <?php if (!$item['admin'] || $isAdmin): ?>
                    <?php if ($item['divider_before']): ?>
                        <hr class="sidebar-divider">
                        <div class="sidebar-section-label"><?= $item['id'] === 'borrowing' ? 'Circulation' : ($item['id'] === 'my-account' ? 'Account' : 'Admin') ?></div>
                    <?php endif; ?>
                    <a class="nav-link <?= $item['id'] === 'dashboard' ? 'active' : '' ?>"
                       href="#" data-tab="<?= $item['id'] ?>" onclick="switchTab(event)"
                       title="<?= htmlspecialchars($item['label']) ?>">
                        <i class="fas <?= $item['icon'] ?>"></i>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                        <?php if ($item['id'] === 'account-requests'): ?>
                            <span id="account-req-badge" class="badge ms-auto" style="background:var(--danger);display:none;font-size:.6rem;"></span>
                        <?php endif; ?>
                        <?php if ($item['id'] === 'settings'): ?>
                            <!-- gear icon only -->
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <!-- Footer / Account -->
        <div class="sidebar-footer">
            <div class="sidebar-user" title="<?= htmlspecialchars($userName) ?>">
                <div class="sidebar-avatar"><img id="sidebar-avatar-img" class="avatar-img <?= $avFitClass ?>" src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($userName) ?>"></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
                    <div class="sidebar-user-role"><?= ucfirst(htmlspecialchars($userRole)) ?></div>
                </div>
            </div>
            <div class="sidebar-footer-actions">
                <button type="button" class="theme-toggle theme-toggle-sidebar" data-theme-toggle title="Toggle theme">
                    <span data-theme-icon>D</span>
                </button>
                <a class="logout-btn" href="logout.php" title="Logout"
                   data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                    <i class="fas fa-right-from-bracket"></i>
                    <span class="logout-link-text">Logout</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- ── MAIN ─────────────────────────────────────── -->
    <div class="app-main">

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
                <a href="logout.php" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal"
                   style="color:rgba(255,255,255,.6);padding:8px 10px;display:flex;align-items:center;gap:9px;font-size:.82rem;text-decoration:none;">
                    <i class="fas fa-right-from-bracket" style="width:16px;text-align:center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>

        <!-- Admin / Staff topbar (desktop) -->
        <?php if ($uiTier === 'admin'): ?>
        <div class="admin-topbar d-none d-md-flex" id="adminTopbar">
            <div class="admin-topbar-left">
                <nav class="admin-topbar-breadcrumb" aria-label="breadcrumb">
                    <span>SDO Library</span>
                    <span class="bc-sep"><i class="fas fa-chevron-right"></i></span>
                    <span class="bc-current" id="topbar-page-name">Dashboard</span>
                </nav>
            </div>
            <div class="admin-topbar-right">
                <button type="button" class="topbar-icon-btn"
                        data-bs-toggle="modal" data-bs-target="#adminNotificationsModal"
                        title="Notifications" id="topbar-notif-btn">
                    <i class="fas fa-bell"></i>
                    <span class="notif-dot" id="topbar-notif-dot"></span>
                </button>
                <a href="#" class="topbar-user-chip" data-tab="my-account" onclick="switchTab(event)" title="My Account">
                    <div class="topbar-user-avatar"><img id="topbar-avatar-img" class="avatar-img <?= $avFitClass ?>" src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($userName) ?>"></div>
                    <span class="topbar-user-name"><?= htmlspecialchars($userName) ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tab content -->
        <main class="main-content" id="mainContent">
            <div class="tab-content active" id="dashboard"><?php require __DIR__ . '/templates/dashboard.php'; ?></div>
            <div class="tab-content" id="documents"><?php require __DIR__ . '/templates/tables.php'; ?></div>
            <div class="tab-content" id="books"><?php require __DIR__ . '/templates/books.php'; ?></div>
            <div class="tab-content" id="borrowing"><?php require __DIR__ . '/templates/book_borrow.php'; ?></div>
            <div class="tab-content" id="reservations"><?php require __DIR__ . '/templates/reservations.php'; ?></div>
            <div class="tab-content" id="archive"><?php require __DIR__ . '/templates/archive.php'; ?></div>
            <?php if ($isAdmin): ?>
            <div class="tab-content" id="members"><?php require __DIR__ . '/templates/borrowers.php'; ?></div>
            <div class="tab-content" id="trash"><?php require __DIR__ . '/templates/trash.php'; ?></div>
            <div class="tab-content" id="account-requests"><?php require __DIR__ . '/templates/account_requests.php'; ?></div>
            <div class="tab-content" id="delivery-log"><?php require __DIR__ . '/templates/delivery_log.php'; ?></div>
            <div class="tab-content" id="audit-logs"><?php require __DIR__ . '/templates/audit_logs.php'; ?></div>
            <div class="tab-content" id="settings"><?php require __DIR__ . '/templates/settings.php'; ?></div>
            <?php endif; ?>
            <div class="tab-content" id="my-account"><?php require __DIR__ . '/templates/user_settings.php'; ?></div>
        </main>
    </div>
</div>

<?php require __DIR__ . '/templates/modals.php'; ?>

<!-- ── Logout confirmation (global · every role) ──────────────────────────── -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1"
     aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmDesc" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered logout-modal-dialog">
        <div class="modal-content">
            <div class="modal-body text-center" style="padding:28px 26px 8px;">
                <div class="logout-modal-icon" aria-hidden="true">
                    <i class="fas fa-right-from-bracket"></i>
                </div>
                <h5 class="modal-title" id="logoutConfirmTitle"
                    style="font-size:1.08rem;font-weight:700;margin:16px 0 6px;">Are you sure want to log out?</h5>
                <p id="logoutConfirmDesc" class="text-muted" style="font-size:.85rem;line-height:1.55;margin:0;">
                    You're signed in as <strong><?= htmlspecialchars($userName) ?></strong>.<br>
                    You'll need to log in again to continue.
                
                </p>
            </div>
            <div class="modal-footer logout-modal-footer">
                <button type="button" class="btn btn-secondary" id="logoutCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-danger" id="logoutConfirmBtn">
                    <i class="fas fa-right-from-bracket me-1" aria-hidden="true"></i>Log out
                </a>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('logoutConfirmModal');
    if (!modalEl) return;
    // Cancel is the safe default — focus it on open so Enter cancels, not logs out.
    modalEl.addEventListener('shown.bs.modal', function () {
        var c = document.getElementById('logoutCancelBtn');
        if (c) c.focus();
    });
    // Brief feedback + double-click guard while the logout navigation happens.
    var confirmBtn = document.getElementById('logoutConfirmBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', function () {
        confirmBtn.classList.add('disabled');
        confirmBtn.setAttribute('aria-disabled', 'true');
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1" aria-hidden="true"></i>Logging out…';
    });
})();
</script>

<!-- Admin Notifications Modal (push-style deep-link) -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="adminNotificationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable" style="max-width:440px;">
        <div class="modal-content">
            <div class="modal-header" style="padding:12px 18px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="fas fa-bell" style="color:var(--primary);font-size:.9rem;"></i>
                    <span style="font-weight:700;font-size:.9rem;">Notifications</span>
                    <span id="notif-unread-count" class="badge" style="background:var(--danger);font-size:.65rem;display:none;">0</span>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;" onclick="markAllNotificationsRead()">
                        Mark all read
                    </button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div id="admin-notif-list" style="max-height:480px;overflow-y:auto;">
                    <div class="text-center text-muted py-5" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading notifications…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if ($isStaff): ?><script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script><?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="assets/js/vendor/xlsx.full.min.js"></script>
<script>
if (typeof XLSX === 'undefined') {
    var xlsxFallback = document.createElement('script');
    xlsxFallback.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
    document.head.appendChild(xlsxFallback);
}
</script>
<script src="assets/js/app.js?v=sdo-2026-v8"></script>
<script src="assets/js/discovery.js?v=sdo-2026-v2"></script>
<script src="assets/js/inventory.js?v=sdo-2026-v4"></script>

<?php if ($uiTier === 'child'): ?>
<script src="assets/js/gamification.js"></script>
<?php endif; ?>

<?php if ($isAdmin): ?>
<script>
// Push-style notifications with deep-link
async function loadAdminNotifications() {
    const body = await fetch('api/library_handler.php?action=notifications_get&limit=30', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;

    const { items = [], unread_count = 0 } = body.data || {};

    // Update badges
    ['admin-notif-badge','settings-notif-badge'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = unread_count;
        el.style.display = unread_count > 0 ? 'inline-block' : 'none';
    });
    const countEl = document.getElementById('notif-unread-count');
    if (countEl) { countEl.textContent = unread_count; countEl.style.display = unread_count > 0 ? 'inline-block' : 'none'; }

    const list = document.getElementById('admin-notif-list');
    if (!list) return;

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtDT = v => { if (!v) return ''; const d = new Date(String(v).replace(' ','T')); return isNaN(d)?v:d.toLocaleString('en-PH',{month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'}); };

    const typeIcon = { borrow:'fa-hand-holding', delivery:'fa-truck-ramp-box', reservation:'fa-bookmark', return:'fa-rotate-left', info:'fa-circle-info' };
    const typeColor = { borrow:'var(--success)', delivery:'var(--info)', reservation:'var(--warning)', return:'var(--primary)', info:'var(--text-muted)' };

    if (!items.length) {
        list.innerHTML = '<div class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-bell-slash fa-2x mb-3 d-block"></i>No notifications yet.</div>';
        return;
    }

    list.innerHTML = items.map(n => {
        const color = typeColor[n.type] || 'var(--text-muted)';
        const icon  = typeIcon[n.type]  || 'fa-circle-dot';
        const unread = !n.is_read;
        return `<div onclick="notifDeepLink('${esc(n.module)}', ${n.target_id || 0}, ${n.id})"
                     style="padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;background:${unread?'rgba(0,48,135,.03)':'transparent'};transition:background .15s;"
                     onmouseover="this.style.background='var(--surface-hover)'" onmouseout="this.style.background='${unread?'rgba(0,48,135,.03)':'transparent'}'">
            <div style="display:flex;gap:11px;align-items:flex-start;">
                <div style="width:34px;height:34px;border-radius:9px;background:${color}18;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;">
                    <i class="fas ${icon}" style="color:${color};font-size:.8rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:${unread?700:500};font-size:.82rem;color:var(--text);">${esc(n.title)}</div>
                    <div style="font-size:.76rem;color:var(--text-muted);margin-top:2px;line-height:1.4;">${esc(n.body||'')}</div>
                    <div style="font-size:.7rem;color:var(--text-light);margin-top:4px;">${fmtDT(n.created_at)}</div>
                </div>
                ${unread ? '<div style="width:7px;height:7px;border-radius:50%;background:var(--primary);flex-shrink:0;margin-top:6px;"></div>' : ''}
            </div>
        </div>`;
    }).join('');
}

async function notifDeepLink(module, targetId, notifId) {
    // Mark as read
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: new URLSearchParams({ action: 'notifications_mark_read', id: notifId })
    });

    // Close modal and navigate
    bootstrap.Modal.getInstance(document.getElementById('adminNotificationsModal'))?.hide();
    if (module && typeof switchTabById === 'function') {
        switchTabById(module);
    }
    loadAdminNotifications();
}

async function markAllNotificationsRead() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: new URLSearchParams({ action: 'notifications_mark_read', id: 0 })
    });
    loadAdminNotifications();
}

// Load on open
document.getElementById('adminNotificationsModal')?.addEventListener('show.bs.modal', loadAdminNotifications);

// Poll for new notifications every 45 seconds
setInterval(loadAdminNotifications, 45000);
document.addEventListener('DOMContentLoaded', loadAdminNotifications);

window.loadAdminNotifications    = loadAdminNotifications;
window.notifDeepLink             = notifDeepLink;
window.markAllNotificationsRead  = markAllNotificationsRead;

// Sync topbar notification dot with sidebar badge
(function syncTopbarDot() {
    const dot  = document.getElementById('topbar-notif-dot');
    const sync = () => {
        const count = parseInt(document.getElementById('notif-unread-count')?.textContent || '0');
        if (dot) dot.style.display = count > 0 ? 'block' : 'none';
    };
    const obs = new MutationObserver(sync);
    const badge = document.getElementById('notif-unread-count');
    if (badge) obs.observe(badge, { childList: true, attributes: true });
})();
</script>
<?php endif; ?>

<!-- Topbar page-name sync (all tiers that have the topbar) -->
<?php if ($uiTier === 'admin'): ?>
<script>
document.addEventListener('tabChanged', function (e) {
    const nameEl = document.getElementById('topbar-page-name');
    if (!nameEl) return;
    const labels = {
        'dashboard': 'Dashboard', 'documents': 'Documents', 'books': 'Inventory',
        'borrowing': 'Borrowing', 'reservations': 'Reservations', 'members': 'Members',
        'delivery-log': 'Delivery Log', 'archive': 'Archive', 'trash': 'Trash',
        'account-requests': 'Account Requests', 'audit-logs': 'Audit Logs',
        'settings': 'Settings', 'my-account': 'My Account'
    };
    const tabId = typeof e.detail === 'string' ? e.detail : (e.detail?.id || '');
    if (labels[tabId]) nameEl.textContent = labels[tabId];
});
</script>
<?php endif; ?>

<!-- Prevent the browser from autofilling saved login (e.g. "admin") into search/filter boxes.
     readonly-until-interaction is the only reliable cross-browser block — Chrome ignores autocomplete="off". -->
<style>
input.naf-input[readonly] { background-color: var(--bs-body-bg, var(--surface, #fff)) !important; cursor: text; opacity: 1; }
</style>
<script>
(function hardenSearchInputs() {
    function harden(el) {
        if (['text', 'search', ''].indexOf(el.type) === -1) return;
        if (el.dataset.naf !== '1') {
            el.dataset.naf = '1';
            el.classList.add('naf-input');
            el.setAttribute('autocomplete', 'off');
            el.setAttribute('autocapitalize', 'off');
            el.setAttribute('spellcheck', 'false');
            if (!el.getAttribute('name')) {
                el.setAttribute('name', 'q_' + (el.id || Math.random().toString(36).slice(2)));
            }
        }
        // Until the user actually engages the field, keep it readonly + empty so
        // the browser cannot pin its saved username onto it.
        if (!el.dataset.unlocked && el !== document.activeElement) {
            el.value = '';
            el.setAttribute('readonly', 'readonly');
        }
    }
    function run() {
        document.querySelectorAll('input[id*="search" i], input[id*="filter" i]').forEach(harden);
    }
    // The moment the user focuses/clicks a search box, unlock it for typing.
    function unlock(e) {
        var el = e.target;
        if (el && el.dataset && el.dataset.naf === '1') {
            el.dataset.unlocked = '1';
            el.removeAttribute('readonly');
        }
    }
    document.addEventListener('focusin', unlock);
    document.addEventListener('pointerdown', unlock, true);
    document.addEventListener('DOMContentLoaded', run);
    [100, 400, 1000].forEach(function (t) { setTimeout(run, t); });
    document.addEventListener('tabChanged', function () { setTimeout(run, 30); });
})();
</script>

</body>
</html>
