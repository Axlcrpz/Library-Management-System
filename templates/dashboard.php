<?php
$userName  = $currentUser['full_name'] ?? 'Guest';
$firstName = htmlspecialchars(explode(' ', trim($userName))[0]);
?>

<!-- ── Welcome Banner ─────────────────────────────── -->
<div class="welcome-banner mb-4">
    <div style="position:relative;z-index:1;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div id="greeting-clock" style="line-height:1.2;">
            <div id="clock-time" style="font-size:1.6rem;font-weight:700;letter-spacing:.05em;color:#fff;"></div>
            <div id="clock-date" style="font-size:.75rem;opacity:.75;margin-top:2px;"></div>
        </div>
        <div style="text-align:center;">
            <div class="welcome-title" id="greeting-text">Hello, <?= $firstName ?>!</div>
            <div class="welcome-sub" id="greeting-date">&nbsp;·&nbsp; Here's what's happening today.</div>
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

<script>
(function() {
    function updateGreeting() {
        var now  = new Date();
        var h    = now.getHours();
        var g    = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
        var name = <?= json_encode($firstName) ?>;

        var days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];

        var gel  = document.getElementById('greeting-text');
        var del  = document.getElementById('greeting-date');
        var tel  = document.getElementById('clock-time');
        var dtel = document.getElementById('clock-date');

        if (gel) {
            gel.textContent = g + ', ' + name + '!';
        }

        if (del) {
            del.textContent = "Here's what's happening today.";
        }

        // Display 12-hour format with AM/PM
        if (tel) {
            tel.textContent = now.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            });
        }

        if (dtel) {
            dtel.textContent =
                days[now.getDay()] + ', ' +
                months[now.getMonth()] + ' ' +
                now.getDate() + ', ' +
                now.getFullYear();
        }
    }

    updateGreeting();
    setInterval(updateGreeting, 1000);
})();
</script>

<?php if ($isAdmin): ?>
<!-- ════════════════════════════════════════════════
     ADMIN DASHBOARD
════════════════════════════════════════════════ -->

<!-- Quick Actions -->
<div class="row g-3 mb-4">
    <?php
    $quickActions = [
        ['label'=>'Add Book',         'icon'=>'fa-book-medical',    'color'=>'var(--primary)', 'bg'=>'var(--primary-light)',  'onclick'=>'openAddBookModal()'],
        ['label'=>'Log Delivery',     'icon'=>'fa-truck-ramp-box',  'color'=>'var(--success)', 'bg'=>'var(--success-light)',  'onclick'=>'openAddDeliveryModal()'],
        ['label'=>'New Borrow',       'icon'=>'fa-hand-holding',    'color'=>'var(--warning)', 'bg'=>'var(--warning-light)',  'onclick'=>"switchTabById('borrowing')"],
        ['label'=>'Add Document',     'icon'=>'fa-file-circle-plus','color'=>'var(--purple)',  'bg'=>'var(--purple-light)',   'onclick'=>"document.querySelector('.btn-open-add')?.click()"],
        ['label'=>'Post Announcement','icon'=>'fa-bullhorn',         'color'=>'var(--info)',    'bg'=>'var(--info-light)',     'onclick'=>'openAddAnnouncementModal()'],
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

<!-- ── Inventory summary — unified KPIs + category breakdown ─────────────────── -->
<div class="card mb-4" id="inventorySummaryCard">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2" style="padding:12px 18px;">
        <span style="font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px;">
            <i class="fas fa-book" style="color:var(--primary);font-size:.85rem;"></i> Book Inventory
        </span>
        <button class="btn btn-sm btn-outline-primary" style="font-size:.74rem;padding:4px 11px;"
                onclick="switchTabById('books')">
            <i class="fas fa-up-right-from-square me-1"></i> Open inventory
        </button>
    </div>

    <!-- KPI strip -->
    <div class="row g-0" style="border-bottom:1px solid var(--border);">
        <?php
        $invKpis = [
            ['id'=>'book-stat-total',     'label'=>'Total copies', 'color'=>'var(--primary)', 'sub'=>'in catalog'],
            ['id'=>'book-stat-available', 'label'=>'Available',    'color'=>'var(--success)', 'sub'=>'on the shelf'],
            ['id'=>'book-stat-borrowed',  'label'=>'Borrowed',     'color'=>'var(--warning)', 'sub'=>'checked out'],
            ['id'=>'book-stat-overdue',   'label'=>'Overdue',      'color'=>'var(--danger)',  'sub'=>'past due'],
        ];
        foreach ($invKpis as $i => $k): ?>
        <div class="col-6 col-md-3" style="padding:14px 18px;<?= $i < 3 ? 'border-right:1px solid var(--border);' : '' ?>">
            <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#9ca3af);">
                <?= $k['label'] ?>
            </div>
            <div id="<?= $k['id'] ?>" style="font-size:1.7rem;font-weight:800;color:<?= $k['color'] ?>;line-height:1.15;">—</div>
            <div style="font-size:.64rem;color:var(--text-muted,#9ca3af);"><?= $k['sub'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Category breakdown header -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:11px 18px 4px;">
        <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#9ca3af);">
            By category <span id="bcat-count" style="font-weight:600;"></span>
        </span>
        <div class="input-group input-group-sm" style="width:180px;">
            <span class="input-group-text" style="font-size:.7rem;"><i class="fas fa-search"></i></span>
            <input type="text" id="bcat-search" class="form-control" placeholder="Filter categories…"
                   oninput="renderCategoryList()" style="font-size:.78rem;">
        </div>
    </div>

    <!-- Category list (each row links to filtered Inventory) -->
    <div id="bcatList" style="padding:4px 10px 10px;">
        <div class="text-center text-muted py-4" style="font-size:.82rem;">
            <i class="fas fa-spinner fa-spin me-1"></i> Loading categories…
        </div>
    </div>

    <!-- Smart empty/data-quality banner -->
    <div id="bcat-banner" style="display:none;margin:0 14px 14px;"></div>
</div>

<!-- Today's book activity — the one KPI not in the inventory card above -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Today's Activity</div>
                    <div class="stat-card-value" id="book-stat-today">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-receipt"></i></div>
            </div>
        </div>
    </div>
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

<!-- Document collection breakdown + Active Borrows -->
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                <span style="font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-folder-tree" style="color:var(--purple,#7c3aed);font-size:.85rem;"></i> Document Collection
                </span>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span id="doclib-summary" style="font-size:.72rem;color:var(--text-muted);"></span>
                    <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem;padding:3px 10px;"
                            onclick="switchTabById('documents')">
                        <i class="fas fa-up-right-from-square me-1"></i> Open documents
                    </button>
                </div>
            </div>
            <div class="card-body p-0" style="overflow-y:auto;max-height:305px;">
                <div id="doclib-list">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header" style="justify-content:space-between;">
                <span style="font-weight:600;font-size:.85rem;">Active Borrows</span>
                <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;padding:3px 8px;"
                        onclick="switchTabById('borrowing')">View all</button>
            </div>
            <div class="card-body p-0" style="overflow-y:auto;max-height:305px;">
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
                <div id="announcements-container-admin" class="announcements-container">
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

<!-- Full book table removed from the dashboard — it duplicated the Inventory tab.
     The Inventory summary card above gives at-a-glance health and links to the full module. -->

<script>
// ── Book inventory — category breakdown (single panel, links to Inventory) ────
let _bcatData = [];
const bcatEsc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
function escHtml(s) { return bcatEsc(s); }

function loadCategoryStats() {
    return libApi('book_category_stats').then(r => {
        _bcatData = (r.data || []).slice().sort((a, b) =>
            parseInt(b.copies || b.total || 0) - parseInt(a.copies || a.total || 0));
        renderCategoryList();
        renderBcatBanner();
    }).catch(() => {});
}

// ── Category list — each row navigates to the Inventory tab, pre-filtered ──────
function renderCategoryList() {
    const list = document.getElementById('bcatList');
    const countEl = document.getElementById('bcat-count');
    if (!list) return;

    const q = (document.getElementById('bcat-search')?.value || '').toLowerCase().trim();
    const cats = q ? _bcatData.filter(c => String(c.subject || '').toLowerCase().includes(q)) : _bcatData;

    if (countEl) {
        const n = _bcatData.length;
        countEl.textContent = `· ${n} categor${n !== 1 ? 'ies' : 'y'}`;
    }

    if (!cats.length) {
        list.innerHTML = `<div class="text-center text-muted py-4" style="font-size:.82rem;">
            <i class="fas fa-folder-open me-1"></i> No categories match “${bcatEsc(q)}”.</div>`;
        return;
    }

    list.innerHTML = cats.map(c => {
        const idx    = _bcatData.indexOf(c);
        const copies = parseInt(c.copies || c.total || 0);
        const titles = parseInt(c.total || 0);
        const avail  = parseInt(c.available || 0);
        const pct    = copies > 0 ? Math.round((avail / copies) * 100) : 0;
        const barCol = pct >= 50 ? 'var(--success,#10b981)' : (pct > 0 ? 'var(--warning,#f59e0b)' : 'var(--danger,#ef4444)');
        return `
        <div onclick="bcatGoto(${idx})" role="button" tabindex="0"
             onkeydown="if(event.key==='Enter')bcatGoto(${idx})"
             style="display:flex;align-items:center;gap:14px;padding:11px 12px;border-radius:9px;cursor:pointer;transition:background .12s;"
             onmouseover="this.style.background='var(--bg,#f8fafc)'" onmouseout="this.style.background='transparent'">
            <div style="min-width:130px;max-width:200px;">
                <div style="font-size:.85rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${bcatEsc(c.subject || 'Uncategorized')}</div>
                <div style="font-size:.66rem;color:var(--text-muted,#9ca3af);">${titles} title${titles !== 1 ? 's' : ''}</div>
            </div>
            <div style="flex:1;min-width:50px;height:7px;border-radius:99px;background:var(--border,#eef1f5);overflow:hidden;">
                <div style="width:${pct}%;height:100%;background:${barCol};border-radius:99px;transition:width .5s;"></div>
            </div>
            <div style="font-size:.78rem;color:var(--text-muted,#6b7280);white-space:nowrap;font-variant-numeric:tabular-nums;">
                <b style="color:var(--text);">${avail.toLocaleString()}</b> / ${copies.toLocaleString()} avail.
            </div>
            <i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:.7rem;"></i>
        </div>`;
    }).join('');
}

// ── Navigate to Inventory tab, pre-filtered to the chosen category ────────────
// (Targets the inventory module's live search box — the old subject <select>
//  this pointed at no longer exists, so the filter silently never applied.)
function bcatGoto(idx) {
    const cat = _bcatData[idx];
    const subject = cat ? (cat.subject || '') : '';
    switchTabById('books');
    setTimeout(() => {
        const inv = document.getElementById('inv-search');
        if (inv) {
            inv.dataset.unlocked = '1';
            inv.removeAttribute('readonly');
            inv.value = subject === 'Uncategorized' ? '' : subject;
        }
        if (typeof invApplyFilters === 'function') invApplyFilters();
    }, 300);
}

// ── Data-quality banner — surfaces the real problem behind a flat breakdown ────
function renderBcatBanner() {
    const el = document.getElementById('bcat-banner');
    if (!el) return;
    const totalCopies = _bcatData.reduce((s, c) => s + parseInt(c.copies || c.total || 0), 0);
    const uncat = _bcatData.find(c => String(c.subject || '').toLowerCase() === 'uncategorized'
                                   || !String(c.subject || '').trim());
    const uncatCopies = uncat ? parseInt(uncat.copies || uncat.total || 0) : 0;
    const share = totalCopies > 0 ? uncatCopies / totalCopies : 0;

    if (share < 0.5 || uncatCopies === 0) { el.style.display = 'none'; return; }

    <?php if (!empty($isStaff)): ?>
    const actions = `
        <button class="btn btn-sm" style="font-size:.72rem;padding:4px 10px;background:#fff;border:1px solid #fcd34d;color:#92400e;"
                onclick="switchTabById('books')">
            <i class="fas fa-wand-magic-sparkles me-1"></i>Categorize books
        </button>`;
    <?php else: ?>
    const actions = '';
    <?php endif; ?>

    el.style.display = 'block';
    el.innerHTML = `
        <div style="display:flex;align-items:flex-start;gap:11px;padding:12px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;">
            <i class="fas fa-tags" style="color:#d97706;font-size:1rem;margin-top:1px;flex-shrink:0;"></i>
            <div style="flex:1;">
                <div style="font-size:.8rem;color:#92400e;line-height:1.5;">
                    <b>${Math.round(share * 100)}%</b> of copies (${uncatCopies.toLocaleString()}) are uncategorized${
                        _bcatData.length === 1 ? ', and grade & location are unset' : ''}.
                    Categorizing them turns this list into a useful breakdown.
                </div>
                ${actions ? `<div style="margin-top:9px;">${actions}</div>` : ''}
            </div>
        </div>`;
}

document.addEventListener('tabChanged', e => {
    if (e.detail === 'dashboard') loadCategoryStats();
});
window.addEventListener('load', () => {
    if (document.getElementById('dashboard')?.classList.contains('active')) {
        loadCategoryStats();
    }
});
</script>

<?php else: ?>
<!-- ════════════════════════════════════════════════
     USER DASHBOARD
════════════════════════════════════════════════ -->

<!-- User Quick Actions -->
<div class="row g-3 mb-4">
    <?php
    $userActions = [
        ['label'=>'Book Inventory',    'icon'=>'fa-magnifying-glass','color'=>'var(--primary)', 'bg'=>'var(--primary-light)', 'onclick'=>"switchTabById('books')"],
        ['label'=>'My Borrowed Book/s','icon'=>'fa-list-check',     'color'=>'var(--success)', 'bg'=>'var(--success-light)', 'onclick'=>"switchTabById('borrowing')"],
        ['label'=>'My Reservation/s', 'icon'=>'fa-bookmark',        'color'=>'var(--warning)', 'bg'=>'var(--warning-light)', 'onclick'=>"switchTabById('reservations')"],
        ['label'=>'My Account',      'icon'=>'fa-circle-user',     'color'=>'var(--info)',    'bg'=>'var(--info-light)',    'onclick'=>"switchTabById('my-account')"],
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
                <div><div class="stat-card-label">Borrowed Books</div>
                <div class="stat-card-value" id="user-stat-borrowed">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-book-reader"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Due Soon</div>
                <div class="stat-card-value" id="user-stat-due">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Overdue</div>
                <div class="stat-card-value" id="user-stat-overdue">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Pending Requests</div>
                <div class="stat-card-value" id="user-stat-pending">—</div></div>
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
                <div id="announcements-container-user" class="announcements-container">
                    <div class="text-center text-muted py-4" style="font-size:.8rem;">
                        <i class="fas fa-spinner fa-spin me-1"></i> Loading...
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>