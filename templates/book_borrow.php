<div class="page-header">
    <div>
        <h1 class="page-title">Borrowing</h1>
        <p class="text-muted mb-0" style="font-size:.8rem;">
            <?php if (!empty($isStaff)): ?>
            Manage book borrow requests and returns
            <?php else: ?>
            Track your borrow requests here. Need it for future dates?
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

        <?php if (!empty($isStaff)): ?>
        <button class="btn btn-primary btn-sm" onclick="openBorrowRequestModal()">
            <i class="fas fa-plus me-1"></i> New Borrow Request
        </button>
        <?php else: ?>
        <button class="btn btn-outline-primary btn-sm" onclick="switchTabById('books')">
            <i class="fas fa-magnifying-glass me-1"></i> Browse Catalog
        </button>
        <button class="btn btn-primary btn-sm" onclick="openBorrowCart()">
            <i class="fas fa-basket-shopping me-1"></i> My Borrow Cart
        </button>
        <?php endif; ?>
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

<!-- Borrow record detail (rendered by viewBorrowRecord in app.js) -->
<div class="modal fade" id="borrowRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="padding:12px 20px;">
                <span style="font-weight:700;font-size:.9rem;"><i class="fas fa-receipt me-2" style="color:var(--primary);"></i>Borrow Request</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="borrowRecordBody"></div>
        </div>
    </div>
</div>
