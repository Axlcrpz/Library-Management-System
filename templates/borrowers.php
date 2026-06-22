<?php // templates/borrowers.php — Member/Borrower Management ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Members</h1>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-secondary btn-sm" onclick="loadBorrowers()">
            <i class="fas fa-rotate-right me-1"></i>Refresh
        </button>
    </div>
</div>

<!-- Summary Stats -->
<div class="row g-3 mb-4" id="borrower-stats-row">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Total Members</div>
                    <div class="stat-card-value" id="bwr-stat-total">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Active Borrowers</div>
                    <div class="stat-card-value" id="bwr-stat-active">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-hand-holding"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">With Overdue</div>
                    <div class="stat-card-value" id="bwr-stat-overdue">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-danger">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Total Fines</div>
                    <div class="stat-card-value" id="bwr-stat-fines">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-peso-sign"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <div class="input-group input-group-sm" style="width:240px;">
        <span class="input-group-text"><i class="fas fa-search" style="font-size:.7rem;"></i></span>
        <input type="text" id="bwrSearch" class="form-control" placeholder="Name, LRN, contact…" oninput="filterBorrowers()">
    </div>
    <select id="bwrTypeFilter" class="form-select form-select-sm" style="width:160px;" onchange="filterBorrowers()">
        <option value="">All Types</option>
        <option value="school">School</option>
        <option value="individual">Individual</option>
    </select>
    <select id="bwrClassFilter" class="form-select form-select-sm" style="width:170px;" onchange="filterBorrowers()">
        <option value="">All Classifications</option>
        <option value="child">Child</option>
        <option value="teen">Teen</option>
        <option value="individual">Individual</option>
        <option value="school">School</option>
        <option value="professional">Professional</option>
        <option value="private_institution">Private Institution</option>
        <option value="deped">DepEd / Gov't</option>
    </select>
    <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('bwrSearch').value='';document.getElementById('bwrTypeFilter').value='';document.getElementById('bwrClassFilter').value='';filterBorrowers()">
        <i class="fas fa-xmark me-1"></i>Clear
    </button>
</div>

<!-- Borrowers Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="font-size:.78rem;">Name</th>
                        <th style="font-size:.78rem;">LRN / Classification</th>
                        <th style="font-size:.78rem;">Contact</th>
                        <th style="font-size:.78rem;text-align:center;">Borrows</th>
                        <th style="font-size:.78rem;text-align:center;">Active</th>
                        <th style="font-size:.78rem;text-align:center;">Overdue</th>
                        <th style="font-size:.78rem;text-align:right;">Fines</th>
                        <th style="font-size:.78rem;width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody id="bwrTableBody">
                    <tr><td colspan="8" class="text-center text-muted py-5" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading members…
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Borrower Profile Panel -->
<div id="bwrProfilePanel" class="card mt-3" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center" style="font-size:.85rem;font-weight:600;">
        <span><i class="fas fa-user me-2" style="color:var(--primary);"></i><span id="bwrProfileName">—</span></span>
        <button class="btn btn-sm btn-outline-secondary" onclick="closeBorrowerProfile()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <!-- Left: Info -->
            <div class="col-md-4">
                <div style="text-align:center;margin-bottom:16px;">
                    <div id="bwrProfileImg" style="width:80px;height:80px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--primary);margin:0 auto 10px;overflow:hidden;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div id="bwrProfileNameBig" style="font-weight:700;font-size:.92rem;">—</div>
                    <div id="bwrProfileBadge" style="margin-top:4px;"></div>
                </div>
                <table class="table table-sm mb-0" style="font-size:.78rem;">
                    <tbody id="bwrInfoTable"></tbody>
                </table>
            </div>
            <!-- Right: History -->
            <div class="col-md-8">
                <div style="font-weight:700;font-size:.82rem;margin-bottom:10px;">
                    <i class="fas fa-clock-rotate-left me-2" style="color:var(--primary);"></i>Borrow History
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" style="font-size:.77rem;">
                        <thead>
                            <tr>
                                <th>Books</th>
                                <th>Borrowed</th>
                                <th>Due</th>
                                <th>Returned</th>
                                <th>Status</th>
                                <th style="text-align:right;">Fine</th>
                            </tr>
                        </thead>
                        <tbody id="bwrHistoryBody">
                            <tr><td colspan="6" class="text-center text-muted">No history yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let allBorrowers = [];

async function loadBorrowers() {
    const body = await fetch('api/library_handler.php?action=borrowers_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    allBorrowers = body.data || [];
    updateBorrowerStats();
    renderBorrowerTable(allBorrowers);
}

function updateBorrowerStats() {
    const total   = allBorrowers.length;
    const active  = allBorrowers.filter(b => Number(b.active_borrows) > 0).length;
    const overdue = allBorrowers.filter(b => Number(b.overdue_count)  > 0).length;
    const fines   = allBorrowers.reduce((s, b) => s + parseFloat(b.total_fines || 0), 0);
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    set('bwr-stat-total',   total);
    set('bwr-stat-active',  active);
    set('bwr-stat-overdue', overdue);
    set('bwr-stat-fines',   'PHP ' + fines.toFixed(2));
}

function filterBorrowers() {
    const q    = (document.getElementById('bwrSearch')?.value || '').toLowerCase().trim();
    const type = document.getElementById('bwrTypeFilter')?.value || '';
    const cls  = document.getElementById('bwrClassFilter')?.value || '';

    let filtered = [...allBorrowers];
    if (q) filtered = filtered.filter(b =>
        [b.name, b.lrn, b.contact, b.email].some(v => String(v||'').toLowerCase().includes(q)));
    if (type) filtered = filtered.filter(b => b.borrower_type === type);
    if (cls)  filtered = filtered.filter(b => b.classification === cls);
    renderBorrowerTable(filtered);
}

function renderBorrowerTable(rows) {
    const tbody = document.getElementById('bwrTableBody');
    if (!tbody) return;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtDate = v => { if (!v) return '—'; const d = new Date(String(v).replace(' ','T')); return isNaN(d)?v:d.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}); };

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-users me-2"></i>No members found.</td></tr>';
        return;
    }

    const clsMap = {child:'Child',teen:'Teen',individual:'Individual',school:'School',professional:'Professional',private_institution:'Private Institution',deped:'DepEd / Gov\'t'};
    tbody.innerHTML = rows.map(b => {
        const overdue = Number(b.overdue_count) > 0;
        const lrnBadge = b.lrn ? `<div style="font-size:.68rem;color:var(--primary);font-weight:600;">LRN: ${esc(b.lrn)}</div>` : '';
        const clsLabel = clsMap[b.classification] || esc(b.classification || '—');
        return `<tr>
            <td>
                <div style="font-weight:600;font-size:.82rem;">${esc(b.name)}</div>
                <div style="font-size:.7rem;color:var(--text-muted);">${esc(b.email || '')}</div>
            </td>
            <td>
                ${lrnBadge}
                <span style="background:var(--info-light);color:var(--info);padding:1px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">${esc(clsLabel)}</span>
            </td>
            <td style="font-size:.78rem;">${esc(b.contact || '—')}</td>
            <td style="text-align:center;font-size:.82rem;font-weight:600;">${esc(b.total_borrows || 0)}</td>
            <td style="text-align:center;">
                ${Number(b.active_borrows) > 0
                    ? `<span style="background:var(--success-light);color:var(--success);padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">${esc(b.active_borrows)}</span>`
                    : '<span style="color:var(--text-muted);font-size:.75rem;">0</span>'}
            </td>
            <td style="text-align:center;">
                ${overdue
                    ? `<span style="background:var(--danger-light);color:var(--danger);padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">${esc(b.overdue_count)} overdue</span>`
                    : '<span style="color:var(--text-muted);font-size:.75rem;">—</span>'}
            </td>
            <td style="text-align:right;font-size:.78rem;font-weight:600;${parseFloat(b.total_fines||0)>0?'color:var(--danger);':'color:var(--text-muted);'}">
                ${parseFloat(b.total_fines||0)>0 ? 'PHP '+parseFloat(b.total_fines).toFixed(2) : '—'}
            </td>
            <td>
                <button class="btn btn-sm btn-outline-primary" style="font-size:.7rem;padding:3px 9px;"
                        onclick="viewBorrowerProfile(${b.id})">
                    <i class="fas fa-eye me-1"></i>View
                </button>
            </td>
        </tr>`;
    }).join('');
}

async function viewBorrowerProfile(id) {
    const panel = document.getElementById('bwrProfilePanel');
    if (!panel) return;
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    document.getElementById('bwrProfileName').textContent = 'Loading…';
    document.getElementById('bwrHistoryBody').innerHTML =
        '<tr><td colspan="6" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</td></tr>';

    const body = await fetch(`api/library_handler.php?action=borrower_profile&id=${id}`, { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;

    const { borrower, history } = body.data;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtDT = v => { if (!v) return '—'; const d = new Date(String(v).replace(' ','T')); return isNaN(d)?v:d.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'}); };

    document.getElementById('bwrProfileName').textContent = borrower.name || '—';
    document.getElementById('bwrProfileNameBig').textContent = borrower.name || '—';

    const clsMap = {child:'Child',teen:'Teen',individual:'Individual',school:'School',professional:'Professional',private_institution:'Private Institution',deped:'DepEd/Gov\'t'};
    const badge = clsMap[borrower.classification] || borrower.classification || '';
    document.getElementById('bwrProfileBadge').innerHTML = badge
        ? `<span style="background:var(--primary-light);color:var(--primary);padding:2px 10px;border-radius:99px;font-size:.7rem;font-weight:600;">${esc(badge)}</span>`
        : '';

    // ── Avatar ──────────────────────────────────────────────────────────────
    // A member links to a `users` account whose avatar is served by the user_avatar
    // endpoint (uploaded photo → system avatar → initials SVG; it never returns
    // blank). Walk-in borrowers (no user_id) and any load failure fall back to an
    // initials circle. Reset first so a previously-viewed member's avatar can't linger.
    const imgBox = document.getElementById('bwrProfileImg');
    const initials = (borrower.name || '?').trim().split(/\s+/).map(w => w[0] || '').slice(0, 2).join('').toUpperCase() || '?';
    const showInitials = () => { imgBox.innerHTML = `<span>${esc(initials)}</span>`; };
    const avatarSrc = borrower.user_id
        ? `api/library_handler.php?action=user_avatar&id=${encodeURIComponent(borrower.user_id)}`
        : (borrower.profile_image || '');
    console.debug('[member-avatar]', { id: borrower.id, user_id: borrower.user_id ?? null, src: avatarSrc || '(initials)' });
    showInitials();                                   // baseline — never blank, even while the image loads
    if (avatarSrc) {
        const img = new Image();
        img.alt = borrower.name || '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        img.onload  = () => { imgBox.innerHTML = ''; imgBox.appendChild(img); };
        img.onerror = showInitials;                  // keep initials if the avatar can't load
        img.src = avatarSrc;
    }

    const infoRows = [
        ['LRN',     borrower.lrn],
        ['School',  borrower.school_name],
        ['Grade',   borrower.grade_level],
        ['Contact', borrower.contact],
        ['Email',   borrower.email],
        ['Address', borrower.address],
        ['Type',    borrower.borrower_type],
        ['Since',   fmtDT(borrower.created_at)],
    ];
    document.getElementById('bwrInfoTable').innerHTML = infoRows
        .filter(([_, v]) => v)
        .map(([k, v]) => `<tr><th style="font-size:.72rem;color:var(--text-muted);font-weight:600;width:80px;">${esc(k)}</th><td style="font-size:.78rem;">${esc(v)}</td></tr>`)
        .join('');

    const statusBadge = s => {
        const map = {pending:'badge-pending',borrowed:'badge-borrowed',returned:'badge-returned',rejected:'badge-rejected',cancelled:'badge-cancelled'};
        return `<span class="badge ${map[s]||'badge-cancelled'}">${esc(s||'—')}</span>`;
    };

    document.getElementById('bwrHistoryBody').innerHTML = history.length
        ? history.map(h => `<tr>
            <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(h.books_list||'—')}</td>
            <td>${fmtDT(h.borrowed_at||h.requested_at)}</td>
            <td style="${h.status==='borrowed'&&h.due_at&&new Date(h.due_at)<new Date()?'color:var(--danger);font-weight:700;':''}">${fmtDT(h.due_at)}</td>
            <td>${fmtDT(h.returned_at)}</td>
            <td>${statusBadge(h.status)}</td>
            <td style="text-align:right;${parseFloat(h.fine_amount||0)>0?'color:var(--danger);font-weight:700;':'color:var(--text-muted);'}">
                ${parseFloat(h.fine_amount||0)>0?'PHP '+parseFloat(h.fine_amount).toFixed(2):'—'}
            </td>
        </tr>`).join('')
        : '<tr><td colspan="6" class="text-center text-muted py-3" style="font-size:.8rem;">No borrow history found.</td></tr>';
}

function closeBorrowerProfile() {
    const panel = document.getElementById('bwrProfilePanel');
    if (panel) panel.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', loadBorrowers);
window.loadBorrowers    = loadBorrowers;
window.filterBorrowers  = filterBorrowers;
window.viewBorrowerProfile = viewBorrowerProfile;
window.closeBorrowerProfile = closeBorrowerProfile;
</script>
