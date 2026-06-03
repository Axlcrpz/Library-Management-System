<?php
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$userName = $currentUser['full_name'] ?? 'Guest';
$firstName = explode(' ', trim($userName))[0];
$today    = date('l, F j, Y');
?>

<!-- ── Welcome Banner ─────────────────────────────── -->
<div class="welcome-banner mb-4">
    <div style="position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <div class="welcome-title"><?= $greeting ?>, <?= htmlspecialchars($firstName) ?>!</div>
            <div class="welcome-sub"><?= $today ?> &nbsp;·&nbsp; Here's what's happening today.</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <select id="yearFilterDashboard" class="form-select form-select-sm"
                    style="width:auto;background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff;font-size:.78rem;"></select>
            <?php if ($isAdmin): ?>
            <button class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);"
                    data-bs-toggle="modal" data-bs-target="#notificationsModal" id="notif-btn">
                <i class="fas fa-bell"></i>
                <span id="notif-count" class="badge ms-1" style="background:#ef4444;display:none;font-size:.65rem;">0</span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ════════════════════════════════════════════════
     ADMIN DASHBOARD
════════════════════════════════════════════════ -->

<!-- Quick Actions -->
<div class="row g-3 mb-4">
    <?php
    $quickActions = [
        ['label'=>'Add Book',         'icon'=>'fa-book-medical',   'color'=>'var(--primary)',  'bg'=>'var(--primary-light)',   'onclick'=>'openAddBookModal()'],
        ['label'=>'Log Delivery',     'icon'=>'fa-truck-ramp-box', 'color'=>'var(--success)',  'bg'=>'var(--success-light)',   'onclick'=>'openAddDeliveryModal()'],
        ['label'=>'New Borrow',       'icon'=>'fa-hand-holding',   'color'=>'var(--warning)',  'bg'=>'var(--warning-light)',   'onclick'=>"switchTabById('borrowing')"],
        ['label'=>'Add Document',     'icon'=>'fa-file-circle-plus','color'=>'var(--purple)',  'bg'=>'var(--purple-light)',    'onclick'=>"document.querySelector('.btn-open-add')?.click()"],
        ['label'=>'Post Announcement','icon'=>'fa-bullhorn',        'color'=>'var(--info)',     'bg'=>'var(--info-light)',      'onclick'=>'openAddAnnouncementModal()'],
    ];
    foreach ($quickActions as $qa): ?>
    <div class="col">
        <div onclick="<?= $qa['onclick'] ?>"
             style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;cursor:pointer;transition:all .18s;display:flex;align-items:center;gap:12px;"
             onmouseover="this.style.borderColor='<?= $qa['color'] ?>';this.style.boxShadow='0 0 0 3px <?= str_replace(')', ',.1)', $qa['bg']) ?>'"
             onmouseout="this.style.borderColor='var(--border)';this.style.boxShadow='none'">
            <div style="width:38px;height:38px;border-radius:9px;background:<?= $qa['bg'] ?>;display:flex;align-items:center;justify-content:center;color:<?= $qa['color'] ?>;font-size:.95rem;flex-shrink:0;">
                <i class="fas <?= $qa['icon'] ?>"></i>
            </div>
            <span style="font-size:.8rem;font-weight:600;color:var(--text);"><?= $qa['label'] ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Stat cards row 1 — Books -->
<div class="row g-3 mb-3">
    <?php
    $bookCards = [
        ['id'=>'book-stat-total',    'label'=>'Total Books',      'icon'=>'fa-book',                'cls'=>'stat-primary'],
        ['id'=>'book-stat-available','label'=>'Available',        'icon'=>'fa-book-open',           'cls'=>'stat-success'],
        ['id'=>'book-stat-borrowed', 'label'=>'Borrowed',         'icon'=>'fa-hand-holding-heart',  'cls'=>'stat-warning'],
        ['id'=>'book-stat-overdue',  'label'=>'Overdue',          'icon'=>'fa-triangle-exclamation','cls'=>'stat-danger'],
        ['id'=>'book-stat-today',    'label'=>"Today's Activity", 'icon'=>'fa-receipt',             'cls'=>'stat-info'],
    ];
    foreach ($bookCards as $c): ?>
    <div class="col-6 col-lg">
        <div class="stat-card <?= $c['cls'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label"><?= $c['label'] ?></div>
                    <div class="stat-card-value" id="<?= $c['id'] ?>">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas <?= $c['icon'] ?>"></i></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Stat cards row 2 — Documents -->
<div class="row g-3 mb-4">
    <?php
    $docCards = [
        ['id'=>'stat-total',    'label'=>'Total Documents','icon'=>'fa-file-lines',  'cls'=>'stat-primary'],
        ['id'=>'stat-available','label'=>'Available',      'icon'=>'fa-circle-check','cls'=>'stat-success'],
        ['id'=>'stat-borrowed', 'label'=>'Borrowed',       'icon'=>'fa-hand-holding','cls'=>'stat-warning'],
        ['id'=>'stat-archived', 'label'=>'Archived',       'icon'=>'fa-box-archive', 'cls'=>'stat-purple'],
    ];
    foreach ($docCards as $c): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card <?= $c['cls'] ?>">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label"><?= $c['label'] ?></div>
                    <div class="stat-card-value" id="<?= $c['id'] ?>">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas <?= $c['icon'] ?>"></i></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Charts + Active Borrows -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;">Document Status</span>
            </div>
            <div class="card-body">
                <div class="chart-container" style="height:180px;">
                    <canvas id="completionTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;">Document Types</span>
            </div>
            <div class="card-body">
                <div class="chart-container" style="height:180px;">
                    <canvas id="categorySummaryChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header" style="justify-content:space-between;">
                <span style="font-weight:600;font-size:.85rem;">Active Borrows</span>
                <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;padding:3px 8px;"
                        onclick="switchTabById('borrowing')">View all</button>
            </div>
            <div class="card-body p-0" style="overflow-y:auto;max-height:220px;">
                <div id="dashboard-active-borrows">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Announcements + Deliveries -->
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;"><i class="fas fa-bullhorn me-2" style="color:var(--warning);"></i>Announcements</span>
                <button class="btn btn-primary btn-sm" onclick="openAddAnnouncementModal()">
                    <i class="fas fa-plus me-1"></i> Post
                </button>
            </div>
            <div class="card-body p-0" style="max-height:260px;overflow-y:auto;">
                <div id="announcements-container">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;"><i class="fas fa-truck me-2" style="color:var(--info);"></i>Recent Deliveries</span>
                <button class="btn btn-success btn-sm" onclick="openAddDeliveryModal()">
                    <i class="fas fa-plus me-1"></i> Log
                </button>
            </div>
            <div class="card-body p-0" style="max-height:260px;overflow-y:auto;">
                <div id="deliveries-container">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Book Inventory -->
<div class="card mb-2">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-book" style="color:var(--success);"></i>
            <span style="font-weight:600;font-size:.85rem;">Book Inventory</span>
            <button class="btn btn-primary btn-sm" onclick="openAddBookModal()">
                <i class="fas fa-plus me-1"></i> Add
            </button>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div class="input-group input-group-sm" style="width:190px;">
                <span class="input-group-text"><i class="fas fa-search" style="font-size:.68rem;"></i></span>
                <input type="text" class="form-control" id="book-search-dashboard"
                       placeholder="Search..." oninput="filterBooksTable()">
            </div>
            <select id="book-subject-filter" class="form-select form-select-sm"
                    style="width:auto;" onchange="filterBooksTable()">
                <option value="">All Subjects</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Subject</th>
                        <th>Grade</th>
                        <th>Location</th>
                        <th>Available</th>
                        <th>Condition</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="books-table-dashboard">
                    <tr><td colspan="7" class="text-center text-muted py-4" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i> Loading...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════
     USER DASHBOARD
════════════════════════════════════════════════ -->

<!-- User Quick Actions -->
<div class="row g-3 mb-4">
    <?php
    $userActions = [
        ['label'=>'Search Books',     'icon'=>'fa-magnifying-glass','color'=>'var(--primary)', 'bg'=>'var(--primary-light)', 'onclick'=>"switchTabById('books')"],
        ['label'=>'Borrow a Book',    'icon'=>'fa-hand-holding',    'color'=>'var(--success)', 'bg'=>'var(--success-light)', 'onclick'=>"switchTabById('borrowing');setTimeout(()=>openBorrowRequestModal(),300)"],
        ['label'=>'My Transactions',  'icon'=>'fa-list-check',      'color'=>'var(--warning)', 'bg'=>'var(--warning-light)', 'onclick'=>"switchTabById('borrowing')"],
        ['label'=>'View Inventory',   'icon'=>'fa-book-open',       'color'=>'var(--info)',    'bg'=>'var(--info-light)',    'onclick'=>"switchTabById('books')"],
    ];
    foreach ($userActions as $qa): ?>
    <div class="col-6 col-md-3">
        <div onclick="<?= $qa['onclick'] ?>"
             style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px 16px;cursor:pointer;transition:all .18s;text-align:center;"
             onmouseover="this.style.borderColor='<?= $qa['color'] ?>';this.style.transform='translateY(-2px)';this.style.boxShadow='var(--shadow)'"
             onmouseout="this.style.borderColor='var(--border)';this.style.transform='none';this.style.boxShadow='none'">
            <div style="width:46px;height:46px;border-radius:12px;background:<?= $qa['bg'] ?>;display:flex;align-items:center;justify-content:center;color:<?= $qa['color'] ?>;font-size:1.1rem;margin:0 auto 10px;">
                <i class="fas <?= $qa['icon'] ?>"></i>
            </div>
            <div style="font-size:.78rem;font-weight:600;color:var(--text);"><?= $qa['label'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- User stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Borrowed Books</div>
                    <div class="stat-card-value" id="user-stat-borrowed">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-book-reader"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Due Soon</div>
                    <div class="stat-card-value" id="user-stat-due">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Overdue</div>
                    <div class="stat-card-value" id="user-stat-overdue">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Pending Requests</div>
                    <div class="stat-card-value" id="user-stat-pending">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Active borrows + Announcements -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;"><i class="fas fa-list-check me-2" style="color:var(--primary);"></i>My Active Borrows</span>
                <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;padding:3px 8px;"
                        onclick="switchTabById('borrowing')">View all</button>
            </div>
            <div class="card-body p-0">
                <div id="user-active-borrows">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <span style="font-weight:600;font-size:.85rem;"><i class="fas fa-bullhorn me-2" style="color:var(--warning);"></i>Announcements</span>
            </div>
            <div class="card-body p-0" style="max-height:300px;overflow-y:auto;">
                <div id="announcements-container">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Book availability -->
<div class="card mb-2">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-book" style="color:var(--success);"></i>
            <span style="font-weight:600;font-size:.85rem;">Book Availability</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <div class="input-group input-group-sm" style="width:190px;">
                <span class="input-group-text"><i class="fas fa-search" style="font-size:.68rem;"></i></span>
                <input type="text" class="form-control" id="book-search-dashboard"
                       placeholder="Search..." oninput="filterBooksTable()">
            </div>
            <select id="book-subject-filter" class="form-select form-select-sm"
                    style="width:auto;" onchange="filterBooksTable()">
                <option value="">All Subjects</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Subject</th>
                        <th>Grade</th>
                        <th>Location</th>
                        <th>Available</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody id="books-table-dashboard">
                    <tr><td colspan="6" class="text-center text-muted py-4" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i> Loading...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>