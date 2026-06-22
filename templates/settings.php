<?php // templates/settings.php — System Settings (Admin) ?>
<style>
.stab-btn{border:none;background:none;padding:9px 14px;font-size:.83rem;color:var(--text-muted);border-radius:6px;transition:all .15s;cursor:pointer;font-weight:500;display:flex;align-items:center;gap:8px;width:100%;text-align:left;}
.stab-btn:hover{background:var(--hover-bg,rgba(0,0,0,.05));color:var(--text);}
.stab-btn.active{background:var(--primary-light,rgba(0,48,135,.08));color:var(--primary,#003087);font-weight:600;}
.stab-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border-color);}
.stab-pane{animation:stFadeIn .15s ease;}
@keyframes stFadeIn{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
@media(max-width:640px){
    .settings-layout{flex-direction:column!important}
    .settings-tab-nav{width:100%!important;border-right:none!important;border-bottom:1px solid var(--border-color);display:flex!important;overflow-x:auto;gap:4px;padding-bottom:8px;padding-right:0!important}
    .settings-content{padding:16px 0 0!important}
}
</style>

<div class="page-header mb-0">
    <div>
        <h1 class="page-title">Settings</h1>
    </div>
    <div class="page-actions d-flex align-items-center gap-2">
        <button type="button" class="btn btn-outline-secondary btn-sm position-relative"
                data-bs-toggle="modal" data-bs-target="#adminNotificationsModal"
                title="Notifications" style="padding:6px 10px;">
            <i class="fas fa-bell"></i>
            <span id="settings-notif-badge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="display:none;font-size:.6rem;">0</span>
        </button>
    </div>
</div>

<div id="settingsAlert" style="display:none;" class="mt-3 mb-0"></div>

<div class="settings-layout d-flex gap-0 mt-3" style="min-height:520px;">

    <!-- ── Sidebar Tab Nav ─────────────────────────────────────── -->
    <div class="settings-tab-nav d-flex flex-column gap-1" style="width:175px;flex-shrink:0;border-right:1px solid var(--border-color);padding-right:8px;">
        <?php
        $sTabs = [
            ['id'=>'general',       'label'=>'General',       'icon'=>'fa-sliders'],
            ['id'=>'policies',      'label'=>'Policies',      'icon'=>'fa-gavel'],
            ['id'=>'users',         'label'=>'Users',         'icon'=>'fa-users'],
            ['id'=>'notifications', 'label'=>'Notifications', 'icon'=>'fa-bell'],
            ['id'=>'maintenance',   'label'=>'Maintenance',   'icon'=>'fa-wrench'],
        ];
        foreach ($sTabs as $t): ?>
        <button type="button" class="stab-btn<?= $t['id']==='general'?' active':'' ?>"
                data-stab="<?= $t['id'] ?>" onclick="switchSettingsTab('<?= $t['id'] ?>')">
            <i class="fas <?= $t['icon'] ?> fa-fw" style="font-size:.82rem;flex-shrink:0;"></i>
            <span><?= $t['label'] ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── Content Panes ──────────────────────────────────────── -->
    <div class="settings-content" style="flex:1;padding:0 0 0 24px;min-width:0;">

        <!-- ═══════ GENERAL ═══════ -->
        <div id="stab-general" class="stab-pane">
            <div class="stab-section-title">Library Information</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Library Name</label>
                    <input type="text" class="form-control form-control-sm" id="setting-library_name" placeholder="SDO Quirino Library">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Contact Number</label>
                    <input type="text" class="form-control form-control-sm" id="setting-library_contact" placeholder="(09xx) xxx-xxxx">
                </div>
                <div class="col-12 col-md-8">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Address</label>
                    <input type="text" class="form-control form-control-sm" id="setting-library_address" placeholder="Full address">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Library Email</label>
                    <input type="email" class="form-control form-control-sm" id="setting-library_email" placeholder="library@sdo.gov.ph">
                </div>
            </div>

            <div class="stab-section-title">Loan &amp; Fine Policy</div>
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Default Loan Period (days)</label>
                    <input type="number" class="form-control form-control-sm" id="setting-max_borrow_days" min="1" max="365">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Max Books Per Borrow</label>
                    <input type="number" class="form-control form-control-sm" id="setting-max_books_per_borrow" min="1" max="50">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Reservation Expiry (days)</label>
                    <input type="number" class="form-control form-control-sm" id="setting-reservation_expiry_days" min="1" max="30">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Default Fine/Day (₱)</label>
                    <input type="number" class="form-control form-control-sm" id="setting-fine_per_day" min="0" step="0.5">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="setting-auto_fine_enabled-cb"
                               onchange="document.getElementById('setting-auto_fine_enabled').value=this.checked?'1':'0'">
                        <label class="form-check-label" for="setting-auto_fine_enabled-cb" style="font-size:.82rem;">
                            Auto-calculate fines when returning overdue books
                        </label>
                        <input type="hidden" id="setting-auto_fine_enabled" value="1">
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" onclick="saveGeneralSettings()">
                    <i class="fas fa-floppy-disk me-2"></i>Save General Settings
                </button>
            </div>
        </div>

        <!-- ═══════ POLICIES ═══════ -->
        <div id="stab-policies" class="stab-pane" style="display:none;">
            <div class="stab-section-title">Per-Classification Borrowing Rules</div>
            <p class="text-muted mb-3" style="font-size:.79rem;">
                These rules override the global defaults for each borrower type. Set a lower fine to 0 to waive it for a class.
            </p>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle" style="font-size:.8rem;">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width:140px;">Classification</th>
                            <th>Loan Days</th>
                            <th>Max Books</th>
                            <th>Fine/Day (₱)</th>
                            <th>Reservation Expiry</th>
                            <th>Grace Period</th>
                        </tr>
                    </thead>
                    <tbody id="policies-tbody">
                        <tr><td colspan="6" class="text-center text-muted py-3">
                            <i class="fas fa-spinner fa-spin me-2"></i>Loading policies…
                        </td></tr>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" onclick="savePolicies()">
                    <i class="fas fa-floppy-disk me-2"></i>Save Policies
                </button>
            </div>
        </div>

        <!-- ═══════ USERS ═══════ -->
        <div id="stab-users" class="stab-pane" style="display:none;">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="stab-section-title mb-0">User Accounts</div>
                <button class="btn btn-success btn-sm" onclick="openUserModal(null)">
                    <i class="fas fa-plus me-1"></i>Create User
                </button>
            </div>
            <div class="mb-3 d-flex gap-2 flex-wrap">
                <input type="text" class="form-control form-control-sm" id="usersSearch"
                       placeholder="Search name or email…" oninput="renderUsersTable()" style="max-width:220px;">
                <select class="form-select form-select-sm" id="usersRoleFilter"
                        onchange="renderUsersTable()" style="max-width:130px;">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="staff">Staff</option>
                    <option value="viewer">Viewer</option>
                </select>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle" style="font-size:.8rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Since</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody">
                        <tr><td colspan="7" class="text-center text-muted py-3">
                            <i class="fas fa-spinner fa-spin me-2"></i>Loading…
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════ NOTIFICATIONS ═══════ -->
        <div id="stab-notifications" class="stab-pane" style="display:none;">
            <div class="stab-section-title">Admin Alerts</div>
            <div class="mb-4">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="setting-notify_borrow-cb"
                           onchange="document.getElementById('setting-notify_borrow').value=this.checked?'1':'0'">
                    <label class="form-check-label" for="setting-notify_borrow-cb" style="font-size:.85rem;">
                        <strong>Borrow Approved</strong> — Create admin notification when a borrow is approved
                    </label>
                    <input type="hidden" id="setting-notify_borrow" value="1">
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="setting-notify_overdue-cb"
                           onchange="document.getElementById('setting-notify_overdue').value=this.checked?'1':'0'">
                    <label class="form-check-label" for="setting-notify_overdue-cb" style="font-size:.85rem;">
                        <strong>Overdue Books</strong> — Create admin notification for overdue returns
                    </label>
                    <input type="hidden" id="setting-notify_overdue" value="1">
                </div>
            </div>

            <div class="stab-section-title">SMS via Semaphore</div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="setting-sms_enabled-cb"
                       onchange="document.getElementById('setting-sms_enabled').value=this.checked?'1':'0';toggleSmsFields(this.checked)">
                <label class="form-check-label fw-semibold" for="setting-sms_enabled-cb" style="font-size:.85rem;">
                    Enable SMS Notifications
                </label>
                <input type="hidden" id="setting-sms_enabled" value="0">
            </div>
            <div id="smsFieldsWrapper">
                <div class="row g-3 mb-4">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold" style="font-size:.78rem;">Semaphore API Key</label>
                        <input type="password" class="form-control form-control-sm" id="setting-sms_api_key"
                               placeholder="API Key from semaphore.co">
                        <div class="form-text" style="font-size:.7rem;">Get your key at <strong>semaphore.co</strong></div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold" style="font-size:.78rem;">Sender Name (max 11 chars)</label>
                        <input type="text" class="form-control form-control-sm" id="setting-sms_sender_name"
                               maxlength="11" placeholder="LIBRARY">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.78rem;">Borrow Confirmation Template</label>
                        <textarea class="form-control form-control-sm" id="setting-sms_borrow_message" rows="3"
                                  placeholder='Dear {name}, you borrowed "{book}". Due: {due}.'></textarea>
                        <div class="form-text" style="font-size:.7rem;">Tokens: {name} {book} {due}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold" style="font-size:.78rem;">Overdue Reminder Template</label>
                        <textarea class="form-control form-control-sm" id="setting-sms_overdue_message" rows="3"
                                  placeholder='Dear {name}, your book "{book}" is overdue. Fine: PHP {fine}.'></textarea>
                        <div class="form-text" style="font-size:.7rem;">Tokens: {name} {book} {fine}</div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" onclick="saveNotificationSettings()">
                    <i class="fas fa-floppy-disk me-2"></i>Save Notification Settings
                </button>
            </div>
        </div>

        <!-- ═══════ MAINTENANCE ═══════ -->
        <div id="stab-maintenance" class="stab-pane" style="display:none;">
            <div class="stab-section-title">System Status</div>
            <div class="d-flex gap-2 mb-4 flex-wrap align-items-center">
                <span class="badge bg-success px-3 py-2" style="font-size:.78rem;">
                    <i class="fas fa-circle me-1"></i>System Online
                </span>
                <span class="badge bg-light text-dark border px-3 py-2" style="font-size:.78rem;">
                    PHP <?= PHP_VERSION ?>
                </span>
            </div>

            <div class="stab-section-title" style="color:var(--danger,#dc3545);">Maintenance Mode</div>
            <div class="card border-danger mb-4" style="background:color-mix(in srgb,var(--danger,#dc3545) 5%,transparent);">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3 gap-3">
                        <div>
                            <div class="fw-semibold" style="font-size:.88rem;">Enable Maintenance Mode</div>
                            <div class="text-muted" style="font-size:.74rem;">Blocks all non-admin access and shows a message to users.</div>
                        </div>
                        <div class="form-check form-switch mb-0 flex-shrink-0">
                            <input class="form-check-input" type="checkbox" id="maintenance-toggle" role="switch"
                                   onchange="toggleMaintenance(this.checked)" style="width:2.8em;height:1.5em;">
                        </div>
                    </div>
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Maintenance Message</label>
                    <textarea class="form-control form-control-sm" id="setting-maintenance_msg" rows="2"
                              placeholder="System is under maintenance. Please check back later."></textarea>
                    <input type="hidden" id="setting-maintenance_mode" value="0">
                    <div class="mt-2 text-end">
                        <button class="btn btn-outline-secondary btn-sm" onclick="saveMaintenanceMsg()">
                            <i class="fas fa-floppy-disk me-1"></i>Save Message
                        </button>
                    </div>
                </div>
            </div>

            <div class="stab-section-title">Data Export</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <i class="fas fa-book fa-2x mb-2" style="color:var(--primary,#003087);"></i>
                            <div class="fw-semibold mb-1" style="font-size:.83rem;">Books Inventory</div>
                            <div class="text-muted mb-3" style="font-size:.72rem;">All books as CSV</div>
                            <button class="btn btn-sm btn-outline-primary" onclick="exportCsv('books')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <i class="fas fa-users fa-2x mb-2" style="color:var(--success,#198754);"></i>
                            <div class="fw-semibold mb-1" style="font-size:.83rem;">Members / Borrowers</div>
                            <div class="text-muted mb-3" style="font-size:.72rem;">All borrowers as CSV</div>
                            <button class="btn btn-sm btn-outline-success" onclick="exportCsv('borrowers')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="card h-100">
                        <div class="card-body text-center py-3">
                            <i class="fas fa-hand-holding-heart fa-2x mb-2" style="color:var(--info,#0dcaf0);"></i>
                            <div class="fw-semibold mb-1" style="font-size:.83rem;">Borrow Transactions</div>
                            <div class="text-muted mb-3" style="font-size:.72rem;">Borrow records as CSV</div>
                            <button class="btn btn-sm btn-outline-info" onclick="exportCsv('transactions')">
                                <i class="fas fa-download me-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="stab-section-title" style="color:var(--danger,#dc3545);">Danger Zone</div>
            <div class="card border-warning">
                <div class="card-body d-flex align-items-center justify-content-between gap-3">
                    <div>
                        <div class="fw-semibold" style="font-size:.85rem;">Purge Old Trash</div>
                        <div class="text-muted" style="font-size:.73rem;">Permanently delete documents in trash older than 30 days. Cannot be undone.</div>
                    </div>
                    <button class="btn btn-sm btn-outline-danger flex-shrink-0" onclick="purgeTrash()">
                        <i class="fas fa-fire-flame-curved me-1"></i>Purge Now
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /settings-content -->
</div><!-- /settings-layout -->

<!-- ── User Create/Edit Modal ─────────────────────────────────── -->
<div class="modal fade" id="userCrudModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userCrudTitle">Create User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="ucm-id">
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.8rem;">Full Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="ucm-name" placeholder="Juan Dela Cruz">
                </div>
                <div class="mb-3" id="ucm-email-wrap">
                    <label class="form-label fw-semibold" style="font-size:.8rem;">Email / Username <span class="text-danger">*</span></label>
                    <input type="email" class="form-control form-control-sm" id="ucm-email" placeholder="juan@deped.gov.ph">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" style="font-size:.8rem;" id="ucm-pw-label">
                        Password <span class="text-danger">*</span>
                    </label>
                    <input type="password" class="form-control form-control-sm" id="ucm-password" placeholder="Min 8 characters">
                    <div class="form-text" id="ucm-pw-hint" style="display:none;font-size:.7rem;">
                        Leave blank to keep the current password.
                    </div>
                </div>
                <div class="row g-3 mb-0">
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:.8rem;">Role</label>
                        <select class="form-select form-select-sm" id="ucm-role">
                            <option value="viewer">Viewer</option>
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold" style="font-size:.8rem;">Classification</label>
                        <select class="form-select form-select-sm" id="ucm-classification">
                            <option value="individual">Individual</option>
                            <option value="deped">DepEd Staff</option>
                            <option value="child">Child (0–12)</option>
                            <option value="teen">Teen (13–17)</option>
                            <option value="school">School / Institution</option>
                            <option value="professional">Professional</option>
                            <option value="private_institution">Private Institution</option>
                        </select>
                    </div>
                </div>
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="ucm-active" checked>
                    <label class="form-check-label" for="ucm-active" style="font-size:.82rem;">Account is Active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="submitUserCrud()">
                    <i class="fas fa-floppy-disk me-1"></i><span id="ucm-submit-label">Create</span>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// ─── Tab Navigation ──────────────────────────────────────────────────────────
let _stTabLoaded = {};

function switchSettingsTab(id) {
    document.querySelectorAll('.stab-btn').forEach(b => b.classList.toggle('active', b.dataset.stab === id));
    document.querySelectorAll('.stab-pane').forEach(p => { p.style.display = 'none'; });
    const pane = document.getElementById('stab-' + id);
    if (pane) pane.style.display = '';
    if (!_stTabLoaded[id]) {
        _stTabLoaded[id] = true;
        if (id === 'policies')    loadPolicies();
        if (id === 'users')       loadUsers();
        if (id === 'maintenance') loadMaintenanceState();
    }
}

// ─── Settings Load/Save ───────────────────────────────────────────────────────
const GENERAL_KEYS = [
    'library_name','library_address','library_contact','library_email',
    'max_borrow_days','max_books_per_borrow','reservation_expiry_days',
    'fine_per_day','auto_fine_enabled',
];
const NOTIF_KEYS = [
    'notify_borrow','notify_overdue',
    'sms_enabled','sms_api_key','sms_sender_name','sms_borrow_message','sms_overdue_message',
];

async function loadSettings() {
    const res = await fetch('api/library_handler.php?action=settings_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!res.success) return;
    const s = res.data || {};
    [...GENERAL_KEYS, ...NOTIF_KEYS].forEach(k => {
        const el = document.getElementById('setting-' + k);
        if (el) el.value = s[k] ?? '';
        const cb = document.getElementById('setting-' + k + '-cb');
        if (cb) cb.checked = s[k] === '1';
    });
    toggleSmsFields(s['sms_enabled'] === '1');
    const mt = document.getElementById('maintenance-toggle');
    if (mt) mt.checked = s['maintenance_mode'] === '1';
    const mm = document.getElementById('setting-maintenance_msg');
    if (mm) mm.value = s['maintenance_msg'] ?? '';
    const mh = document.getElementById('setting-maintenance_mode');
    if (mh) mh.value = s['maintenance_mode'] ?? '0';
}

async function _postSettings(keys) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new URLSearchParams({ action: 'settings_save' });
    keys.forEach(k => {
        const el = document.getElementById('setting-' + k);
        if (el) data.append('settings[' + k + ']', el.value);
    });
    return fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: data,
    }).then(r => r.json()).catch(() => ({ success: false, message: 'Network error' }));
}

function settingsToast(res) {
    const el = document.getElementById('settingsAlert');
    if (el) {
        el.style.display = 'block';
        el.className = `alert alert-${res.success ? 'success' : 'danger'} mt-3 mb-0`;
        el.innerHTML = `<i class="fas ${res.success ? 'fa-circle-check' : 'fa-circle-xmark'} me-2"></i>${res.message || (res.success ? 'Saved.' : 'Failed.')}`;
        setTimeout(() => { el.style.display = 'none'; }, 4000);
    }
    if (typeof showToast === 'function') showToast(res.message || (res.success ? 'Saved.' : 'Failed.'), res.success ? 'success' : 'error');
}

async function saveGeneralSettings() { settingsToast(await _postSettings(GENERAL_KEYS)); }
async function saveNotificationSettings() { settingsToast(await _postSettings(NOTIF_KEYS)); }
window.saveSettings = async function() { settingsToast(await _postSettings([...GENERAL_KEYS, ...NOTIF_KEYS])); };

function toggleSmsFields(on) {
    const w = document.getElementById('smsFieldsWrapper');
    if (w) w.style.opacity = on ? '1' : '.4';
}

// ─── Policies ─────────────────────────────────────────────────────────────────
const POLICY_CLASSES = [
    { id:'child',               label:'Child (0–12)' },
    { id:'teen',                label:'Teen (13–17)' },
    { id:'individual',          label:'Individual' },
    { id:'deped',               label:'DepEd Staff' },
    { id:'school',              label:'School / Institution' },
    { id:'professional',        label:'Professional' },
    { id:'private_institution', label:'Private Institution' },
];
let _policiesData = {};

async function loadPolicies() {
    const res = await fetch('api/library_handler.php?action=policies_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    _policiesData = res.success ? (res.data || {}) : {};
    const tbody = document.getElementById('policies-tbody');
    if (!tbody) return;

    const inp = (cls, field, def, step) =>
        `<input type="number" class="form-control form-control-sm policy-inp" min="0" step="${step||1}"
                style="width:72px;" data-cls="${cls}" data-field="${field}"
                value="${(_policiesData[cls] || {})[field] ?? def}">`;

    tbody.innerHTML = POLICY_CLASSES.map(c => `<tr>
        <td class="fw-semibold">${c.label}</td>
        <td>${inp(c.id,'max_borrow_days',14)}</td>
        <td>${inp(c.id,'max_books_per_borrow',5)}</td>
        <td>${inp(c.id,'fine_per_day',5,'0.5')}</td>
        <td>${inp(c.id,'reservation_expiry_days',3)}</td>
        <td>${inp(c.id,'grace_period_days',0)}</td>
    </tr>`).join('');
}

async function savePolicies() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new URLSearchParams({ action: 'policies_save' });
    document.querySelectorAll('.policy-inp').forEach(el =>
        data.append(`policies[${el.dataset.cls}][${el.dataset.field}]`, el.value)
    );
    settingsToast(await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: data,
    }).then(r => r.json()).catch(() => ({ success: false })));
}

// ─── Users Management ──────────────────────────────────────────────────────────
let _usersData = [];

async function loadUsers() {
    const res = await fetch('api/library_handler.php?action=users_list', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    _usersData = res.success ? (res.data || []) : [];
    renderUsersTable();
}

function renderUsersTable() {
    const q  = (document.getElementById('usersSearch')?.value || '').toLowerCase();
    const rf = document.getElementById('usersRoleFilter')?.value || '';
    const tbody = document.getElementById('users-tbody');
    if (!tbody) return;
    const filtered = _usersData.filter(u =>
        (!q || (u.full_name||'').toLowerCase().includes(q) || (u.username||'').toLowerCase().includes(q)) &&
        (!rf || u.role === rf)
    );
    if (!filtered.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">No users found.</td></tr>`;
        return;
    }
    const rc = { admin:'danger', staff:'warning', viewer:'secondary' };
    tbody.innerHTML = filtered.map(u => {
        const bd = (v,c) => `<span class="badge bg-${c}" style="font-size:.7rem;">${v}</span>`;
        const cls = (u.classification||'individual').replace(/_/g,' ');
        return `<tr>
            <td class="fw-semibold">${stEsc(u.full_name)}</td>
            <td class="text-muted" style="font-size:.77rem;">${stEsc(u.username)}</td>
            <td>${bd(u.role||'viewer', rc[u.role]||'secondary')}</td>
            <td style="text-transform:capitalize;font-size:.77rem;">${cls}</td>
            <td>${u.is_active==1 ? bd('Active','success') : bd('Inactive','light text-dark border')}</td>
            <td class="text-muted" style="font-size:.77rem;">${stFmtDate(u.created_at)}</td>
            <td class="text-end" style="white-space:nowrap;">
                <button class="btn btn-sm py-0 px-2 btn-outline-primary me-1" title="Edit"
                        onclick='openUserModal(${JSON.stringify(u)})'><i class="fas fa-pen" style="font-size:.72rem;"></i></button>
                <button class="btn btn-sm py-0 px-2 btn-outline-${u.is_active==1?'warning':'success'} me-1"
                        title="${u.is_active==1?'Deactivate':'Activate'}"
                        onclick="toggleUserStatus(${u.id},${u.is_active==1?0:1})">
                    <i class="fas fa-${u.is_active==1?'ban':'check'}" style="font-size:.72rem;"></i>
                </button>
                <button class="btn btn-sm py-0 px-2 btn-outline-danger" title="Delete"
                        onclick="deleteUser(${u.id},'${stEsc(u.full_name)}')">
                    <i class="fas fa-trash" style="font-size:.72rem;"></i>
                </button>
            </td>
        </tr>`;
    }).join('');
}

function openUserModal(user) {
    const editing = !!(user?.id);
    document.getElementById('userCrudTitle').textContent = editing ? 'Edit User' : 'Create User';
    document.getElementById('ucm-id').value            = user?.id || '';
    document.getElementById('ucm-name').value          = user?.full_name || '';
    document.getElementById('ucm-email').value         = user?.username || '';
    document.getElementById('ucm-password').value      = '';
    document.getElementById('ucm-role').value          = user?.role || 'viewer';
    document.getElementById('ucm-classification').value= user?.classification || 'individual';
    document.getElementById('ucm-active').checked      = user ? user.is_active == 1 : true;
    document.getElementById('ucm-pw-label').innerHTML  = `Password ${editing ? '' : '<span class="text-danger">*</span>'}`;
    document.getElementById('ucm-pw-hint').style.display   = editing ? '' : 'none';
    document.getElementById('ucm-email-wrap').style.display = editing ? 'none' : '';
    document.getElementById('ucm-submit-label').textContent = editing ? 'Save Changes' : 'Create User';
    new bootstrap.Modal(document.getElementById('userCrudModal')).show();
}

async function submitUserCrud() {
    const csrf    = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const id      = document.getElementById('ucm-id').value;
    const editing = !!id;
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({
            action:         editing ? 'users_update' : 'users_create',
            id,
            full_name:      document.getElementById('ucm-name').value,
            email:          document.getElementById('ucm-email').value,
            password:       document.getElementById('ucm-password').value,
            role:           document.getElementById('ucm-role').value,
            classification: document.getElementById('ucm-classification').value,
            is_active:      document.getElementById('ucm-active').checked ? '1' : '0',
        }),
    }).then(r => r.json()).catch(() => ({ success: false, message: 'Network error' }));
    settingsToast(res);
    if (res.success) {
        bootstrap.Modal.getInstance(document.getElementById('userCrudModal'))?.hide();
        await loadUsers();
    }
}

async function toggleUserStatus(id, newStatus) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'users_toggle_status', id, is_active: newStatus }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    if (res.success) {
        const u = _usersData.find(x => x.id == id);
        if (u) u.is_active = newStatus;
        renderUsersTable();
    }
    settingsToast(res);
}

async function deleteUser(id, name) {
    if (!confirm(`Permanently delete "${name}"? This cannot be undone.`)) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'users_delete', id }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    settingsToast(res);
    if (res.success) await loadUsers();
}

// ─── Maintenance ───────────────────────────────────────────────────────────────
async function loadMaintenanceState() {
    const res = await fetch('api/library_handler.php?action=settings_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!res.success) return;
    const s = res.data || {};
    const tog = document.getElementById('maintenance-toggle');
    if (tog) tog.checked = s['maintenance_mode'] === '1';
    const msg = document.getElementById('setting-maintenance_msg');
    if (msg) msg.value = s['maintenance_msg'] ?? '';
}

async function toggleMaintenance(on) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const msg  = document.getElementById('setting-maintenance_msg')?.value || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'maintenance_toggle', enabled: on ? '1' : '0', message: msg }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    settingsToast(res);
}

async function saveMaintenanceMsg() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const msg  = document.getElementById('setting-maintenance_msg')?.value || '';
    const on   = document.getElementById('maintenance-toggle')?.checked ? '1' : '0';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'maintenance_toggle', enabled: on, message: msg }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    settingsToast(res);
}

function exportCsv(type) {
    window.location.href = `api/library_handler.php?action=db_export_csv&type=${type}`;
}

async function purgeTrash() {
    if (!confirm('Permanently delete all documents in trash older than 30 days? This cannot be undone.')) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'db_purge_trash' }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    settingsToast(res);
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function stEsc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function stFmtDate(s) {
    if (!s) return '—';
    const d = new Date(s);
    return isNaN(d) ? s : d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}

// ─── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadSettings);
window.loadSettings        = loadSettings;
window.saveSettings        = window.saveSettings;
window.saveGeneralSettings = saveGeneralSettings;
window.saveNotificationSettings = saveNotificationSettings;
window.toggleSmsFields     = toggleSmsFields;
window.switchSettingsTab   = switchSettingsTab;
window.savePolicies        = savePolicies;
window.openUserModal       = openUserModal;
window.submitUserCrud      = submitUserCrud;
window.toggleUserStatus    = toggleUserStatus;
window.deleteUser          = deleteUser;
window.renderUsersTable    = renderUsersTable;
window.toggleMaintenance   = toggleMaintenance;
window.saveMaintenanceMsg  = saveMaintenanceMsg;
window.exportCsv           = exportCsv;
window.purgeTrash          = purgeTrash;
</script>
