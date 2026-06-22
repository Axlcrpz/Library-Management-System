<?php
// templates/reservations.php — Reservation v2: date-ranged, quantity-based booking
// Calendar heatmap + waitlist; availability derived per day server-side.
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reservations</h1>
        <p class="text-muted mb-0" style="font-size:.8rem;">
            <?php if ($isStaff): ?>
            Manage reservations, the waiting-list queue, and pickups.
            <?php else: ?>
            Reserve copies for future dates or join the waiting list. Want a book right now?
            <a href="#" onclick="switchTabById('borrowing');return false;">Borrow it instead</a>.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-secondary btn-sm" onclick="resvRefreshAll()">
            <i class="fas fa-rotate-right me-1"></i> Refresh
        </button>
    </div>
</div>

<style>
.rcal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.rcal-dow  { text-align:center; font-size:.66rem; font-weight:700; text-transform:uppercase;
             letter-spacing:.05em; color:var(--text-muted); padding:4px 0; }
.rcal-cell { border-radius:9px; padding:6px 4px 5px; text-align:center; cursor:pointer; min-height:52px;
             border:1.5px solid transparent; transition:transform .1s, border-color .1s; user-select:none; }
.rcal-cell:hover { transform:scale(1.04); border-color:var(--primary); }
.rcal-day  { font-size:.72rem; font-weight:600; color:var(--text); line-height:1.1; }
.rcal-avail{ font-size:.86rem; font-weight:800; line-height:1.2; }
.rcal-sub  { font-size:.58rem; color:var(--text-muted); }
.rcal-free { background:#dcfce7; } .rcal-free .rcal-avail { color:#15803d; }
.rcal-low  { background:#fef3c7; } .rcal-low  .rcal-avail { color:#b45309; }
.rcal-full { background:#fee2e2; } .rcal-full .rcal-avail { color:#b91c1c; }
.rcal-past { background:var(--surface-hover,#f3f4f6); opacity:.45; cursor:default; }
.rcal-past:hover { transform:none; border-color:transparent; }
.rcal-out  { visibility:hidden; }
.rcal-sel  { border-color:var(--primary) !important; box-shadow:0 0 0 2px rgba(99,102,241,.25); }
html[data-theme="dark"] .rcal-free { background:rgba(34,197,94,.16); }
html[data-theme="dark"] .rcal-low  { background:rgba(245,158,11,.16); }
html[data-theme="dark"] .rcal-full { background:rgba(239,68,68,.16); }
.resv-chip { display:inline-block; padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
</style>

<?php if (!$isStaff): ?>
<div class="row g-3">
    <!-- ── Calendar (patron self-service only) ───────────────────── -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <i class="fas fa-calendar-days" style="color:var(--primary);"></i>
                <span style="font-weight:600;font-size:.85rem;">Availability Calendar</span>
                <select id="resv-book-select" class="form-select form-select-sm" style="max-width:280px;margin-left:auto;"
                        onchange="resvBookChanged()">
                    <option value="">— Loading books… —</option>
                </select>
            </div>
            <div class="card-body">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <button class="btn btn-sm btn-outline-secondary" onclick="resvMonthNav(-1)"><i class="fas fa-chevron-left"></i></button>
                    <div id="resv-month-label" style="font-weight:700;font-size:.9rem;">—</div>
                    <button class="btn btn-sm btn-outline-secondary" onclick="resvMonthNav(1)"><i class="fas fa-chevron-right"></i></button>
                </div>
                <div id="resv-stock-line" style="font-size:.76rem;color:var(--text-muted);margin-bottom:8px;text-align:center;"></div>
                <div class="rcal-grid" id="resv-cal-dow"></div>
                <div class="rcal-grid" id="resv-cal-grid" style="margin-top:4px;">
                    <div class="text-muted py-4" style="grid-column:1/-1;text-align:center;font-size:.8rem;">
                        Select a book to view its calendar.
                    </div>
                </div>
                <div style="display:flex;gap:14px;margin-top:10px;font-size:.7rem;color:var(--text-muted);flex-wrap:wrap;">
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:#dcfce7;margin-right:4px;"></span>Available</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:#fef3c7;margin-right:4px;"></span>Limited (≤30%)</span>
                    <span><span style="display:inline-block;width:11px;height:11px;border-radius:3px;background:#fee2e2;margin-right:4px;"></span>Fully booked</span>
                    <span style="margin-left:auto;">Number = copies still available that day</span>
                </div>
                <div id="resv-day-detail" style="display:none;margin-top:12px;background:var(--surface-hover,#f8fafc);border-radius:10px;padding:10px 14px;font-size:.8rem;"></div>
            </div>
        </div>
    </div>

    <!-- ── Reserve panel ────────────────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-bookmark" style="color:var(--primary);"></i>
                <span style="font-weight:600;font-size:.85rem;">Reserve Copies</span>
            </div>
            <div class="card-body">
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label fw-semibold small mb-1">Start date</label>
                        <input type="date" id="resv-start" class="form-control form-control-sm" onchange="resvRangeChanged()">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold small mb-1">End date (inclusive)</label>
                        <input type="date" id="resv-end" class="form-control form-control-sm" onchange="resvRangeChanged()">
                    </div>
                </div>
                <label class="form-label fw-semibold small mb-1">Quantity</label>
                <input type="number" id="resv-qty" class="form-control form-control-sm" min="1" value="1" style="max-width:140px;">
                <div id="resv-max-banner" style="margin-top:10px;font-size:.78rem;color:var(--text-muted);">
                    Pick a book and dates to see how many copies you can reserve.
                </div>
                <button class="btn btn-primary btn-sm mt-3" id="resv-submit-btn" onclick="resvSubmit()">
                    <i class="fas fa-bookmark me-1"></i> Reserve
                </button>
                <div id="resv-waitlist-prompt" style="display:none;margin-top:12px;background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:12px;">
                    <div style="font-size:.8rem;color:#9a3412;margin-bottom:8px;" id="resv-waitlist-msg"></div>
                    <button class="btn btn-warning btn-sm" onclick="resvJoinWaitlist()">
                        <i class="fas fa-user-clock me-1"></i> Join Waiting List
                    </button>
                    <label style="font-size:.74rem;margin-left:10px;cursor:pointer;">
                        <input type="checkbox" id="resv-allow-partial" checked> Accept partial quantity
                    </label>
                </div>
            </div>
        </div>

        <!-- Offers -->
        <div class="card mt-3" id="resv-offers-card" style="display:none;">
            <div class="card-header">
                <i class="fas fa-gift" style="color:var(--success);"></i>
                <span style="font-weight:600;font-size:.85rem;">Offers for You</span>
            </div>
            <div class="card-body" id="resv-offers-body"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$isStaff): ?>
<!-- ── My reservations & waitlist (patron self-service only) ─────── -->
<div class="card mt-3">
    <div class="card-header">
        <i class="fas fa-list-check" style="color:var(--primary);"></i>
        <span style="font-weight:600;font-size:.85rem;">My Reservations &amp; Waiting List</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.8rem;">
                <thead><tr>
                    <th>Book</th><th style="text-align:center;">Qty</th><th>From</th><th>To</th>
                    <th>Status</th><th>Queue #</th><th>Actions</th>
                </tr></thead>
                <tbody id="resv-mine-body">
                    <tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isStaff): ?>
<!-- ── Staff: all reservations + queue ─────────────────────────── -->
<div class="card mt-3">
    <div class="card-header">
        <i class="fas fa-clipboard-list" style="color:var(--warning);"></i>
        <span style="font-weight:600;font-size:.85rem;">All Reservations (Staff)</span>
        <select id="resv-staff-filter" class="form-select form-select-sm" style="width:auto;margin-left:auto;" onchange="resvRenderStaff()">
            <option value="active">Active (pending/confirmed/ready)</option>
            <option value="fulfilled">Fulfilled / Completed</option>
            <option value="closed">Cancelled / Expired / No-show</option>
            <option value="all">All</option>
        </select>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.8rem;">
                <thead><tr>
                    <th>#</th><th>Book</th><th>User</th><th style="text-align:center;">Qty</th>
                    <th>From</th><th>To</th><th>Status</th><th>Pickup By</th><th>Actions</th>
                </tr></thead>
                <tbody id="resv-staff-body">
                    <tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header">
        <i class="fas fa-user-clock" style="color:var(--info);"></i>
        <span style="font-weight:600;font-size:.85rem;">Waiting List Queue (Staff)</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.8rem;">
                <thead><tr>
                    <th>Queue #</th><th>Book</th><th>User</th><th style="text-align:center;">Qty</th>
                    <th>Preferred Dates</th><th>Status</th><th>Offer Expires</th><th>Joined</th><th></th>
                </tr></thead>
                <tbody id="resv-staff-wl-body">
                    <tr><td colspan="9" class="text-center text-muted py-4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* ── Reservation v2 module ──────────────────────────────────────── */
let _resvBooks = [];
let _resvMonth = null;            // Date anchored at the 1st
let _resvCalDays = {};            // 'Y-m-d' → {total,reserved,borrowed,available}
let _resvData = { reservations: [], waitlist: [] };
let _resvSelectedDay = null;

function resvCsrf() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
function resvEsc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function resvFmtD(d) {
    if (!d) return '—';
    const dt = new Date(String(d).slice(0, 10) + 'T00:00:00');
    return isNaN(dt) ? d : dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
}
function resvIso(d) { return d.toISOString().slice(0, 10); }

/* ── Boot / refresh ─────────────────────────────────────────────── */
function resvRefreshAll() {
    if (document.getElementById('resv-book-select')) resvLoadBooks();  // calendar is patron-only
    resvLoadMine();
}
window.loadReservations = resvRefreshAll;   // app.js tab hook compatibility

function resvLoadBooks() {
    fetch('api/library_handler.php?action=books_get', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            _resvBooks = res.data || [];
            const sel = document.getElementById('resv-book-select');
            const prev = sel.value;
            sel.innerHTML = '<option value="">— Select a book —</option>' +
                _resvBooks.map(b => `<option value="${b.id}">${resvEsc(b.title)}</option>`).join('');
            if (prev && _resvBooks.some(b => String(b.id) === prev)) { sel.value = prev; }
            if (sel.value) resvLoadCalendar();
        })
        .catch(() => showToast('Failed to load books', 'error'));
}

function resvBookChanged() {
    _resvSelectedDay = null;
    document.getElementById('resv-day-detail').style.display = 'none';
    if (!_resvMonth) { _resvMonth = new Date(); _resvMonth.setDate(1); }
    resvLoadCalendar();
    resvRangeChanged();
}

function resvMonthNav(dir) {
    if (!_resvMonth) { _resvMonth = new Date(); _resvMonth.setDate(1); }
    _resvMonth.setMonth(_resvMonth.getMonth() + dir);
    resvLoadCalendar();
}

/* ── Calendar ───────────────────────────────────────────────────── */
function resvLoadCalendar() {
    const selEl = document.getElementById('resv-book-select');
    if (!selEl) return;   // calendar is patron-only
    const bookId = selEl.value;
    if (!bookId) return;
    if (!_resvMonth) { _resvMonth = new Date(); _resvMonth.setDate(1); }

    const y = _resvMonth.getFullYear();
    const m = String(_resvMonth.getMonth() + 1).padStart(2, '0');
    document.getElementById('resv-month-label').textContent =
        _resvMonth.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });

    fetch(`api/library_handler.php?action=reservation_calendar&book_id=${bookId}&month=${y}-${m}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { showToast(res.message || 'Calendar error', 'error'); return; }
            _resvCalDays = res.data.days || {};
            const bk = res.data.book || {};
            document.getElementById('resv-stock-line').innerHTML =
                `<strong>${resvEsc(bk.title || '')}</strong> — usable stock: <strong>${bk.usable ?? '—'}</strong> · on shelf right now: <strong>${bk.on_shelf_now ?? '—'}</strong>`;
            resvRenderCalendar();
        })
        .catch(() => showToast('Network error loading calendar', 'error'));
}

function resvRenderCalendar() {
    const dow = document.getElementById('resv-cal-dow');
    dow.innerHTML = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(d => `<div class="rcal-dow">${d}</div>`).join('');

    const grid = document.getElementById('resv-cal-grid');
    const y = _resvMonth.getFullYear(), m = _resvMonth.getMonth();
    const firstDow = new Date(y, m, 1).getDay();
    const daysIn = new Date(y, m + 1, 0).getDate();
    const todayIso = resvIso(new Date(Date.now() - new Date().getTimezoneOffset() * 60000));

    let cells = '';
    for (let i = 0; i < firstDow; i++) cells += '<div class="rcal-cell rcal-out"></div>';
    for (let d = 1; d <= daysIn; d++) {
        const iso = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const info = _resvCalDays[iso];
        const past = iso < todayIso;
        let cls = 'rcal-past', availTxt = '·', sub = '';
        if (info && !past) {
            const ratio = info.total > 0 ? info.available / info.total : 0;
            cls = info.available <= 0 ? 'rcal-full' : (ratio <= 0.3 ? 'rcal-low' : 'rcal-free');
            availTxt = info.available;
            sub = info.available <= 0 ? 'FULL' : 'avail';
        } else if (info && past) {
            availTxt = info.available;
        }
        const selCls = (_resvSelectedDay === iso) ? ' rcal-sel' : '';
        cells += `<div class="rcal-cell ${cls}${selCls}" ${past ? '' : `onclick="resvDayClick('${iso}')"`}>
            <div class="rcal-day">${d}</div>
            <div class="rcal-avail">${availTxt}</div>
            <div class="rcal-sub">${sub}</div>
        </div>`;
    }
    grid.innerHTML = cells;
}

function resvDayClick(iso) {
    _resvSelectedDay = iso;
    const info = _resvCalDays[iso];
    const panel = document.getElementById('resv-day-detail');
    if (info) {
        panel.style.display = '';
        panel.innerHTML =
            `<strong>${resvFmtD(iso)}</strong><div class="row mt-1" style="text-align:center;">
                <div class="col-3"><div style="font-weight:800;">${info.total}</div><div style="font-size:.68rem;color:var(--text-muted);">Total usable</div></div>
                <div class="col-3"><div style="font-weight:800;color:#b45309;">${info.reserved}</div><div style="font-size:.68rem;color:var(--text-muted);">Reserved</div></div>
                <div class="col-3"><div style="font-weight:800;color:#6366f1;">${info.borrowed}</div><div style="font-size:.68rem;color:var(--text-muted);">Borrowed out</div></div>
                <div class="col-3"><div style="font-weight:800;color:${info.available > 0 ? '#15803d' : '#b91c1c'};">${info.available}</div><div style="font-size:.68rem;color:var(--text-muted);">Available</div></div>
            </div>`;
    }
    // Calendar click drives the reserve form: first click = start, second = end
    const s = document.getElementById('resv-start'), e = document.getElementById('resv-end');
    if (s && e) {
        if (!s.value || (s.value && e.value) || iso < s.value) { s.value = iso; e.value = ''; }
        else { e.value = iso; }
        resvRangeChanged();
    }
    resvRenderCalendar();
}

/* ── Live feasibility probe ─────────────────────────────────────── */
function resvRangeChanged() {
    const startEl = document.getElementById('resv-start');
    if (!startEl) return;   // staff view has no reserve form
    const bookId = document.getElementById('resv-book-select').value;
    const s = startEl.value;
    const e = document.getElementById('resv-end').value;
    const banner = document.getElementById('resv-max-banner');
    document.getElementById('resv-waitlist-prompt').style.display = 'none';
    if (!bookId || !s || !e) {
        banner.innerHTML = 'Pick a book and dates to see how many copies you can reserve.';
        return;
    }
    banner.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Checking availability…';
    fetch(`api/library_handler.php?action=reservation_calendar&book_id=${bookId}&from=${s}&to=${e}&start=${s}&end=${e}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { banner.textContent = res.message || 'Could not check availability.'; return; }
            const max = res.data.range?.max_available ?? 0;
            banner.innerHTML = max > 0
                ? `<i class="fas fa-circle-check me-1" style="color:#16a34a;"></i>Up to <strong>${max}</strong> cop(ies) can be reserved for ${resvFmtD(s)} – ${resvFmtD(e)}. Copies are free again the day after the end date.`
                : `<i class="fas fa-circle-xmark me-1" style="color:#dc2626;"></i><strong>Fully booked</strong> for ${resvFmtD(s)} – ${resvFmtD(e)}. You can join the waiting list below.`;
            const qtyEl = document.getElementById('resv-qty');
            if (max > 0) qtyEl.max = max;
            if (max <= 0) resvShowWaitlistPrompt('No copies are available for those dates.');
        })
        .catch(() => { banner.textContent = 'Network error.'; });
}

/* ── Reserve / waitlist actions ─────────────────────────────────── */
function resvSubmit() {
    const bookId = parseInt(document.getElementById('resv-book-select').value);
    const qty = parseInt(document.getElementById('resv-qty').value);
    const s = document.getElementById('resv-start').value;
    const e = document.getElementById('resv-end').value;
    if (!bookId) { showToast('Select a book first.', 'error'); return; }
    if (!s || !e) { showToast('Pick start and end dates.', 'error'); return; }
    if (!qty || qty < 1) { showToast('Quantity must be at least 1.', 'error'); return; }

    const btn = document.getElementById('resv-submit-btn');
    btn.disabled = true;
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'create_reservation', book_id: bookId, quantity: qty, start_date: s, end_date: e }),
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showToast(res.message || 'Reservation confirmed!', 'success');
            document.getElementById('resv-waitlist-prompt').style.display = 'none';
            resvLoadCalendar(); resvLoadMine(); resvRangeChanged();
        } else if (res.code === 'INSUFFICIENT') {
            resvShowWaitlistPrompt(res.message + ' Join the waiting list and you will be notified automatically when copies free up.');
        } else {
            showToast(res.message || 'Reservation failed.', 'error');
        }
    })
    .catch(() => showToast('Network error.', 'error'))
    .finally(() => { btn.disabled = false; });
}

function resvShowWaitlistPrompt(msg) {
    document.getElementById('resv-waitlist-msg').textContent = msg;
    document.getElementById('resv-waitlist-prompt').style.display = '';
}

function resvJoinWaitlist() {
    const bookId = parseInt(document.getElementById('resv-book-select').value);
    const qty = parseInt(document.getElementById('resv-qty').value) || 1;
    const s = document.getElementById('resv-start').value;
    const e = document.getElementById('resv-end').value;
    const partial = document.getElementById('resv-allow-partial').checked ? 1 : 0;

    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'waitlist_join', book_id: bookId, quantity: qty,
                               preferred_start: s, preferred_end: e, allow_partial: partial }),
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Added to waiting list.' : 'Failed.'), res.success ? 'success' : 'error');
        if (res.success) { document.getElementById('resv-waitlist-prompt').style.display = 'none'; resvLoadMine(); }
    })
    .catch(() => showToast('Network error.', 'error'));
}

/* ── My reservations / offers / staff boards ────────────────────── */
const RESV_CHIP = {
    pending:   ['#fef9c3', '#854d0e', 'Pending'],
    confirmed: ['#dbeafe', '#1d4ed8', 'Confirmed'],
    ready:     ['#dcfce7', '#166534', 'Ready for Pickup'],
    fulfilled: ['#ede9fe', '#4c1d95', 'Picked Up'],
    completed: ['#f0fdf4', '#15803d', 'Completed'],
    cancelled: ['#f3f4f6', '#374151', 'Cancelled'],
    expired:   ['#fee2e2', '#991b1b', 'Expired'],
    no_show:   ['#fee2e2', '#991b1b', 'No-show'],
    waiting:   ['#fef9c3', '#854d0e', 'Waiting'],
    offered:   ['#dcfce7', '#166534', 'Offer Available!'],
    converted: ['#ede9fe', '#4c1d95', 'Converted'],
    declined:  ['#f3f4f6', '#374151', 'Declined'],
};
function resvChip(st) {
    const c = RESV_CHIP[st] || ['#f3f4f6', '#374151', st];
    return `<span class="resv-chip" style="background:${c[0]};color:${c[1]};">${c[2]}</span>`;
}

function resvLoadMine() {
    fetch('api/library_handler.php?action=get_reservations', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            _resvData = res.data || { reservations: [], waitlist: [] };
            resvRenderMine();
            resvRenderOffers();
            if (window.isStaff) { resvRenderStaff(); resvRenderStaffWl(); }
        });
}

function resvRenderMine() {
    const tbody = document.getElementById('resv-mine-body');
    if (!tbody) return;   // staff view hides the personal reservations table
    const mineRes = window.isStaff
        ? _resvData.reservations.filter(r => String(r.user_id) === String(window.currentUser?.id))
        : _resvData.reservations;
    const mineWl = window.isStaff
        ? (_resvData.waitlist || []).filter(w => String(w.user_id) === String(window.currentUser?.id))
        : (_resvData.waitlist || []);

    const rows = [
        ...mineRes.map(r => `<tr>
            <td style="font-weight:500;">${resvEsc(r.book_title)}</td>
            <td style="text-align:center;font-weight:700;">${r.quantity}</td>
            <td>${resvFmtD(r.start_date)}</td>
            <td>${resvFmtD(r.end_date)}</td>
            <td>${resvChip(r.status)}</td>
            <td>—</td>
            <td>${['pending','confirmed','ready'].includes(r.status)
                ? `<button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.7rem;" onclick="resvCancel(${r.id})"><i class="fas fa-times me-1"></i>Cancel</button>` : ''}</td>
        </tr>`),
        ...mineWl.filter(w => ['waiting', 'offered'].includes(w.status)).map(w => `<tr>
            <td style="font-weight:500;">${resvEsc(w.book_title)} <span class="text-muted" style="font-size:.7rem;">(waiting list)</span></td>
            <td style="text-align:center;font-weight:700;">${w.quantity}</td>
            <td>${resvFmtD(w.preferred_start)}</td>
            <td>${resvFmtD(w.preferred_end)}</td>
            <td>${resvChip(w.status)}</td>
            <td>${w.queue_position ? '#' + w.queue_position : '—'}</td>
            <td><button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.7rem;" onclick="resvCancelWl(${w.id})"><i class="fas fa-times me-1"></i>Leave</button></td>
        </tr>`),
    ];
    tbody.innerHTML = rows.length ? rows.join('')
        : '<tr><td colspan="7" class="text-center text-muted py-4" style="font-size:.8rem;">No reservations yet — pick a book on the calendar to get started.</td></tr>';
}

function resvRenderOffers() {
    const offers = (_resvData.waitlist || []).filter(w =>
        w.status === 'offered' && (!window.isStaff || String(w.user_id) === String(window.currentUser?.id)));
    const card = document.getElementById('resv-offers-card');
    if (!card) return;   // staff view has no personal offers card
    if (!offers.length) { card.style.display = 'none'; return; }
    card.style.display = '';
    document.getElementById('resv-offers-body').innerHTML = offers.map(w => `
        <div style="border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;padding:12px;margin-bottom:8px;">
            <div style="font-size:.82rem;font-weight:600;color:#166534;">
                <i class="fas fa-gift me-1"></i>${w.offer_qty} cop(ies) of "${resvEsc(w.book_title)}" are now available for you!
            </div>
            <div style="font-size:.72rem;color:#15803d;margin:4px 0 8px;">Confirm before ${resvEsc(w.offer_expires_at || '')} or the offer passes to the next in line.</div>
            <button class="btn btn-success btn-sm" onclick="resvRespond(${w.id},'accept')"><i class="fas fa-check me-1"></i>Accept</button>
            <button class="btn btn-outline-secondary btn-sm ms-2" onclick="resvRespond(${w.id},'decline')">Decline</button>
        </div>`).join('');
}

function resvRespond(id, response) {
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'waitlist_respond', id, response }),
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Done.' : 'Failed.'), res.success ? 'success' : 'error');
        resvLoadMine(); resvLoadCalendar();
    });
}

function resvCancel(id) {
    if (!confirm('Cancel this reservation? The copies will be released to the waiting list.')) return;
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'cancel_reservation', id }),
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Cancelled.' : 'Failed.'), res.success ? 'success' : 'error');
        resvLoadMine(); resvLoadCalendar(); resvRangeChanged();
    });
}

function resvCancelWl(id) {
    if (!confirm('Leave the waiting list for this book?')) return;
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'cancel_reservation', waitlist_id: id }),
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Removed.' : 'Failed.'), res.success ? 'success' : 'error');
        resvLoadMine();
    });
}

function resvRenderStaff() {
    const tbody = document.getElementById('resv-staff-body');
    if (!tbody) return;
    const filter = document.getElementById('resv-staff-filter')?.value || 'active';
    const groups = {
        active:    ['pending', 'confirmed', 'ready'],
        fulfilled: ['fulfilled', 'completed'],
        closed:    ['cancelled', 'expired', 'no_show'],
    };
    let rows = _resvData.reservations || [];
    if (filter !== 'all') rows = rows.filter(r => (groups[filter] || []).includes(r.status));

    tbody.innerHTML = rows.length ? rows.map(r => `<tr>
        <td class="text-muted">#${r.id}</td>
        <td style="font-weight:500;">${resvEsc(r.book_title)}</td>
        <td>${resvEsc(r.user_name || '—')}</td>
        <td style="text-align:center;font-weight:700;">${r.quantity}</td>
        <td>${resvFmtD(r.start_date)}</td>
        <td>${resvFmtD(r.end_date)}</td>
        <td>${resvChip(r.status)}</td>
        <td style="font-size:.72rem;color:${r.pickup_deadline && new Date(r.pickup_deadline) < new Date() ? '#dc2626' : 'var(--text-muted)'};">
            ${r.pickup_deadline ? resvEsc(String(r.pickup_deadline).slice(0, 16)) : '—'}</td>
        <td><div style="display:flex;gap:5px;">
            ${['confirmed', 'ready'].includes(r.status)
                ? `<button class="btn btn-sm btn-success py-0 px-2" style="font-size:.7rem;" onclick="resvConvert(${r.id})" title="Record pickup — converts to a borrow"><i class="fas fa-hand-holding me-1"></i>Pickup</button>` : ''}
            ${['pending', 'confirmed', 'ready'].includes(r.status)
                ? `<button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.7rem;" onclick="resvCancel(${r.id})"><i class="fas fa-times"></i></button>` : ''}
        </div></td>
    </tr>`).join('') : '<tr><td colspan="9" class="text-center text-muted py-4" style="font-size:.8rem;">No reservations in this view.</td></tr>';
}

function resvRenderStaffWl() {
    const tbody = document.getElementById('resv-staff-wl-body');
    if (!tbody) return;
    const rows = _resvData.waitlist || [];
    tbody.innerHTML = rows.length ? rows.map(w => `<tr>
        <td style="font-weight:700;">${w.queue_position ? '#' + w.queue_position : '—'}</td>
        <td style="font-weight:500;">${resvEsc(w.book_title)}</td>
        <td>${resvEsc(w.user_name || '—')}</td>
        <td style="text-align:center;font-weight:700;">${w.quantity}${Number(w.allow_partial) ? '' : ' (exact)'}</td>
        <td style="font-size:.74rem;">${resvFmtD(w.preferred_start)} – ${resvFmtD(w.preferred_end)}</td>
        <td>${resvChip(w.status)}</td>
        <td style="font-size:.72rem;">${w.offer_expires_at ? resvEsc(String(w.offer_expires_at).slice(0, 16)) : '—'}</td>
        <td style="font-size:.72rem;color:var(--text-muted);">${resvEsc(String(w.created_at || '').slice(0, 16))}</td>
        <td>${w.status === 'waiting'
            ? `<button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.7rem;" onclick="resvNotifyQueue(${w.book_id})" title="Try sending an offer now"><i class="fas fa-bell"></i></button>` : ''}</td>
    </tr>`).join('') : '<tr><td colspan="9" class="text-center text-muted py-4" style="font-size:.8rem;">Waiting list is empty.</td></tr>';
}

function resvConvert(id) {
    if (!confirm('Record pickup for this reservation? This creates a borrow record and deducts shelf stock.')) return;
    const fd = new FormData();
    fd.append('action', 'reservation_convert');
    fd.append('id', id);
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin', body: fd,
        headers: { 'X-CSRF-Token': resvCsrf() },
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Converted.' : 'Failed.'), res.success ? 'success' : 'error');
        resvLoadMine(); resvLoadCalendar();
        if (typeof loadBookBorrowRequests === 'function') loadBookBorrowRequests();
    });
}

function resvNotifyQueue(bookId) {
    fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': resvCsrf() },
        body: JSON.stringify({ action: 'notify_next_in_queue', book_id: bookId }),
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || '', res.success ? 'success' : 'error');
        resvLoadMine();
    });
}

/* ── Wire-up ────────────────────────────────────────────────────── */
document.addEventListener('tabChanged', e => { if (e.detail === 'reservations') resvRefreshAll(); });
document.addEventListener('DOMContentLoaded', () => {
    _resvMonth = new Date(); _resvMonth.setDate(1);
    const t = new Date();
    const startEl = document.getElementById('resv-start');
    const endEl = document.getElementById('resv-end');
    if (startEl) startEl.min = t.toISOString().slice(0, 10);
    if (endEl) endEl.min = t.toISOString().slice(0, 10);
    if (document.getElementById('reservations')?.classList.contains('active')) resvRefreshAll();
    else resvRefreshAll();   // pre-load so offers badge data is fresh when user switches
});
</script>