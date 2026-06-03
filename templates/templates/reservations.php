<?php
// templates/reservations.php
// Reservation Queue — Module 08
?>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Reservations</h1>
        <p class="text-muted mb-0" style="font-size:.8rem;">
            <?= $isAdmin ? 'Manage all book reservation queues' : 'Track and manage your book reservations' ?>
        </p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-secondary btn-sm" onclick="loadReservations()">
            <i class="fas fa-rotate-right me-1"></i> Refresh
        </button>
        <?php if (!$isAdmin): ?>
        <button class="btn btn-primary btn-sm" onclick="openReserveModal()">
            <i class="fas fa-bookmark me-1"></i> Reserve a Book
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Admin: summary stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Waiting</div>
                    <div class="stat-card-value" id="res-stat-waiting">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Ready to Borrow</div>
                    <div class="stat-card-value" id="res-stat-ready">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-bell"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Expired Today</div>
                    <div class="stat-card-value" id="res-stat-expired">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-clock"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div>
                    <div class="stat-card-label">Total Active</div>
                    <div class="stat-card-value" id="res-stat-total">—</div>
                </div>
                <div class="stat-card-icon"><i class="fas fa-list-check"></i></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Reservations table -->
<div class="card">
    <div class="card-header">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-bookmark" style="color:var(--primary);"></i>
            <span style="font-weight:600;font-size:.85rem;">
                <?= $isAdmin ? 'All Reservations' : 'My Reservations' ?>
            </span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <select id="res-status-filter" class="form-select form-select-sm" style="width:auto;" onchange="loadReservations()">
                <option value="active">Active (waiting + ready)</option>
                <option value="waiting">Waiting</option>
                <option value="ready">Ready to Borrow</option>
                <option value="expired">Expired</option>
                <option value="cancelled">Cancelled</option>
                <option value="fulfilled">Fulfilled</option>
                <option value="all">All</option>
            </select>
            <div class="input-group input-group-sm" style="width:180px;">
                <span class="input-group-text"><i class="fas fa-search" style="font-size:.68rem;"></i></span>
                <input type="text" class="form-control" id="res-search" placeholder="Search..." oninput="filterResTable()">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Book</th>
                        <?php if ($isAdmin): ?><th>Reserved By</th><?php endif; ?>
                        <th>Queue Position</th>
                        <th>Status</th>
                        <th>Reserved On</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="reservations-table-body">
                    <tr>
                        <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="text-center text-muted py-4" style="font-size:.82rem;">
                            <i class="fas fa-spinner fa-spin me-2"></i> Loading...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reserve a Book Modal (user-facing) -->
<div class="modal fade" id="reserveBookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-bookmark me-2"></i>Reserve a Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.82rem;font-weight:600;">Select Book</label>
                    <select class="form-select" id="reserve-book-select">
                        <option value="">— Loading books... —</option>
                    </select>
                    <div id="reserve-book-info" class="mt-2" style="font-size:.78rem;color:var(--text-muted);"></div>
                </div>
                <div id="reserve-modal-alert" class="alert d-none" style="font-size:.8rem;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" onclick="submitReservation()">
                    <i class="fas fa-bookmark me-1"></i> Join Queue
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reservation Detail Modal (admin) -->
<div class="modal fade" id="resDetailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reservation Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="res-detail-body">
                <!-- filled by JS -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button class="btn btn-danger btn-sm" id="res-cancel-btn" onclick="cancelReservationFromModal()">
                    <i class="fas fa-times me-1"></i> Cancel Reservation
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Reservation Module JS ─────────────────────────────────────── */

let _allReservations = [];
let _activeResId = null;

function loadReservations() {
    const tbody = document.getElementById('reservations-table-body');
    const colSpan = window.isAdmin ? 8 : 7;
    tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted py-4" style="font-size:.82rem;"><i class="fas fa-spinner fa-spin me-2"></i> Loading...</td></tr>`;

    fetch('api/library_handler.php?action=get_reservations', {
        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { showToast('Failed to load reservations', 'error'); return; }
        _allReservations = res.data || [];
        updateResStats(_allReservations);
        filterResTable();
    })
    .catch(() => showToast('Network error loading reservations', 'error'));
}

function filterResTable() {
    const statusFilter = document.getElementById('res-status-filter')?.value || 'active';
    const search = (document.getElementById('res-search')?.value || '').toLowerCase();
    const colSpan = window.isAdmin ? 8 : 7;

    let filtered = _allReservations.filter(r => {
        if (statusFilter === 'active') return ['waiting','ready'].includes(r.status);
        if (statusFilter !== 'all') return r.status === statusFilter;
        return true;
    });

    if (search) {
        filtered = filtered.filter(r =>
            (r.book_title || '').toLowerCase().includes(search) ||
            (r.user_name  || '').toLowerCase().includes(search)
        );
    }

    const tbody = document.getElementById('reservations-table-body');
    if (!filtered.length) {
        tbody.innerHTML = `<tr><td colspan="${colSpan}" class="text-center text-muted py-4" style="font-size:.82rem;">No reservations found.</td></tr>`;
        return;
    }

    const statusBadge = {
        waiting:   '<span class="badge" style="background:#fef9c3;color:#854d0e;">Waiting</span>',
        ready:     '<span class="badge" style="background:#dcfce7;color:#166534;">Ready</span>',
        expired:   '<span class="badge" style="background:#fee2e2;color:#991b1b;">Expired</span>',
        cancelled: '<span class="badge" style="background:#f3f4f6;color:#374151;">Cancelled</span>',
        fulfilled: '<span class="badge" style="background:#ede9fe;color:#4c1d95;">Fulfilled</span>',
    };

    tbody.innerHTML = filtered.map(r => `
        <tr>
            <td style="font-size:.78rem;color:var(--text-muted);">#${r.id}</td>
            <td style="font-size:.82rem;font-weight:500;">${resEscHtml(r.book_title || '—')}</td>
            ${window.isAdmin ? `<td style="font-size:.82rem;">${resEscHtml(r.user_name || '—')}</td>` : ''}
            <td>
                <span style="font-size:.82rem;font-weight:600;color:${r.queue_position === 1 ? 'var(--success)' : 'var(--text)'};">
                    #${r.queue_position}
                    ${r.queue_position === 1 ? '<i class="fas fa-star ms-1" style="font-size:.7rem;color:#f59e0b;" title="Next in line"></i>' : ''}
                </span>
            </td>
            <td>${statusBadge[r.status] || r.status}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${resFormatDate(r.created_at)}</td>
            <td style="font-size:.78rem;color:${r.expires_at && new Date(r.expires_at) < new Date() ? '#dc2626' : 'var(--text-muted)'};">
                ${r.expires_at ? resFormatDate(r.expires_at) : '—'}
            </td>
            <td>
                <div style="display:flex;gap:6px;">
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem;padding:3px 8px;"
                            onclick="viewResDetail(${JSON.stringify(r).replace(/"/g,'&quot;')})">
                        <i class="fas fa-eye me-1"></i> View
                    </button>
                    ${['waiting','ready'].includes(r.status) ? `
                    <button class="btn btn-sm btn-outline-danger" style="font-size:.72rem;padding:3px 8px;"
                            onclick="cancelReservation(${r.id})">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>` : ''}
                    ${window.isAdmin && r.status === 'waiting' ? `
                    <button class="btn btn-sm btn-outline-success" style="font-size:.72rem;padding:3px 8px;"
                            onclick="notifyNext(${r.book_id})">
                        <i class="fas fa-bell me-1"></i> Notify
                    </button>` : ''}
                </div>
            </td>
        </tr>
    `).join('');
}

function updateResStats(data) {
    if (!window.isAdmin) return;
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('res-stat-waiting', data.filter(r => r.status === 'waiting').length);
    set('res-stat-ready',   data.filter(r => r.status === 'ready').length);
    const today = new Date().toDateString();
    set('res-stat-expired', data.filter(r => r.status === 'expired' && new Date(r.updated_at).toDateString() === today).length);
    set('res-stat-total',   data.filter(r => ['waiting','ready'].includes(r.status)).length);
}

function openReserveModal() {
    const sel = document.getElementById('reserve-book-select');
    sel.innerHTML = '<option value="">Loading books...</option>';
    document.getElementById('reserve-modal-alert').classList.add('d-none');

    fetch('api/library_handler.php?action=books_get')
        .then(r => r.json())
        .then(res => {
            const books = (res.data || res.books || []);
            sel.innerHTML = '<option value="">— Select a book —</option>' +
                books.map(b => {
                    const avail = b.quantity_available ?? b.available_copies ?? b.available ?? 0;
                    return `<option value="${b.id}" data-available="${avail}">${resEscHtml(b.title)} (Available: ${avail})</option>`;
                }).join('');
        });

    sel.onchange = function() {
        const opt = sel.options[sel.selectedIndex];
        const avail = parseInt(opt.dataset.available || 0);
        const info = document.getElementById('reserve-book-info');
        if (!sel.value) { info.textContent = ''; return; }
        info.innerHTML = avail > 0
            ? '<i class="fas fa-circle-check text-success me-1"></i> Book is available — you can borrow directly instead of reserving.'
            : '<i class="fas fa-circle-info text-warning me-1"></i> Book is currently unavailable. You will be added to the waiting queue.';
    };

    new bootstrap.Modal(document.getElementById('reserveBookModal')).show();
}

function submitReservation() {
    const bookId = parseInt(document.getElementById('reserve-book-select').value);
    const alertEl = document.getElementById('reserve-modal-alert');
    if (!bookId) {
        alertEl.className = 'alert alert-warning';
        alertEl.textContent = 'Please select a book.';
        alertEl.classList.remove('d-none');
        return;
    }

    fetch('api/library_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ action: 'create_reservation', book_id: bookId })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('reserveBookModal')).hide();
            showToast(res.message || 'Reservation created!', 'success');
            loadReservations();
        } else {
            alertEl.className = 'alert alert-danger';
            alertEl.textContent = res.message || 'Could not create reservation.';
            alertEl.classList.remove('d-none');
        }
    });
}

function cancelReservation(id) {
    if (!confirm('Cancel this reservation?')) return;
    fetch('api/library_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ action: 'cancel_reservation', id: id })
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Cancelled.' : 'Error'), res.success ? 'success' : 'error');
        if (res.success) loadReservations();
    });
}

function notifyNext(bookId) {
    if (!confirm('Notify the next person in queue that the book is ready?')) return;
    fetch('api/library_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ action: 'notify_next_in_queue', book_id: bookId })
    })
    .then(r => r.json())
    .then(res => {
        showToast(res.message || (res.success ? 'Notified.' : 'Error'), res.success ? 'success' : 'error');
        if (res.success) loadReservations();
    });
}

function viewResDetail(r) {
    _activeResId = r.id;
    const cancelBtn = document.getElementById('res-cancel-btn');
    if (cancelBtn) cancelBtn.style.display = ['waiting','ready'].includes(r.status) ? '' : 'none';

    document.getElementById('res-detail-body').innerHTML = `
        <table class="table table-sm mb-0" style="font-size:.82rem;">
            <tr><td class="text-muted" style="width:40%">Book</td><td><strong>${resEscHtml(r.book_title||'—')}</strong></td></tr>
            ${window.isAdmin ? `<tr><td class="text-muted">Reserved By</td><td>${resEscHtml(r.user_name||'—')} <span class="text-muted">(${resEscHtml(r.user_email||'')})</span></td></tr>` : ''}
            <tr><td class="text-muted">Queue Position</td><td>#${r.queue_position}</td></tr>
            <tr><td class="text-muted">Status</td><td>${r.status}</td></tr>
            <tr><td class="text-muted">Reserved On</td><td>${resFormatDate(r.created_at)}</td></tr>
            <tr><td class="text-muted">Notified At</td><td>${r.notified_at ? resFormatDate(r.notified_at) : '—'}</td></tr>
            <tr><td class="text-muted">Expires At</td><td>${r.expires_at ? resFormatDate(r.expires_at) : '—'}</td></tr>
        </table>
    `;
    new bootstrap.Modal(document.getElementById('resDetailModal')).show();
}

function cancelReservationFromModal() {
    if (_activeResId) {
        bootstrap.Modal.getInstance(document.getElementById('resDetailModal')).hide();
        cancelReservation(_activeResId);
    }
}

function resFormatDate(dt) {
    if (!dt) return '—';
    return new Date(dt).toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
}

function resEscHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-load on tab activation
document.addEventListener('DOMContentLoaded', function() {
    loadReservations();
});
</script>