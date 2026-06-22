<!-- ══════════════════════════════════════════════════════════════════
     DELIVERY LOG — Full Management Module
══════════════════════════════════════════════════════════════════ -->
<div class="page-header">
    <div>
        <h1 class="page-title">Delivery Log</h1>
    </div>
    <?php if (!empty($isStaff)): ?>
    <div class="page-actions">
        <button class="btn btn-primary btn-sm" onclick="openDeliveryModal()">
            <i class="fas fa-plus me-1"></i>Log Delivery
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Summary stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card stat-primary">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Total Deliveries</div>
                    <div class="stat-card-value" id="dlv-stat-total">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-truck-ramp-box"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-success">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Books Received</div>
                    <div class="stat-card-value" id="dlv-stat-books">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-book"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-warning">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">Damaged / Missing</div>
                    <div class="stat-card-value" id="dlv-stat-damaged">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-triangle-exclamation"></i></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card stat-info">
            <div style="display:flex;justify-content:space-between;align-items:start;">
                <div><div class="stat-card-label">This Month</div>
                    <div class="stat-card-value" id="dlv-stat-month">—</div></div>
                <div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <div class="input-group input-group-sm" style="width:220px;">
        <span class="input-group-text"><i class="fas fa-search" style="font-size:.7rem;"></i></span>
        <input type="text" id="dlvSearch" class="form-control" placeholder="Source, supplier, PO#, remarks…"
               oninput="filterDeliveries()">
    </div>
    <input type="month" id="dlvMonthFilter" class="form-control form-control-sm" style="width:160px;"
           onchange="filterDeliveries()">
    <select id="dlvStatusFilter" class="form-select form-select-sm" style="width:140px;" onchange="filterDeliveries()">
        <option value="">All Statuses</option>
        <option value="pending">Pending</option>
        <option value="received">Received</option>
        <option value="approved">Approved</option>
        <option value="cancelled">Cancelled</option>
    </select>
    <button class="btn btn-outline-secondary btn-sm" onclick="dlvClearFilters()">
        <i class="fas fa-xmark me-1"></i>Clear
    </button>
</div>

<!-- Deliveries table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="font-size:.76rem;width:100px;">Date</th>
                        <th style="font-size:.76rem;">Source / Supplier</th>
                        <th style="font-size:.76rem;width:80px;text-align:center;">Items</th>
                        <th style="font-size:.76rem;width:80px;text-align:center;">Qty</th>
                        <th style="font-size:.76rem;width:90px;">Status</th>
                        <th style="font-size:.76rem;width:120px;">PO / Ref #</th>
                        <th style="font-size:.76rem;">Logged By</th>
                        <th style="font-size:.76rem;width:140px;text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="dlvTableBody">
                    <tr><td colspan="8" class="text-center text-muted py-5" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading…
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detail / Items panel -->
<div id="dlvDetailPanel" class="card mt-3" style="display:none;">
    <div class="card-header d-flex justify-content-between align-items-center py-2" style="font-size:.85rem;font-weight:600;">
        <span id="dlvDetailTitle">Delivery Detail</span>
        <div class="d-flex gap-1">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.72rem;" onclick="closeDlvDetail()">
                <i class="fas fa-xmark me-1"></i>Close
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <!-- Delivery info bar -->
        <div id="dlvDetailInfo" style="padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e5e7eb;font-size:.78rem;display:flex;flex-wrap:wrap;gap:18px;"></div>
        <!-- Items table -->
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="font-size:.74rem;">Book Title</th>
                        <th style="font-size:.74rem;">Subject</th>
                        <th style="font-size:.74rem;">Grade</th>
                        <th style="font-size:.74rem;text-align:center;">Received</th>
                        <th style="font-size:.74rem;text-align:center;">Damaged</th>
                        <th style="font-size:.74rem;text-align:center;">Missing</th>
                        <th style="font-size:.74rem;">Notes</th>
                    </tr>
                </thead>
                <tbody id="dlvDetailBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── ADD / EDIT DELIVERY MODAL ──────────────────────────────────────── -->
<div class="modal fade" id="deliveryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title" id="dlvModalTitle" style="font-size:.92rem;font-weight:600;">
                    <i class="fas fa-truck-ramp-box me-2 text-primary"></i>Log New Delivery
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dlv_edit_id" value="">

                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">Delivery Date <span class="text-danger">*</span></label>
                        <input type="date" id="dlv_date" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">Status</label>
                        <select id="dlv_status" class="form-select form-select-sm">
                            <option value="pending">Pending</option>
                            <option value="received" selected>Received</option>
                            <option value="approved">Approved</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small mb-1">Received By</label>
                        <input type="text" id="dlv_received_by" class="form-control form-control-sm" placeholder="Name of receiving officer">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small mb-1">Source / Supplier <span class="text-danger">*</span></label>
                        <input type="text" id="dlv_source" class="form-control form-control-sm"
                               placeholder="e.g. DepEd Central Office, Vibal Group Inc.">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small mb-1">PO Number</label>
                        <input type="text" id="dlv_po" class="form-control form-control-sm" placeholder="PO-XXXX">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small mb-1">Ref Number</label>
                        <input type="text" id="dlv_ref" class="form-control form-control-sm" placeholder="Ref-XXXX">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Remarks</label>
                        <textarea id="dlv_remarks" class="form-control form-control-sm" rows="2"
                                  placeholder="Optional delivery notes, conditions, discrepancies…"></textarea>
                    </div>
                </div>

                <hr class="my-2">
                <!-- Items section — hidden when editing header only -->
                <div id="dlv_items_section">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span style="font-size:.83rem;font-weight:600;">Book Items</span>
                        <div class="d-flex gap-2">
                            <div class="input-group input-group-sm" style="width:220px;">
                                <input type="text" id="dlv_isbn_input" class="form-control"
                                       placeholder="Scan / enter ISBN…"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();dlvIsbnLookup();}">
                                <button class="btn btn-outline-primary" type="button" onclick="dlvIsbnLookup()" title="Look up book by ISBN">
                                    <i class="fas fa-barcode"></i>
                                </button>
                            </div>
                            <button class="btn btn-sm btn-outline-success" onclick="dlvAddRow(null)">
                                <i class="fas fa-plus me-1"></i>Add Row
                            </button>
                        </div>
                    </div>
                    <div id="dlv_isbn_msg" style="font-size:.76rem;min-height:18px;margin-bottom:6px;"></div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" style="font-size:.8rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Book</th>
                                    <th style="width:90px;text-align:center;">Received</th>
                                    <th style="width:90px;text-align:center;">Damaged</th>
                                    <th style="width:90px;text-align:center;">Missing</th>
                                    <th>Notes</th>
                                    <th style="width:36px;"></th>
                                </tr>
                            </thead>
                            <tbody id="dlvItemsBody">
                                <tr id="dlv_empty_row">
                                    <td colspan="6" class="text-center text-muted py-3" style="font-size:.78rem;">
                                        No items yet — use ISBN lookup or add a row manually.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="dlv_edit_note" class="alert alert-info py-1 px-3 mb-0 mt-1" style="font-size:.76rem;display:none;">
                        <i class="fas fa-info-circle me-1"></i>Editing only updates the header fields. To change book items, delete and re-create the delivery.
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="dlvSaveBtn" onclick="submitDelivery()">
                    <i class="fas fa-save me-1"></i>Save Delivery
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════════════
   DELIVERY LOG MODULE
════════════════════════════════════════════════════════════════════ */
let _allDeliveries = [];
let _dlvBooks      = [];
let _dlvRowCount   = 0;
let _deliveryModal = null;
let _dlvEditMode   = false;

const STATUS_BADGES = {
    pending:   '<span class="badge rounded-pill" style="background:#fef3c7;color:#92400e;font-size:.68rem;">Pending</span>',
    received:  '<span class="badge rounded-pill" style="background:#d1fae5;color:#065f46;font-size:.68rem;">Received</span>',
    approved:  '<span class="badge rounded-pill" style="background:#dbeafe;color:#1e40af;font-size:.68rem;">Approved</span>',
    cancelled: '<span class="badge rounded-pill" style="background:#fee2e2;color:#991b1b;font-size:.68rem;">Cancelled</span>',
};

// ── Load ──────────────────────────────────────────────────────────────
function loadDlvPage() {
    Promise.all([
        libApi('delivery_get'),
        libApi('books_get'),
    ]).then(([dr, br]) => {
        _allDeliveries = dr.data || [];
        _dlvBooks      = (br.data || []).filter(b => !b.is_archived && !b.is_deleted);
        updateDlvStats(_allDeliveries);
        renderDeliveries(_allDeliveries);
    }).catch(() => {
        document.getElementById('dlvTableBody').innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-4">Failed to load deliveries.</td></tr>';
    });
}

// ── Stats ─────────────────────────────────────────────────────────────
function updateDlvStats(data) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const now = new Date();
    const ym  = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    let totalBooks = 0, totalDamaged = 0, thisMonth = 0;
    data.forEach(d => {
        const items = d.items || [];
        totalBooks   += items.reduce((s, i) => s + parseInt(i.quantity_received || 0), 0);
        totalDamaged += items.reduce((s, i) => s + parseInt(i.quantity_damaged  || 0) + parseInt(i.quantity_missing || 0), 0);
        if ((d.delivery_date || '').startsWith(ym)) thisMonth++;
    });
    set('dlv-stat-total',   data.length);
    set('dlv-stat-books',   totalBooks);
    set('dlv-stat-damaged', totalDamaged);
    set('dlv-stat-month',   thisMonth + (thisMonth === 1 ? ' delivery' : ' deliveries'));
}

// ── Render table ──────────────────────────────────────────────────────
function renderDeliveries(data) {
    const tbody = document.getElementById('dlvTableBody');
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-5" style="font-size:.82rem;">No deliveries recorded yet.</td></tr>';
        return;
    }
    <?php $canEdit = !empty($isStaff); $canDelete = !empty($isAdmin); ?>
    const canEdit   = <?= $canEdit   ? 'true' : 'false' ?>;
    const canDelete = <?= $canDelete ? 'true' : 'false' ?>;

    tbody.innerHTML = data.map(d => {
        const items = d.items || [];
        const qty   = items.reduce((s, i) => s + parseInt(i.quantity_received || 0), 0);
        const badge = STATUS_BADGES[d.status || 'received'] || STATUS_BADGES.received;
        const poRef = [d.po_number, d.ref_number].filter(Boolean).join(' / ') || '—';
        return `<tr>
            <td style="font-size:.8rem;">${esc(d.delivery_date || '—')}</td>
            <td style="font-size:.8rem;font-weight:500;">
                ${esc(d.source || '—')}
                ${d.received_by ? `<div style="font-size:.7rem;color:#9ca3af;">Rcvd: ${esc(d.received_by)}</div>` : ''}
            </td>
            <td style="font-size:.8rem;text-align:center;">${items.length}</td>
            <td style="font-size:.8rem;text-align:center;font-weight:600;">${qty}</td>
            <td>${badge}</td>
            <td style="font-size:.75rem;color:#6b7280;">${esc(poRef)}</td>
            <td style="font-size:.76rem;">${esc(d.logged_by_name || '—')}</td>
            <td style="text-align:center;white-space:nowrap;">
                <button class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2" style="font-size:.68rem;"
                        onclick="showDlvDetail(${d.id})" title="View items">
                    <i class="fas fa-list-ul"></i>
                </button>
                <button class="btn btn-xs btn-outline-info btn-sm py-0 px-2" style="font-size:.68rem;"
                        onclick="openDeliveryDocs(${d.id},'${esc(d.source)}')" title="Documents">
                    <i class="fas fa-paperclip"></i>
                </button>
                ${canEdit ? `<button class="btn btn-xs btn-outline-primary btn-sm py-0 px-2" style="font-size:.68rem;"
                        onclick="openEditDelivery(${d.id})" title="Edit"><i class="fas fa-pencil"></i></button>` : ''}
                ${canDelete ? `<button class="btn btn-xs btn-outline-danger btn-sm py-0 px-2" style="font-size:.68rem;"
                        onclick="deleteDelivery(${d.id})" title="Delete"><i class="fas fa-trash"></i></button>` : ''}
            </td>
        </tr>`;
    }).join('');
}

// ── Filters ───────────────────────────────────────────────────────────
function filterDeliveries() {
    const q      = (document.getElementById('dlvSearch')?.value       || '').toLowerCase();
    const mo     = document.getElementById('dlvMonthFilter')?.value   || '';
    const status = document.getElementById('dlvStatusFilter')?.value  || '';
    let data = _allDeliveries;
    if (mo)     data = data.filter(d => (d.delivery_date || '').startsWith(mo));
    if (status) data = data.filter(d => (d.status || 'received') === status);
    if (q)      data = data.filter(d =>
        (d.source    || '').toLowerCase().includes(q) ||
        (d.remarks   || '').toLowerCase().includes(q) ||
        (d.po_number || '').toLowerCase().includes(q) ||
        (d.ref_number|| '').toLowerCase().includes(q) ||
        (d.items || []).some(i => (i.title || '').toLowerCase().includes(q))
    );
    renderDeliveries(data);
}

function dlvClearFilters() {
    document.getElementById('dlvSearch').value       = '';
    document.getElementById('dlvMonthFilter').value  = '';
    document.getElementById('dlvStatusFilter').value = '';
    renderDeliveries(_allDeliveries);
}

// ── Detail panel ──────────────────────────────────────────────────────
function showDlvDetail(id) {
    const d = _allDeliveries.find(x => x.id == id);
    if (!d) return;

    const badge = STATUS_BADGES[d.status || 'received'] || '';
    document.getElementById('dlvDetailTitle').innerHTML =
        `<i class="fas fa-truck-ramp-box me-1 text-primary"></i>
         Delivery #${d.id} — ${esc(d.delivery_date)} · ${esc(d.source)} &nbsp;${badge}`;

    const infoItems = [
        d.received_by && `<span><strong>Received By:</strong> ${esc(d.received_by)}</span>`,
        d.po_number   && `<span><strong>PO #:</strong> ${esc(d.po_number)}</span>`,
        d.ref_number  && `<span><strong>Ref #:</strong> ${esc(d.ref_number)}</span>`,
        d.remarks     && `<span><strong>Remarks:</strong> ${esc(d.remarks)}</span>`,
        `<span><strong>Logged By:</strong> ${esc(d.logged_by_name || '—')}</span>`,
    ].filter(Boolean);
    document.getElementById('dlvDetailInfo').innerHTML = infoItems.join('');

    const tbody = document.getElementById('dlvDetailBody');
    const items = d.items || [];
    tbody.innerHTML = !items.length
        ? '<tr><td colspan="7" class="text-center text-muted py-3" style="font-size:.8rem;">No items recorded.</td></tr>'
        : items.map(i => `
            <tr>
                <td style="font-size:.8rem;">${esc(i.title || '—')}</td>
                <td style="font-size:.76rem;">${esc(i.subject || '—')}</td>
                <td style="font-size:.76rem;">${esc(i.grade_level || '—')}</td>
                <td style="font-size:.8rem;text-align:center;font-weight:600;">${parseInt(i.quantity_received || 0)}</td>
                <td style="font-size:.8rem;text-align:center;color:${parseInt(i.quantity_damaged)>0?'var(--warning,#d97706)':'inherit'};">
                    ${parseInt(i.quantity_damaged || 0) || '—'}</td>
                <td style="font-size:.8rem;text-align:center;">${parseInt(i.quantity_missing || 0) || '—'}</td>
                <td style="font-size:.76rem;">${esc(i.notes || '—')}</td>
            </tr>`).join('');

    document.getElementById('dlvDetailPanel').style.display = 'block';
    document.getElementById('dlvDetailPanel').scrollIntoView({ behavior:'smooth', block:'nearest' });
}

function closeDlvDetail() {
    document.getElementById('dlvDetailPanel').style.display = 'none';
}

// ── Open Add Modal ────────────────────────────────────────────────────
function openDeliveryModal() {
    _dlvEditMode = false;
    _dlvRowCount = 0;
    document.getElementById('dlv_edit_id').value   = '';
    document.getElementById('dlv_date').value       = new Date().toISOString().split('T')[0];
    document.getElementById('dlv_source').value     = '';
    document.getElementById('dlv_status').value     = 'received';
    document.getElementById('dlv_po').value         = '';
    document.getElementById('dlv_ref').value        = '';
    document.getElementById('dlv_received_by').value= '';
    document.getElementById('dlv_remarks').value    = '';
    document.getElementById('dlv_isbn_input').value = '';
    document.getElementById('dlv_isbn_msg').textContent = '';
    document.getElementById('dlv_items_section').style.display = '';
    document.getElementById('dlv_edit_note').style.display     = 'none';
    document.getElementById('dlvModalTitle').innerHTML =
        '<i class="fas fa-truck-ramp-box me-2 text-primary"></i>Log New Delivery';
    document.getElementById('dlvSaveBtn').innerHTML =
        '<i class="fas fa-save me-1"></i>Save Delivery';
    document.getElementById('dlvItemsBody').innerHTML = `
        <tr id="dlv_empty_row">
            <td colspan="6" class="text-center text-muted py-3" style="font-size:.78rem;">
                No items yet — use ISBN lookup or add a row manually.
            </td>
        </tr>`;
    if (!_deliveryModal) _deliveryModal = new bootstrap.Modal(document.getElementById('deliveryModal'));
    _deliveryModal.show();
}

// ── Open Edit Modal ───────────────────────────────────────────────────
function openEditDelivery(id) {
    const d = _allDeliveries.find(x => x.id == id);
    if (!d) return;
    _dlvEditMode = true;
    document.getElementById('dlv_edit_id').value   = d.id;
    document.getElementById('dlv_date').value       = d.delivery_date || '';
    document.getElementById('dlv_source').value     = d.source || '';
    document.getElementById('dlv_status').value     = d.status || 'received';
    document.getElementById('dlv_po').value         = d.po_number || '';
    document.getElementById('dlv_ref').value        = d.ref_number || '';
    document.getElementById('dlv_received_by').value= d.received_by || '';
    document.getElementById('dlv_remarks').value    = d.remarks || '';
    document.getElementById('dlv_items_section').style.display = 'none';
    document.getElementById('dlv_edit_note').style.display     = '';
    document.getElementById('dlvModalTitle').innerHTML =
        '<i class="fas fa-pencil me-2 text-primary"></i>Edit Delivery';
    document.getElementById('dlvSaveBtn').innerHTML =
        '<i class="fas fa-save me-1"></i>Update Delivery';
    if (!_deliveryModal) _deliveryModal = new bootstrap.Modal(document.getElementById('deliveryModal'));
    _deliveryModal.show();
}

// ── Delete ────────────────────────────────────────────────────────────
function deleteDelivery(id) {
    const d = _allDeliveries.find(x => x.id == id);
    if (!confirm(`Delete delivery from "${d?.source || 'this supplier'}" on ${d?.delivery_date || ''}?\nThis cannot be undone.`)) return;
    libApi('delivery_delete', { id }).then(r => {
        if (r.success) { showToast('Delivery deleted.', 'success'); loadDlvPage(); }
        else showToast(r.message || 'Error deleting delivery.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ── ISBN lookup ───────────────────────────────────────────────────────
function dlvIsbnLookup() {
    const isbn = document.getElementById('dlv_isbn_input').value.trim().replace(/[-\s]/g, '');
    const msg  = document.getElementById('dlv_isbn_msg');
    if (!isbn) return;
    const found = _dlvBooks.find(b => (b.isbn || '').replace(/[-\s]/g, '') === isbn);
    if (found) {
        dlvAddRow(found);
        document.getElementById('dlv_isbn_input').value = '';
        msg.style.color   = '#15803d';
        msg.textContent   = `✓ Found: "${found.title}" — row added.`;
        setTimeout(() => msg.textContent = '', 3000);
        return;
    }
    msg.style.color = '#b91c1c';
    msg.textContent = `ISBN "${isbn}" not found in inventory. Add the book via Inventory first, or add a row manually.`;
}

// ── Add row ───────────────────────────────────────────────────────────
function dlvAddRow(book) {
    const empty = document.getElementById('dlv_empty_row');
    if (empty) empty.remove();
    const rowId  = ++_dlvRowCount;
    const options = _dlvBooks.map(b =>
        `<option value="${b.id}" ${book && b.id == book.id ? 'selected' : ''}>${esc(b.title)}${b.isbn ? ' [' + esc(b.isbn) + ']' : ''}</option>`
    ).join('');
    const tr = document.createElement('tr');
    tr.id = 'dlv_row_' + rowId;
    tr.innerHTML = `
        <td>
            <select class="form-select form-select-sm" name="items[${rowId}][book_id]" style="font-size:.78rem;min-width:180px;">
                <option value="">— Select book —</option>${options}
            </select>
        </td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_received]" min="0" value="1" style="width:76px;"></td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_damaged]"  min="0" value="0" style="width:76px;"></td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_missing]"  min="0" value="0" style="width:76px;"></td>
        <td><input type="text"   class="form-control form-control-sm" name="items[${rowId}][notes]" placeholder="Optional" style="font-size:.78rem;"></td>
        <td style="text-align:center;vertical-align:middle;">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="dlvRemoveRow(${rowId})">
                <i class="fas fa-trash" style="font-size:.65rem;"></i>
            </button>
        </td>`;
    document.getElementById('dlvItemsBody').appendChild(tr);
}

function dlvRemoveRow(rowId) {
    const row = document.getElementById('dlv_row_' + rowId);
    if (row) row.remove();
    if (!document.querySelector('#dlvItemsBody tr[id^="dlv_row_"]')) {
        document.getElementById('dlvItemsBody').innerHTML = `
            <tr id="dlv_empty_row">
                <td colspan="6" class="text-center text-muted py-3" style="font-size:.78rem;">
                    No items yet — use ISBN lookup or add a row manually.
                </td>
            </tr>`;
    }
}

// ── Submit (add or update) ────────────────────────────────────────────
function submitDelivery() {
    const date       = document.getElementById('dlv_date').value.trim();
    const source     = document.getElementById('dlv_source').value.trim();
    const editId     = document.getElementById('dlv_edit_id').value;
    if (!date)   { showToast('Please enter a delivery date.', 'error'); return; }
    if (!source) { showToast('Please enter the delivery source.', 'error'); return; }

    const payload = {
        delivery_date: date,
        source,
        status:      document.getElementById('dlv_status').value,
        po_number:   document.getElementById('dlv_po').value.trim(),
        ref_number:  document.getElementById('dlv_ref').value.trim(),
        received_by: document.getElementById('dlv_received_by').value.trim(),
        remarks:     document.getElementById('dlv_remarks').value.trim(),
    };

    if (_dlvEditMode && editId) {
        payload.id = editId;
        libApi('delivery_update', payload).then(r => {
            if (r.success) { showToast('Delivery updated!', 'success'); if (_deliveryModal) _deliveryModal.hide(); loadDlvPage(); }
            else showToast(r.message || 'Error updating delivery.', 'error');
        }).catch(() => showToast('Network error.', 'error'));
        return;
    }

    // New delivery — collect items
    const rows = document.querySelectorAll('#dlvItemsBody tr[id^="dlv_row_"]');
    if (!rows.length) { showToast('Add at least one book item.', 'error'); return; }

    const items = [];
    let valid = true;
    rows.forEach(tr => {
        const bookSel  = tr.querySelector('select');
        const qtyInputs = tr.querySelectorAll('input[type=number]');
        if (!bookSel || !bookSel.value) { valid = false; return; }
        items.push({
            book_id:           bookSel.value,
            quantity_received: qtyInputs[0]?.value || 0,
            quantity_damaged:  qtyInputs[1]?.value || 0,
            quantity_missing:  qtyInputs[2]?.value || 0,
            notes:             tr.querySelector('input[type=text]')?.value || '',
        });
    });
    if (!valid) { showToast('Please select a book for every row.', 'error'); return; }

    payload.items = items;
    libApi('delivery_add', payload).then(r => {
        if (r.success) { showToast('Delivery logged!', 'success'); if (_deliveryModal) _deliveryModal.hide(); loadDlvPage(); }
        else showToast(r.message || 'Error saving delivery.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
}

// ── Helpers ───────────────────────────────────────────────────────────
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Tab init ──────────────────────────────────────────────────────────
document.addEventListener('tabChanged', e => { if (e.detail === 'delivery-log') loadDlvPage(); });
window.addEventListener('load', () => {
    if (document.getElementById('delivery-log')?.classList.contains('active')) loadDlvPage();
});
</script>
