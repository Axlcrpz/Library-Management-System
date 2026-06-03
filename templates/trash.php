<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Trash</h2>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-danger btn-sm" id="trash-delete-selected-btn">
            <i class="fas fa-trash-alt me-1"></i> Delete Selected
        </button>
        <button type="button" class="btn btn-danger btn-sm" id="trash-empty-btn">
            <i class="fas fa-trash me-1"></i> Empty Trash
        </button>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span class="small text-muted">Deleted documents are kept for 30 days before automatic cleanup.</span>
        <div class="input-group input-group-sm" style="width:250px;">
            <input type="text" class="form-control" id="search-input-trash" placeholder="Search...">
            <button class="btn btn-outline-secondary" id="search-button-trash" type="button"><i class="fas fa-search"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover activity-table mb-0">
                <thead>
                    <tr>
                        <th style="width:45px;"><input type="checkbox" id="trash-select-all" title="Select all trash documents"></th>
                        <th>Title</th><th>Type</th><th>Section</th><th>Document Date</th><th>Deleted</th><th>Days Left</th><th>File</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody id="trash-table-body"></tbody>
            </table>
        </div>
    </div>
</div>
