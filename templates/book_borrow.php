<div class="page-header">
    <div>
        <h1 class="page-title">Borrowing</h1>
        <p class="text-muted mb-0" style="font-size:.8rem;">
            <?php if (!empty($isStaff)): ?>
            Manage book borrow requests and returns
            <?php else: ?>
            Request to check out a book now. Need it for future dates?
            <a href="#" onclick="switchTabById('reservations');return false;">Reserve it instead</a>.
            <?php endif; ?>
        </p>
    </div>

    <div class="page-actions">
        <select id="book-borrow-status-filter"
                class="form-select form-select-sm"
                onchange="loadBookBorrowRequests()">
            <option value="active">Active Borrows</option>
            <option value="pending">Pending Requests</option>
            <option value="returned">Returned</option>
            <option value="rejected">Rejected</option>
            <option value="cancelled">Cancelled</option>
            <option value="all">All Records</option>
        </select>

        <button class="btn btn-outline-secondary btn-sm" onclick="loadBookBorrowRequests()">
            <i class="fas fa-rotate-right me-1"></i> Refresh
        </button>

        <button class="btn btn-primary btn-sm" onclick="openBorrowRequestModal()">
            <i class="fas fa-plus me-1"></i> New Borrow Request
        </button>
    </div>
</div>
<?php if (!empty($isStaff)): ?>
<div class="card mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <div style="width:28px;height:28px;border-radius:7px;background:#fef9c3;
                    display:flex;align-items:center;justify-content:center;
                    color:#854d0e;font-size:.78rem;">
            <i class="fas fa-clock"></i>
        </div>

        <span>Pending Requests</span>

        <span id="pending-count-badge"
              class="badge"
              style="background:#fef3c7;color:#92400e;display:none;">
            0
        </span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Borrower</th>
                        <th>Type</th>
                        <th>Time Limit</th>
                        <th>Books</th>
                        <th>Requested</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="book-borrow-pending-body">
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="fas fa-spinner fa-spin me-2"></i> Loading...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <div style="width:28px;height:28px;border-radius:7px;
                    background:var(--primary-light);
                    display:flex;align-items:center;justify-content:center;
                    color:var(--primary);font-size:.78rem;">
            <i class="fas fa-list-check"></i>
        </div>

        <span><?= !empty($isStaff) ? 'Borrow Records' : 'My Borrow Requests' ?></span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Borrower</th>

                        <?php if (!empty($isStaff)): ?>
                            <th>Requested By</th>
                        <?php endif; ?>

                        <th>Books</th>
                        <th>Borrowed</th>
                        <th>Due</th>
                        <th>Returned</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody id="book-borrow-records-body">
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fas fa-spinner fa-spin me-2"></i> Loading...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
let borrowModalInstance = null;

/**
 * OPEN BORROW MODAL (FIXED)
 */
window.openBorrowRequestModal = function () {
    const modalEl = document.getElementById('borrowRequestModal');

    if (!modalEl) {
        console.error("Borrow modal not found (#borrowRequestModal)");
        return;
    }

    borrowModalInstance = new bootstrap.Modal(modalEl);
    borrowModalInstance.show();
};

/**
 * LOAD BORROW REQUESTS
 */
window.loadBookBorrowRequests = async function () {
    const status = document.getElementById('book-borrow-status-filter')?.value || 'active';

    try {
        const res = await fetch(`api/library_handler.php?action=borrow_list&status=${status}`, {
            credentials: 'same-origin'
        });

        const data = await res.json();

        if (!data.success) {
            console.error(data);
            return;
        }

        renderBorrowTables(data.data);

    } catch (err) {
        console.error("Failed to load borrow requests:", err);
    }
};

/**
 * RENDER TABLES
 */
function renderBorrowTables(data) {
    const recordsBody = document.getElementById('book-borrow-records-body');
    const pendingBody = document.getElementById('book-borrow-pending-body');

    if (recordsBody) {
        recordsBody.innerHTML = data.records?.length
            ? data.records.map(renderRow).join('')
            : `<tr><td colspan="8" class="text-center text-muted py-3">No records found</td></tr>`;
    }

    if (pendingBody) {
        pendingBody.innerHTML = data.pending?.length
            ? data.pending.map(renderPendingRow).join('')
            : `<tr><td colspan="6" class="text-center text-muted py-3">No pending requests</td></tr>`;
    }

    const badge = document.getElementById('pending-count-badge');
    if (badge) {
        const count = data.pending?.length || 0;
        badge.textContent = count;
        badge.style.display = count ? 'inline-block' : 'none';
    }
}

/**
 * ROW (MAIN TABLE)
 */
function renderRow(b) {
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const staffCell = window.isStaff ? `<td>${esc(b.requested_by_name || '-')}</td>` : '';
    return `
        <tr>
            <td><span class="badge bg-secondary">${esc(b.status)}</span></td>
            <td>${esc(b.borrower_name)}</td>
            ${staffCell}
            <td>${esc(b.books || (b.items?.map(i=>i.title).join(', ')) || '-')}</td>
            <td>${esc(b.borrowed_at || '-')}</td>
            <td>${esc(b.due_at || '-')}</td>
            <td>${esc(b.returned_at || '-')}</td>
            <td>
                <button class="btn btn-sm btn-outline-primary"
                        onclick="viewBorrowRecord(${b.id})">
                    View
                </button>
            </td>
        </tr>
    `;
}

/**
 * PENDING ROW
 */
function renderPendingRow(b) {
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    return `
        <tr>
            <td>${esc(b.borrower_name)}</td>
            <td>${esc(b.borrow_type || '-')}</td>
            <td>${b.time_allowed_minutes ? b.time_allowed_minutes + ' min' : '-'}</td>
            <td>${(b.items||[]).map(i=>esc(i.title)).join(', ') || '-'}</td>
            <td>${esc(b.requested_at || '-')}</td>
            <td>
                <button class="btn btn-success btn-sm" onclick="approveBorrowRequest(${b.id})">Approve</button>
                <button class="btn btn-danger btn-sm ms-1" onclick="rejectBorrowRequest(${b.id})">Reject</button>
            </td>
        </tr>
    `;
}

async function approveBorrowRequest(id) {
    if (!confirm('Approve this borrow request?')) return;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const params = new URLSearchParams({ action: 'book_borrow_approve', id });
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: params
    }).then(r => r.json()).catch(() => ({ success: false }));
    if (res.success) { await loadBookBorrowRequests(); }
    else { alert(res.message || 'Failed to approve.'); }
}

async function rejectBorrowRequest(id) {
    if (!confirm('Reject this borrow request?')) return;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const params = new URLSearchParams({ action: 'book_borrow_reject', id });
    const res = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: params
    }).then(r => r.json()).catch(() => ({ success: false }));
    if (res.success) { await loadBookBorrowRequests(); }
    else { alert(res.message || 'Failed to reject.'); }
}

function viewBorrowRecord(id) {
    // Placeholder: open modal or navigate to borrow detail
    alert('Borrow record #' + id + ' — detailed view coming soon.');
}


document.addEventListener('DOMContentLoaded', () => {
    loadBookBorrowRequests();
});
</script>