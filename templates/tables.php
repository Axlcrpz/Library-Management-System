<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Documents</h2>
    <div class="mb-2 d-flex align-items-center flex-wrap gap-2">
        <span class="fw-bold">Filter:</span>
        <div class="btn-group" id="status-filter-group">
            <button type="button" class="btn btn-outline-secondary btn-sm active" data-status="all">All</button>
            <button type="button" class="btn btn-outline-success btn-sm" data-status="available">Available</button>
            <button type="button" class="btn btn-outline-warning btn-sm" data-status="borrowed">Borrowed</button>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span></span>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-primary btn-sm btn-open-add"><i class="fas fa-plus me-1"></i> Add Document</button>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-secondary btn-sm" onclick="openBatchQrModal()"><i class="fas fa-qrcode me-1"></i> Batch QR</button>
            <button type="button" class="btn btn-info btn-sm" id="scan-qr-btn"><i class="fas fa-camera me-1"></i> Scan QR</button>
            <?php endif; ?>
            <select id="yearFilterDocuments" class="form-select form-select-sm" style="width:auto;"></select>
            <div class="input-group input-group-sm" style="width:250px;">
                <input type="text" class="form-control" id="search-input-documents" placeholder="Search…">
                <button class="btn btn-outline-secondary" id="search-button-documents"><i class="fas fa-search"></i></button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover activity-table mb-0">
                <thead>
                    <tr>
                        <th>Title</th><th>Type</th><th>Section / Location</th><th>Document Date</th><?php if ($isAdmin): ?><th>Borrowed By</th><?php endif; ?><th>Status</th><th>File</th>
                        <?php if ($isAdmin): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="activities-table-body-documents"></tbody>
            </table>
        </div>
    </div>
</div>
