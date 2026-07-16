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
        <!-- Book summary chips -->
        <div id="dlvDetailSummary" style="padding:8px 16px;border-bottom:1px solid #e5e7eb;display:flex;flex-wrap:wrap;gap:8px;"></div>
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
        <!-- Supporting documents — view / preview / download without leaving the LMS -->
        <div style="padding:12px 16px;border-top:1px solid #e5e7eb;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span style="font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;">
                    <i class="fas fa-paperclip me-1"></i>Supporting Documents
                </span>
                <?php if (!empty($isStaff)): ?>
                <button class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.72rem;" id="dlvDetailAddDocBtn">
                    <i class="fas fa-plus me-1"></i>Add Documents
                </button>
                <?php endif; ?>
            </div>
            <div id="dlvDetailDocs"><div class="text-muted" style="font-size:.78rem;">Loading…</div></div>
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

                <div style="font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:8px;">
                    <span class="badge rounded-pill bg-primary me-1" style="font-size:.66rem;">1</span> Delivery Information
                </div>
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
                        <label class="form-label fw-semibold small mb-1">Source / Supplier <span class="text-danger">*</span></label>
                        <input type="text" id="dlv_source" class="form-control form-control-sm"
                               placeholder="e.g. DepEd Central Office, Vibal Group Inc.">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small mb-1">Delivered By</label>
                        <input type="text" id="dlv_delivered_by" class="form-control form-control-sm" placeholder="Courier / delivery personnel">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small mb-1">Received By</label>
                        <input type="text" id="dlv_received_by" class="form-control form-control-sm" placeholder="Name of receiving officer">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small mb-1">PO Number</label>
                        <input type="text" id="dlv_po" class="form-control form-control-sm" placeholder="PO-XXXX">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small mb-1">Ref Number</label>
                        <input type="text" id="dlv_ref" class="form-control form-control-sm" placeholder="Ref-XXXX">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small mb-1">Remarks</label>
                        <textarea id="dlv_remarks" class="form-control form-control-sm" rows="2"
                                  placeholder="Optional delivery notes, conditions, discrepancies…"></textarea>
                    </div>
                </div>

                <!-- Supporting documents — the recommended, low-typing workflow: attach the
                     DR / PO / inventory list instead of retyping it. Files are stored per
                     document type, so a future OCR / spreadsheet-import step can pick the
                     right file without another redesign. -->
                <div id="dlv_docs_section">
                    <div style="font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:8px;">
                        <span class="badge rounded-pill bg-primary me-1" style="font-size:.66rem;">2</span> Supporting Documents
                        <span style="text-transform:none;letter-spacing:0;font-weight:500;">(recommended — DR, PO, inventory list…)</span>
                    </div>
                    <div class="row g-2 mb-1">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Document Type</label>
                            <select id="dlv_doc_label" class="form-select form-select-sm">
                                <option value="Delivery Receipt (DR)">Delivery Receipt (DR)</option>
                                <option value="Purchase Order">Purchase Order</option>
                                <option value="Inventory List">Inventory List</option>
                                <option value="Packing List">Packing List</option>
                                <option value="Memorandum">Memorandum</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small mb-1">Attach File(s)</label>
                            <input type="file" id="dlv_doc_files" class="form-control form-control-sm" multiple
                                   accept=".pdf,.xlsx,.xls,.csv,.docx,.doc,.jpg,.jpeg,.png,.gif,.webp,.zip"
                                   onchange="dlvShowChosenFiles()">
                            <div class="form-text" style="font-size:.7rem;">PDF, Excel, Word, Images, ZIP · Max 20 MB each · you can select several files at once</div>
                        </div>
                    </div>
                    <div id="dlv_doc_chips" class="d-flex flex-wrap gap-1 mb-3"></div>
                </div>

                <hr class="my-2">
                <!-- Items section — hidden when editing header only -->
                <div id="dlv_items_section">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span style="font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;">
                            <span class="badge rounded-pill bg-primary me-1" style="font-size:.66rem;">3</span> Book Items
                        </span>
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
                        onclick="showDlvDetail(${d.id})" title="View delivery details">
                    <i class="fas fa-eye"></i> View
                </button>
                <button class="btn btn-xs ${d.docs_count > 0 ? 'btn-info text-white' : 'btn-outline-info'} btn-sm py-0 px-2" style="font-size:.68rem;"
                        onclick="openDeliveryDocs(${d.id},'${esc(d.source)}')" title="${d.docs_count > 0 ? d.docs_count + ' document(s) attached' : 'Attach documents'}">
                    <i class="fas fa-paperclip"></i>${d.docs_count > 0 ? ' ' + d.docs_count : ''}
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

// ── Detail panel — the delivery's complete record: info, summary, books, docs ──
function showDlvDetail(id) {
    const d = _allDeliveries.find(x => x.id == id);
    if (!d) return;

    const badge = STATUS_BADGES[d.status || 'received'] || '';
    const dlvNo = 'DEL-' + String(d.id).padStart(5, '0');
    document.getElementById('dlvDetailTitle').innerHTML =
        `<i class="fas fa-truck-ramp-box me-1 text-primary"></i>
         ${dlvNo} — ${esc(d.delivery_date)} · ${esc(d.source)} &nbsp;${badge}`;

    const infoItems = [
        `<span><strong>Delivery No.:</strong> ${dlvNo}</span>`,
        d.delivered_by && `<span><strong>Delivered By:</strong> ${esc(d.delivered_by)}</span>`,
        d.received_by  && `<span><strong>Received By:</strong> ${esc(d.received_by)}</span>`,
        d.po_number    && `<span><strong>PO #:</strong> ${esc(d.po_number)}</span>`,
        d.ref_number   && `<span><strong>Ref #:</strong> ${esc(d.ref_number)}</span>`,
        d.remarks      && `<span><strong>Remarks:</strong> ${esc(d.remarks)}</span>`,
        `<span><strong>Logged By:</strong> ${esc(d.logged_by_name || '—')}</span>`,
    ].filter(Boolean);
    document.getElementById('dlvDetailInfo').innerHTML = infoItems.join('');

    // Book summary chips
    const items = d.items || [];
    const totRcv  = items.reduce((s, i) => s + parseInt(i.quantity_received || 0), 0);
    const totDmg  = items.reduce((s, i) => s + parseInt(i.quantity_damaged  || 0), 0);
    const totMiss = items.reduce((s, i) => s + parseInt(i.quantity_missing  || 0), 0);
    const chip = (label, val, bg, fg) =>
        `<span style="background:${bg};color:${fg};padding:3px 12px;border-radius:99px;font-size:.72rem;font-weight:600;">${val} ${label}</span>`;
    document.getElementById('dlvDetailSummary').innerHTML =
        chip('title' + (items.length === 1 ? '' : 's'), items.length, '#e0e7ff', '#3730a3') +
        chip('copies received', totRcv, '#d1fae5', '#065f46') +
        (totDmg  ? chip('damaged', totDmg,  '#fef3c7', '#92400e') : '') +
        (totMiss ? chip('missing', totMiss, '#fee2e2', '#991b1b') : '');

    const tbody = document.getElementById('dlvDetailBody');
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

    // Documents — loaded live so the list is always current
    const addBtn = document.getElementById('dlvDetailAddDocBtn');
    if (addBtn) addBtn.onclick = () => openDeliveryDocs(d.id, d.source);
    dlvRefreshDetailDocs(d.id);

    document.getElementById('dlvDetailPanel').style.display = 'block';
    document.getElementById('dlvDetailPanel').scrollIntoView({ behavior:'smooth', block:'nearest' });
}

let _dlvDetailId = null;

// Renders the supporting-documents list inside the detail panel with
// per-file Preview (PDF & images open inline in a new tab) and Download.
// Exposed globally so the attach modal can refresh it after uploads/deletes.
window.dlvRefreshDetailDocs = function (deliveryId) {
    _dlvDetailId = deliveryId;
    const host = document.getElementById('dlvDetailDocs');
    if (!host) return;
    fetch(`api/library_handler.php?action=delivery_get_docs&delivery_id=${deliveryId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(body => {
            if (deliveryId !== _dlvDetailId) return;   // user opened another delivery meanwhile
            const docs = body.success ? (body.data || []) : [];
            if (!docs.length) {
                host.innerHTML = '<div class="text-muted" style="font-size:.78rem;"><i class="fas fa-folder-open me-1"></i>No documents attached to this delivery.</div>';
                return;
            }
            const iconMap = { pdf:'fa-file-pdf text-danger', xlsx:'fa-file-excel text-success', xls:'fa-file-excel text-success',
                              csv:'fa-file-csv text-success', docx:'fa-file-word text-primary', doc:'fa-file-word text-primary',
                              jpg:'fa-file-image text-warning', jpeg:'fa-file-image text-warning', png:'fa-file-image text-warning',
                              gif:'fa-file-image text-warning', webp:'fa-file-image text-warning', zip:'fa-file-zipper text-secondary' };
            const previewable = ['pdf','jpg','jpeg','png','gif','webp'];
            const canManage = <?= !empty($isStaff) ? 'true' : 'false' ?>;
            host.innerHTML = '<div class="row g-2">' + docs.map(doc => {
                const icon = iconMap[doc.file_type] || 'fa-file text-secondary';
                const size = doc.file_size ? (doc.file_size / 1024 >= 1024
                    ? (doc.file_size / 1048576).toFixed(1) + ' MB'
                    : (doc.file_size / 1024).toFixed(0) + ' KB') : '';
                const ref  = encodeURIComponent(doc.file_path);
                const previewBtn = previewable.includes(doc.file_type)
                    ? `<a href="api/library_handler.php?action=file_serve&ref=${ref}" target="_blank" rel="noopener"
                          class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.7rem;" title="Open preview in a new tab">
                          <i class="fas fa-eye me-1"></i>Preview</a>`
                    : '';
                return `<div class="col-12 col-md-6 col-xl-4">
                    <div style="border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;display:flex;gap:10px;align-items:center;height:100%;">
                        <i class="fas ${icon}" style="font-size:1.5rem;flex-shrink:0;"></i>
                        <div style="flex:1;min-width:0;">
                            ${doc.label ? `<div style="font-size:.68rem;font-weight:700;color:var(--primary,#003087);text-transform:uppercase;letter-spacing:.03em;">${esc(doc.label)}</div>` : ''}
                            <div style="font-size:.78rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(doc.file_name)}">${esc(doc.file_name)}</div>
                            <div style="font-size:.68rem;color:#9ca3af;">${size}${doc.uploaded_by_name ? ' · ' + esc(doc.uploaded_by_name) : ''}</div>
                        </div>
                        <div class="d-flex flex-column gap-1" style="flex-shrink:0;">
                            ${previewBtn}
                            <a href="api/library_handler.php?action=file_serve&download=1&ref=${ref}"
                               class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.7rem;">
                               <i class="fas fa-download me-1"></i>Download</a>
                            ${canManage ? `<button class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.7rem;"
                                onclick="dlvDetailDeleteDoc(${doc.id}, ${deliveryId})"><i class="fas fa-trash"></i></button>` : ''}
                        </div>
                    </div>
                </div>`;
            }).join('') + '</div>';
        })
        .catch(() => { host.innerHTML = '<div class="text-danger" style="font-size:.78rem;">Failed to load documents.</div>'; });
};

window.dlvDetailDeleteDoc = function (docId, deliveryId) {
    if (!confirm('Remove this document from the delivery record?')) return;
    libApi('delivery_delete_doc', { id: docId }).then(r => {
        if (r.success) { showToast('Document removed.', 'success'); dlvRefreshDetailDocs(deliveryId); loadDlvPage(); }
        else showToast(r.message || 'Failed to remove document.', 'error');
    }).catch(() => showToast('Network error.', 'error'));
};

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
    document.getElementById('dlv_delivered_by').value = '';
    document.getElementById('dlv_received_by').value= '';
    document.getElementById('dlv_remarks').value    = '';
    document.getElementById('dlv_isbn_input').value = '';
    document.getElementById('dlv_isbn_msg').textContent = '';
    document.getElementById('dlv_doc_files').value  = '';
    document.getElementById('dlv_doc_chips').innerHTML = '';
    document.getElementById('dlv_docs_section').style.display  = '';
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
    document.getElementById('dlv_delivered_by').value = d.delivered_by || '';
    document.getElementById('dlv_received_by').value= d.received_by || '';
    document.getElementById('dlv_remarks').value    = d.remarks || '';
    document.getElementById('dlv_docs_section').style.display  = 'none';   // docs are managed from the details view
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

// ── Chosen-files chips (upload section feedback) ──────────────────────
function dlvShowChosenFiles() {
    const input = document.getElementById('dlv_doc_files');
    const chips = document.getElementById('dlv_doc_chips');
    if (!input || !chips) return;
    chips.innerHTML = [...input.files].map(f => {
        const mb = f.size / 1048576;
        const size = mb >= 1 ? mb.toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
        const tooBig = f.size > 20 * 1048576;
        return `<span style="background:${tooBig ? '#fee2e2' : '#eef2ff'};color:${tooBig ? '#991b1b' : '#3730a3'};padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:600;">
            <i class="fas fa-file me-1"></i>${esc(f.name)} · ${size}${tooBig ? ' — too large!' : ''}</span>`;
    }).join('');
}

// ── Add row ───────────────────────────────────────────────────────────
// values: optional prefill {book_id, quantity_received, quantity_damaged, quantity_missing, notes}
function dlvAddRow(book, values) {
    const empty = document.getElementById('dlv_empty_row');
    if (empty) empty.remove();
    const rowId  = ++_dlvRowCount;
    const selId  = values ? String(values.book_id || '') : (book ? String(book.id) : '');
    const options = _dlvBooks.map(b =>
        `<option value="${b.id}" ${String(b.id) === selId ? 'selected' : ''}>${esc(b.title)}${b.isbn ? ' [' + esc(b.isbn) + ']' : ''}</option>`
    ).join('');
    const v = values || {};
    const tr = document.createElement('tr');
    tr.id = 'dlv_row_' + rowId;
    tr.innerHTML = `
        <td>
            <select class="form-select form-select-sm" name="items[${rowId}][book_id]" style="font-size:.78rem;min-width:180px;">
                <option value="">— Select book —</option>${options}
            </select>
        </td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_received]" min="0" value="${parseInt(v.quantity_received ?? 1)}" style="width:76px;"></td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_damaged]"  min="0" value="${parseInt(v.quantity_damaged ?? 0)}" style="width:76px;"></td>
        <td><input type="number" class="form-control form-control-sm text-center" name="items[${rowId}][quantity_missing]"  min="0" value="${parseInt(v.quantity_missing ?? 0)}" style="width:76px;"></td>
        <td><input type="text"   class="form-control form-control-sm" name="items[${rowId}][notes]" placeholder="Optional" style="font-size:.78rem;" value="${esc(v.notes || '')}"></td>
        <td style="text-align:center;vertical-align:middle;white-space:nowrap;">
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="dlvDuplicateRow(${rowId})" title="Duplicate this row">
                <i class="fas fa-clone" style="font-size:.65rem;"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="dlvRemoveRow(${rowId})" title="Remove row">
                <i class="fas fa-trash" style="font-size:.65rem;"></i>
            </button>
        </td>`;
    document.getElementById('dlvItemsBody').appendChild(tr);
}

// Duplicate a row's quantities/notes into a fresh row — fast entry for
// deliveries where many titles share the same counts.
function dlvDuplicateRow(rowId) {
    const row = document.getElementById('dlv_row_' + rowId);
    if (!row) return;
    const nums = row.querySelectorAll('input[type=number]');
    dlvAddRow(null, {
        book_id:           row.querySelector('select')?.value || '',
        quantity_received: nums[0]?.value ?? 1,
        quantity_damaged:  nums[1]?.value ?? 0,
        quantity_missing:  nums[2]?.value ?? 0,
        notes:             row.querySelector('input[type=text]')?.value || '',
    });
    // Focus the new row's book selector — the one field that must change
    const rows = document.querySelectorAll('#dlvItemsBody tr[id^="dlv_row_"]');
    rows[rows.length - 1]?.querySelector('select')?.focus();
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
        status:       document.getElementById('dlv_status').value,
        po_number:    document.getElementById('dlv_po').value.trim(),
        ref_number:   document.getElementById('dlv_ref').value.trim(),
        delivered_by: document.getElementById('dlv_delivered_by').value.trim(),
        received_by:  document.getElementById('dlv_received_by').value.trim(),
        remarks:      document.getElementById('dlv_remarks').value.trim(),
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

    // Multipart submit: header fields + item rows + supporting documents in ONE
    // request, so the delivery and its paperwork are recorded together.
    const docInput = document.getElementById('dlv_doc_files');
    if ([...(docInput?.files || [])].some(f => f.size > 20 * 1048576)) {
        showToast('One of the selected files is larger than 20 MB. Remove it and try again.', 'error');
        return;
    }
    const fd = new FormData();
    fd.append('action', 'delivery_add');
    Object.entries(payload).forEach(([k, v]) => fd.append(k, v));
    items.forEach((it, i) => Object.entries(it).forEach(([k, v]) => fd.append(`items[${i}][${k}]`, v)));
    if (docInput?.files.length) {
        fd.append('doc_label', document.getElementById('dlv_doc_label')?.value || '');
        [...docInput.files].forEach(f => fd.append('files[]', f));
    }

    const saveBtn = document.getElementById('dlvSaveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving…';
    fetch('api/library_handler.php', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' },
    }).then(r => r.json()).then(r => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save Delivery';
        if (r.success) {
            showToast(r.message || 'Delivery logged!', r.message && r.message.includes('However') ? 'error' : 'success');
            if (_deliveryModal) _deliveryModal.hide();
            loadDlvPage();
        }
        else showToast(r.message || 'Error saving delivery.', 'error');
    }).catch(() => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save me-1"></i>Save Delivery';
        showToast('Network error.', 'error');
    });
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
