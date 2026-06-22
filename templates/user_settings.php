<?php // templates/user_settings.php — My Account (All Users) ?>
<style>
.ustab-btn{border:none;background:none;padding:9px 14px;font-size:.83rem;color:var(--text-muted);border-radius:6px;transition:all .15s;cursor:pointer;font-weight:500;display:flex;align-items:center;gap:8px;width:100%;text-align:left;}
.ustab-btn:hover{background:var(--hover-bg,rgba(0,0,0,.05));color:var(--text);}
.ustab-btn.active{background:var(--primary-light,rgba(0,48,135,.08));color:var(--primary,#003087);font-weight:600;}
.ustab-section{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid var(--border-color);}
.ustab-pane{animation:uaFadeIn .15s ease;}
@keyframes uaFadeIn{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
@media(max-width:640px){
    .uacct-layout{flex-direction:column!important}
    .ustab-nav{width:100%!important;border-right:none!important;border-bottom:1px solid var(--border-color);display:flex!important;overflow-x:auto;gap:4px;padding-bottom:8px;padding-right:0!important}
    .ustab-content{padding:16px 0 0!important}
}
</style>

<div class="page-header mb-0">
    <div>
        <h1 class="page-title">My Account</h1>
    </div>
</div>

<div id="myAcctAlert" style="display:none;" class="mt-3 mb-0"></div>

<div class="uacct-layout d-flex gap-0 mt-3" style="min-height:520px;">

    <!-- ── Sidebar Tab Nav ────────────────────────────────────── -->
    <div class="ustab-nav d-flex flex-column gap-1" style="width:175px;flex-shrink:0;border-right:1px solid var(--border-color);padding-right:8px;">
        <?php
        $uaTabs = [
            ['id'=>'profile',       'label'=>'Profile',        'icon'=>'fa-user'],
            ['id'=>'notifications', 'label'=>'Notifications',  'icon'=>'fa-bell'],
            ['id'=>'activity',      'label'=>'My Activity',    'icon'=>'fa-clock-rotate-left'],
            ['id'=>'security',      'label'=>'Security',       'icon'=>'fa-shield-halved'],
        ];
        foreach ($uaTabs as $t): ?>
        <button type="button" class="ustab-btn<?= $t['id']==='profile'?' active':'' ?>"
                data-utab="<?= $t['id'] ?>" onclick="switchAcctTab('<?= $t['id'] ?>')">
            <i class="fas <?= $t['icon'] ?> fa-fw" style="font-size:.82rem;flex-shrink:0;"></i>
            <span><?= $t['label'] ?></span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- ── Content Panes ─────────────────────────────────────── -->
    <div class="ustab-content" style="flex:1;padding:0 0 0 24px;min-width:0;">

        <!-- ═══════ PROFILE ═══════ -->
        <div id="utab-profile" class="ustab-pane">
            <div class="ustab-section">Profile Photo</div>
            <div class="d-flex align-items-center gap-4 mb-4 flex-wrap">
                <img id="myAvatarPreview" class="avatar-img av-fit-initials" src="api/library_handler.php?action=user_avatar"
                     alt="Your avatar"
                     style="width:96px;height:96px;border-radius:50%;border:2px solid var(--border-color);flex-shrink:0;">
                <div style="flex:1;min-width:220px;">
                    <div class="d-flex gap-2 flex-wrap mb-2">
                        <button class="btn btn-primary btn-sm" onclick="document.getElementById('avatarFileInput').click()">
                            <i class="fas fa-camera me-1"></i>Upload Photo
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" onclick="openAvatarGallery()">
                            <i class="fas fa-grip me-1"></i>Choose Avatar
                        </button>
                        <button class="btn btn-outline-danger btn-sm" id="avatarRemoveBtn" style="display:none;" onclick="removeAvatarPhoto()">
                            <i class="fas fa-trash me-1"></i>Remove
                        </button>
                        <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="onAvatarFileChosen(event)">
                    </div>
                    <div id="avatarHint" class="text-muted" style="font-size:.74rem;">
                         Photo changes are limited to once every 30 days.
                    </div>
                </div>
            </div>

            <div class="ustab-section">Personal Information</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Full Name</label>
                    <input type="text" class="form-control form-control-sm" id="myp-name" placeholder="Your full name">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Contact Number</label>
                    <input type="text" class="form-control form-control-sm" id="myp-contact" placeholder="(09xx) xxx-xxxx">
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Email / Username</label>
                    <input type="text" class="form-control form-control-sm" id="myp-email" disabled
                           style="background:var(--input-disabled-bg,#f8f9fa);">
                    <div class="form-text" style="font-size:.7rem;">Contact admin to change your email address.</div>
                </div>
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Role</label>
                    <input type="text" class="form-control form-control-sm" id="myp-role" disabled
                           style="background:var(--input-disabled-bg,#f8f9fa);">
                </div>
            </div>
            <div class="d-flex justify-content-end mb-5">
                <button class="btn btn-primary btn-sm" onclick="saveProfile()">
                    <i class="fas fa-floppy-disk me-2"></i>Save Profile
                </button>
            </div>

            <div class="ustab-section">Change Password</div>
            <div class="row g-3 mb-4">
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Current Password</label>
                    <input type="password" class="form-control form-control-sm" id="pw-current"
                           placeholder="Enter current password">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">New Password</label>
                    <input type="password" class="form-control form-control-sm" id="pw-new"
                           placeholder="Min 8 characters">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label fw-semibold" style="font-size:.78rem;">Confirm New Password</label>
                    <input type="password" class="form-control form-control-sm" id="pw-confirm"
                           placeholder="Repeat new password">
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button class="btn btn-outline-warning btn-sm" onclick="changePassword()">
                    <i class="fas fa-key me-2"></i>Change Password
                </button>
            </div>
        </div>

        <!-- ═══════ NOTIFICATIONS ═══════ -->
        <div id="utab-notifications" class="ustab-pane" style="display:none;">
            <div class="ustab-section">Notification Preferences</div>
            <p class="text-muted mb-4" style="font-size:.78rem;">
                <i class="fas fa-circle-info me-1"></i>SMS alerts require a phone number on your profile.
            </p>
            <div class="mb-4">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="unp-borrow_sms">
                    <label class="form-check-label" for="unp-borrow_sms" style="font-size:.85rem;">
                        <strong>Borrow Confirmation</strong> — Receive SMS when your borrow is approved
                    </label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="unp-overdue_sms">
                    <label class="form-check-label" for="unp-overdue_sms" style="font-size:.85rem;">
                        <strong>Overdue Reminder</strong> — Receive SMS when your book becomes overdue
                    </label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="unp-due_reminder">
                    <label class="form-check-label" for="unp-due_reminder" style="font-size:.85rem;">
                        <strong>Due Date Reminder</strong> — Receive SMS one day before your book is due
                    </label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="unp-announcements">
                    <label class="form-check-label" for="unp-announcements" style="font-size:.85rem;">
                        <strong>Announcements</strong> — Receive SMS for library announcements
                    </label>
                </div>
            </div>
            <div class="d-flex justify-content-end">
                <button class="btn btn-primary btn-sm" onclick="saveNotifPrefs()">
                    <i class="fas fa-floppy-disk me-2"></i>Save Preferences
                </button>
            </div>
        </div>

        <!-- ═══════ ACTIVITY ═══════ -->
        <div id="utab-activity" class="ustab-pane" style="display:none;">
            <div class="ustab-section">My Borrow History</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle" style="font-size:.8rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Books</th>
                            <th>Status</th>
                            <th>Borrowed</th>
                            <th>Due</th>
                            <th>Returned</th>
                            <th>Fine</th>
                        </tr>
                    </thead>
                    <tbody id="my-activity-tbody">
                        <tr><td colspan="6" class="text-center text-muted py-3">
                            <i class="fas fa-spinner fa-spin me-2"></i>Loading…
                        </td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ═══════ SECURITY ═══════ -->
        <div id="utab-security" class="ustab-pane" style="display:none;">
            <div class="ustab-section">Active Sessions</div>
            <div id="sessions-list" class="mb-4">
                <div class="text-muted" style="font-size:.83rem;">
                    <i class="fas fa-spinner fa-spin me-2"></i>Loading sessions…
                </div>
            </div>
            <button class="btn btn-outline-danger btn-sm mb-5" onclick="revokeAllSessions()">
                <i class="fas fa-right-from-bracket me-2"></i>Logout from All Other Devices
            </button>

            <div class="ustab-section">Account Information</div>
            <div class="row g-2">
                <div class="col-12 col-md-6">
                    <div class="p-3 rounded" style="border:1px solid var(--border-color);background:var(--card-bg,#fff);">
                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Account ID</div>
                        <div id="sec-user-id" class="fw-semibold mt-1" style="font-size:.9rem;">—</div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="p-3 rounded" style="border:1px solid var(--border-color);background:var(--card-bg,#fff);">
                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);">Member Since</div>
                        <div id="sec-joined" class="fw-semibold mt-1" style="font-size:.9rem;">—</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /ustab-content -->
</div>

<script>
// ─── Tab Navigation ───────────────────────────────────────────────────────────
let _acctTabLoaded = {};

function switchAcctTab(id) {
    document.querySelectorAll('.ustab-btn').forEach(b => b.classList.toggle('active', b.dataset.utab === id));
    document.querySelectorAll('.ustab-pane').forEach(p => { p.style.display = 'none'; });
    const pane = document.getElementById('utab-' + id);
    if (pane) pane.style.display = '';
    if (!_acctTabLoaded[id]) {
        _acctTabLoaded[id] = true;
        if (id === 'profile')       loadMyProfile();
        if (id === 'notifications') loadNotifPrefs();
        if (id === 'activity')      loadMyActivity();
        if (id === 'security')      loadMySessions();
    }
}

function acctToast(res) {
    const el = document.getElementById('myAcctAlert');
    if (el) {
        el.style.display = 'block';
        el.className = `alert alert-${res.success ? 'success' : 'danger'} mt-3 mb-0`;
        el.innerHTML = `<i class="fas ${res.success ? 'fa-circle-check' : 'fa-circle-xmark'} me-2"></i>${res.message || (res.success ? 'Saved.' : 'Failed.')}`;
        setTimeout(() => { el.style.display = 'none'; }, 4500);
    }
    if (typeof showToast === 'function') showToast(res.message || (res.success ? 'Saved.' : 'Failed.'), res.success ? 'success' : 'error');
}

// ─── Profile ─────────────────────────────────────────────────────────────────
async function loadMyProfile() {
    const res = await fetch('api/library_handler.php?action=user_profile_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!res.success) return;
    const p = res.data || {};
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    set('myp-name',    p.full_name);
    set('myp-contact', p.contact);
    set('myp-email',   p.username);
    set('myp-role',    (p.role || 'viewer').charAt(0).toUpperCase() + (p.role || 'viewer').slice(1));
    const uid  = document.getElementById('sec-user-id');
    const jnd  = document.getElementById('sec-joined');
    if (uid) uid.textContent = p.id ? '#' + p.id : '—';
    if (jnd) jnd.textContent = uaFmtDate(p.created_at);

    // Avatar state
    const prev = document.getElementById('myAvatarPreview');
    if (prev && p.avatar_url) { prev.src = p.avatar_url; setAvatarFit(prev, p.avatar_type); }
    setAvatarFit(document.getElementById('sidebar-avatar-img'), p.avatar_type);
    setAvatarFit(document.getElementById('topbar-avatar-img'), p.avatar_type);
    const rmBtn = document.getElementById('avatarRemoveBtn');
    if (rmBtn) rmBtn.style.display = p.has_photo ? '' : 'none';
    const hint = document.getElementById('avatarHint');
    if (hint) {
        if (p.has_photo && !p.can_upload_photo && p.photo_next_change) {
            hint.innerHTML = '<i class="fas fa-clock me-1"></i>You can change your photo again on <strong>' + uaFmtDate(p.photo_next_change) + '</strong>.';
        } else {
            hint.textContent = ' Photo changes are limited to once every 30 days.';
        }
    }
}

// ─── Avatar: upload (client-side resized), gallery select, remove ──────────────
function setAvatarFit(el, type) {
    if (!el || !type) return;
    el.classList.remove('av-fit-photo', 'av-fit-system', 'av-fit-initials');
    el.classList.add('av-fit-' + type);
}
function refreshAllAvatars(url, type) {
    const bust = url + (url.includes('?') ? '&' : '?') + '_=' + Date.now();
    ['myAvatarPreview', 'sidebar-avatar-img', 'topbar-avatar-img'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.src = bust; setAvatarFit(el, type); }
    });
}

function onAvatarFileChosen(e) {
    const file = e.target.files && e.target.files[0];
    e.target.value = ''; // allow re-picking the same file
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { acctToast({ success: false, message: 'Please choose a JPG, PNG, or WebP image.' }); return; }
    if (file.size > 10 * 1024 * 1024) { acctToast({ success: false, message: 'That image is too large (max 10 MB).' }); return; }

    const img = new Image();
    const url = URL.createObjectURL(file);
    img.onload = () => {
        URL.revokeObjectURL(url);
        // Center-crop to a square, downscale to 256×256, re-encode as JPEG
        const S = 256;
        const side = Math.min(img.width, img.height);
        const sx = (img.width - side) / 2, sy = (img.height - side) / 2;
        const canvas = document.createElement('canvas');
        canvas.width = S; canvas.height = S;
        const ctx = canvas.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(img, sx, sy, side, side, 0, 0, S, S);
        canvas.toBlob(blob => {
            if (!blob) { acctToast({ success: false, message: 'Could not process that image.' }); return; }
            uploadAvatarBlob(blob);
        }, 'image/jpeg', 0.85);
    };
    img.onerror = () => { URL.revokeObjectURL(url); acctToast({ success: false, message: 'That file is not a valid image.' }); };
    img.src = url;
}

async function uploadAvatarBlob(blob) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const fd = new FormData();
    fd.append('action', 'user_avatar_upload');
    fd.append('photo', blob, 'avatar.jpg');
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf }, body: fd,
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    if (res.success) { refreshAllAvatars(res.data.url, res.data.avatar_type); loadMyProfile(); }
}

let _avatarLib = [];
async function openAvatarGallery() {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('avatarGalleryModal')).show();
    if (!_avatarLib.length) {
        const res = await fetch('api/library_handler.php?action=avatar_library', { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        _avatarLib = res.success ? (res.data || []) : [];
    }
    renderAvatarGallery(_avatarLib[0]?.category || null);
}

function renderAvatarGallery(activeCat) {
    const tabs = document.getElementById('avatarCatTabs');
    const grid = document.getElementById('avatarGalleryGrid');
    if (!tabs || !grid) return;
    if (!_avatarLib.length) { grid.innerHTML = '<div class="text-muted text-center py-4" style="grid-column:1/-1;">No avatars available.</div>'; return; }

    tabs.innerHTML = _avatarLib.map(g => `
        <button class="btn btn-sm ${g.category === activeCat ? 'btn-primary' : 'btn-outline-secondary'}"
                onclick="renderAvatarGallery('${g.category}')" style="font-size:.76rem;">${uaEsc(g.label)}</button>`).join('');

    const group = _avatarLib.find(g => g.category === activeCat) || _avatarLib[0];
    grid.innerHTML = group.avatars.map(a => `
        <button type="button" onclick="selectSystemAvatar('${a.id.replace(/'/g, "\\'")}')"
                style="border:1px solid var(--border-color);background:var(--card-bg,#fff);border-radius:12px;padding:6px;cursor:pointer;aspect-ratio:1;"
                title="Select this avatar">
            <img src="${a.url}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:contain;border-radius:8px;">
        </button>`).join('');
}

async function selectSystemAvatar(avatarId) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'user_avatar_select', avatar_id: avatarId }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    if (res.success) {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('avatarGalleryModal')).hide();
        if (!res.data.photo_overrides) refreshAllAvatars(res.data.url, res.data.avatar_type);
        loadMyProfile();
    }
}

async function removeAvatarPhoto() {
    if (!confirm('Remove your uploaded photo? Your avatar or initials will be shown instead.')) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'user_avatar_remove' }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    if (res.success) { refreshAllAvatars(res.data.url, res.data.avatar_type); loadMyProfile(); }
}

async function saveProfile() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({
            action:    'user_profile_update',
            full_name: document.getElementById('myp-name').value,
            contact:   document.getElementById('myp-contact').value,
        }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    // Update sidebar display name if updated
    if (res.success && res.data?.full_name) {
        document.querySelectorAll('[data-user-display-name]').forEach(el => {
            el.textContent = res.data.full_name;
        });
    }
}

async function changePassword() {
    const cur = document.getElementById('pw-current').value;
    const nw  = document.getElementById('pw-new').value;
    const cfm = document.getElementById('pw-confirm').value;
    if (!cur || !nw) { acctToast({ success: false, message: 'Please fill in all password fields.' }); return; }
    if (nw !== cfm)  { acctToast({ success: false, message: 'New passwords do not match.' }); return; }
    if (nw.length < 8) { acctToast({ success: false, message: 'New password must be at least 8 characters.' }); return; }
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'user_password_change', current_password: cur, new_password: nw }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    if (res.success) {
        ['pw-current','pw-new','pw-confirm'].forEach(id => { document.getElementById(id).value = ''; });
    }
}

// ─── Notification Preferences ─────────────────────────────────────────────────
async function loadNotifPrefs() {
    const res = await fetch('api/library_handler.php?action=user_notif_prefs_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!res.success) return;
    const p = res.data || {};
    const cb = (id, val) => { const el = document.getElementById(id); if (el) el.checked = val == 1; };
    cb('unp-borrow_sms',    p.notify_borrow_sms ?? 1);
    cb('unp-overdue_sms',   p.notify_overdue_sms ?? 1);
    cb('unp-due_reminder',  p.notify_due_reminder ?? 1);
    cb('unp-announcements', p.notify_announcements ?? 1);
}

async function saveNotifPrefs() {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const v = id => document.getElementById(id)?.checked ? '1' : '0';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({
            action:               'user_notif_prefs_save',
            notify_borrow_sms:    v('unp-borrow_sms'),
            notify_overdue_sms:   v('unp-overdue_sms'),
            notify_due_reminder:  v('unp-due_reminder'),
            notify_announcements: v('unp-announcements'),
        }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
}

// ─── Activity ─────────────────────────────────────────────────────────────────
async function loadMyActivity() {
    const res = await fetch('api/library_handler.php?action=user_activity_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    const tbody = document.getElementById('my-activity-tbody');
    if (!tbody) return;
    const rows = res.success ? (res.data || []) : [];
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">No borrow history found.</td></tr>';
        return;
    }
    const sc = { pending:'warning', approved:'success', active:'success', returned:'secondary', rejected:'danger', cancelled:'secondary' };
    tbody.innerHTML = rows.map(r => {
        const st    = (r.status || 'pending').toLowerCase();
        const badge = `<span class="badge bg-${sc[st]||'secondary'}" style="font-size:.7rem;">${st}</span>`;
        const fine  = parseFloat(r.fine_amount || 0) > 0
            ? `<span class="text-danger fw-semibold">₱${parseFloat(r.fine_amount).toFixed(2)}</span>` : '—';
        return `<tr>
            <td class="fw-semibold" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${uaEsc(r.book_titles)}">
                ${uaEsc(r.book_titles || '—')}
            </td>
            <td>${badge}</td>
            <td>${uaFmtDate(r.borrowed_at || r.requested_at)}</td>
            <td>${uaFmtDate(r.due_at)}</td>
            <td>${uaFmtDate(r.returned_at)}</td>
            <td>${fine}</td>
        </tr>`;
    }).join('');
}

// ─── Sessions ─────────────────────────────────────────────────────────────────
async function loadMySessions() {
    const res = await fetch('api/library_handler.php?action=user_sessions_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    const container = document.getElementById('sessions-list');
    if (!container) return;
    const sessions = res.success ? (res.data || []) : [];
    if (!sessions.length) {
        container.innerHTML = '<div class="text-muted" style="font-size:.83rem;">No active sessions found.</div>';
        return;
    }
    container.innerHTML = sessions.map(s => `
        <div class="d-flex align-items-center gap-3 p-3 mb-2 rounded"
             style="border:1px solid var(--border-color);background:var(--card-bg,#fff);">
            <i class="fas fa-${s.is_current ? 'laptop-code' : 'desktop'} fa-lg"
               style="color:var(--${s.is_current ? 'success' : 'text-muted'});flex-shrink:0;"></i>
            <div style="flex:1;min-width:0;">
                <div class="fw-semibold" style="font-size:.83rem;">
                    ${s.is_current ? '<span class="badge bg-success me-2" style="font-size:.65rem;">This Device</span>' : ''}
                    ${uaEsc(s.device || 'Unknown Device')}
                </div>
                <div class="text-muted" style="font-size:.73rem;">
                    ${uaEsc(s.ip_address || 'Unknown IP')}
                    &middot; Last active ${uaFmtDate(s.last_active)}
                </div>
            </div>
        </div>
    `).join('');
}

async function revokeAllSessions() {
    if (!confirm('This will log you out from all other devices. Continue?')) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'user_sessions_revoke_all' }),
    }).then(r => r.json()).catch(() => ({ success: false }));
    acctToast(res);
    if (res.success) await loadMySessions();
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function uaEsc(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function uaFmtDate(s) {
    if (!s) return '—';
    const d = new Date(s);
    return isNaN(d) ? s : d.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}

// ─── Init ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    _acctTabLoaded['profile'] = true;
    loadMyProfile();
});
window.switchAcctTab    = switchAcctTab;
window.saveProfile      = saveProfile;
window.changePassword   = changePassword;
window.saveNotifPrefs   = saveNotifPrefs;
window.revokeAllSessions= revokeAllSessions;
window.onAvatarFileChosen = onAvatarFileChosen;
window.openAvatarGallery  = openAvatarGallery;
window.renderAvatarGallery= renderAvatarGallery;
window.selectSystemAvatar = selectSystemAvatar;
window.removeAvatarPhoto  = removeAvatarPhoto;
</script>
