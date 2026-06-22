<!-- ── Inventory header ─────────────────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h1 class="page-title">Inventory</h1>
    </div>
    <?php if (!empty($isStaff)): ?>
    <div class="page-actions">
        <button class="btn btn-primary btn-sm" onclick="openBookDiscovery()">
            <i class="fas fa-wand-magic-sparkles me-1"></i> Discover &amp; Add
        </button>
        <button class="btn btn-outline-primary btn-sm" onclick="openAddBookModal()">
            <i class="fas fa-plus me-1"></i> Add Manually
        </button>
        <?php if ($isAdmin): ?>
        <button class="btn btn-outline-primary btn-sm" onclick="openBulkImportModal()">
            <i class="fas fa-file-import me-1"></i> Import
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="inv-enrich-btn" onclick="invBackfill()"
                title="Fetch covers, descriptions &amp; subjects for books that are missing them">
            <i class="fas fa-cloud-arrow-down me-1"></i> Enrich Metadata
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Inventory Health Overview ────────────────────────────────────────────── -->
<div id="inventoryHealth" class="row g-2 mb-3">
    <div class="col-12 text-center text-muted py-3" style="font-size:.82rem;">
        <i class="fas fa-spinner fa-spin me-2"></i> Loading inventory…
    </div>
</div>

<!-- ── Toolbar: search · sort · view mode ───────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="input-group input-group-sm" style="max-width:340px;flex:1;">
                <span class="input-group-text"><i class="fas fa-search" style="font-size:.72rem;"></i></span>
                <input type="text" id="inv-search" class="form-control"
                       placeholder="Search title, author, ISBN, subject, accession #…" oninput="invApplyFilters()">
            </div>
            <select id="inv-sort" class="form-select form-select-sm" style="width:auto;" onchange="invSetSort(this.value)" title="Sort">
                <option value="title">Title (A–Z)</option>
                <option value="author">Author (A–Z)</option>
                <option value="subject">Subject (A–Z)</option>
                <option value="avail_asc">Least available</option>
                <option value="avail_desc">Most available</option>
                <option value="total_desc">Most copies</option>
                <option value="recent">Recently added</option>
            </select>
            <div class="btn-group btn-group-sm ms-auto" role="group" aria-label="View mode">
                <button type="button" class="btn btn-outline-secondary inv-view-btn active" data-view="grid" onclick="invSetView('grid')" title="Grid view"><i class="fas fa-grip"></i></button>
                <button type="button" class="btn btn-outline-secondary inv-view-btn" data-view="table" onclick="invSetView('table')" title="Table view"><i class="fas fa-table-list"></i></button>
                <button type="button" class="btn btn-outline-secondary inv-view-btn" data-view="compact" onclick="invSetView('compact')" title="Compact view"><i class="fas fa-bars"></i></button>
            </div>
        </div>
    </div>
</div>

<!-- ── Category pills (the primary organizational layer) ────────────────────── -->
<div id="inventoryCategories" class="d-flex flex-wrap gap-2 mb-3"></div>

<!-- ── Results ──────────────────────────────────────────────────────────────── -->
<div id="inventoryContainer"></div>
