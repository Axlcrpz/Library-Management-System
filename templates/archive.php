<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Archive</h2>
</div>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-end align-items-center flex-wrap gap-2">
        <select id="yearFilterArchive" class="form-select form-select-sm" style="width:auto;"></select>
        <div class="input-group input-group-sm" style="width:250px;">
            <input type="text" class="form-control" id="search-input-archive" placeholder="Search…">
            <button class="btn btn-outline-secondary" id="search-button-archive"><i class="fas fa-search"></i></button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover activity-table mb-0">
                <thead>
                    <tr>
                        <th>Title</th><th>Type</th><th>Section / Location</th><th>Document Date</th><th>Borrowed By</th><th>Status</th><th>File</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="activities-table-body-archive"></tbody>
            </table>
        </div>
    </div>
</div>
