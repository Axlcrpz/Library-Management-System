
const API = 'api/library_handler.php';
const DATE_FORMAT = new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });

let documents = [];
let trashDocuments = [];
let currentYear = new Date().getFullYear();
let currentArchiveYear = 'all';
let currentStatusFilter = 'all';
let activeDocumentId = null;
let autoShowNotificationsOnce = true;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const byId = id => document.getElementById(id);
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
const fileUrl = path => {
    const clean = String(path || '').replace(/^\.\//, '').replace(/^\.\.\//, '');
    return escapeHtml(clean);
};

function showToast(message, type = 'success') {
    const container = byId('toast-container');
    if (!container) return;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-xmark';
    const colors = {
        success: { bg: '#dcfce7', color: '#15803d', border: '#16a34a' },
        error:   { bg: '#fee2e2', color: '#b91c1c', border: '#dc2626' },
    };
    const c = colors[type] || colors.success;
    const inner = byId('toast-inner') || container;
    inner.innerHTML = `
        <div style="background:${c.bg};color:${c.color};border:1px solid ${c.border};border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:10px;font-size:.83rem;font-weight:500;box-shadow:0 8px 24px rgba(0,0,0,.12);animation:slideDown .22s ease;">
            <i class="fas ${icon}" style="font-size:1rem;flex-shrink:0;"></i>
            <span style="flex:1;">${escapeHtml(message)}</span>
            <button onclick="hideToast()" style="background:none;border:none;color:${c.color};cursor:pointer;font-size:.9rem;padding:0;line-height:1;"><i class="fas fa-times"></i></button>
        </div>`;
    container.style.display = 'block';
    setTimeout(window.hideToast, 4000);
}

window.hideToast = function () {
    const container = byId('toast-container');
    if (!container) return;
    container.innerHTML = '';
    container.style.display = 'none';
};

async function requestJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (error) {
        console.error('Invalid JSON response:', text);
        return { success: false, message: 'Invalid server response.' };
    }
}

function modal(id) {
    const element = byId(id);
    return element ? bootstrap.Modal.getOrCreateInstance(element) : null;
}

function switchTab(event) {
    event?.preventDefault?.();
    const tab = event?.currentTarget?.dataset?.tab;
    if (!tab) return;

    document.querySelectorAll('.nav-link[data-tab]').forEach(link => link.classList.toggle('active', link.dataset.tab === tab));
    document.querySelectorAll('.tab-content').forEach(section => section.classList.toggle('active', section.id === tab));

    if (tab === 'dashboard') renderDashboard();
    else if (tab === 'borrow-history') renderBorrowHistory();
    else if (tab === 'trash') loadTrash();
    else renderTable();

    const mobileMenu = byId('mobileSidebarMenu');
    if (mobileMenu?.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(mobileMenu).hide();
}

window.switchTab = switchTab;

function switchTabById(tabId) {
    const link = document.querySelector(`.nav-link[data-tab="${tabId}"]`);
    if (link) link.click();
    else {
        document.querySelectorAll('.tab-content').forEach(s => s.classList.toggle('active', s.id === tabId));
        document.querySelectorAll('.nav-link[data-tab]').forEach(l => l.classList.toggle('active', l.dataset.tab === tabId));
    }
}
window.switchTabById = switchTabById;

function init() {
    document.querySelectorAll('.nav-link[data-tab]').forEach(link => link.addEventListener('click', switchTab));
    document.querySelectorAll('.btn-open-add').forEach(button => button.addEventListener('click', openAddModal));

    byId('save-document-btn')?.addEventListener('click', submitAddForm);
    byId('save-edit-btn')?.addEventListener('click', submitEditForm);
    byId('save-borrow-btn')?.addEventListener('click', submitBorrowForm);
    byId('save-return-btn')?.addEventListener('click', submitReturnForm);
    byId('print-qr-btn')?.addEventListener('click', printCurrentQr);
    byId('print-batch-qr-btn')?.addEventListener('click', printBatchQr);
    byId('scan-qr-btn')?.addEventListener('click', () => window.openScanQrModal());
    byId('scan-qr-manual-btn')?.addEventListener('click', scanQrManual);
    byId('start-camera-qr-btn')?.addEventListener('click', startCameraQrScan);
    byId('stop-camera-qr-btn')?.addEventListener('click', stopCameraQrScan);
    byId('scanQrModal')?.addEventListener('hidden.bs.modal', stopCameraQrScan);
    byId('goto-documents-btn')?.addEventListener('click', () => {
        modal('notificationsModal')?.hide();
        document.querySelector('.nav-link[data-tab="documents"]')?.click();
    });

    ['documents', 'archive', 'trash'].forEach(scope => {
        byId(`search-button-${scope}`)?.addEventListener('click', renderTable);
        byId(`search-input-${scope}`)?.addEventListener('keyup', event => { if (event.key === 'Enter') renderTable(); });
    });
    byId('trash-delete-selected-btn')?.addEventListener('click', deleteSelectedTrash);
    byId('trash-empty-btn')?.addEventListener('click', emptyTrash);
    byId('trash-select-all')?.addEventListener('change', event => {
        document.querySelectorAll('.trash-select').forEach(input => { input.checked = event.target.checked; });
    });

    byId('yearFilterDashboard')?.addEventListener('change', onYearChange);
    byId('yearFilterDocuments')?.addEventListener('change', onYearChange);
    byId('yearFilterArchive')?.addEventListener('change', event => { currentArchiveYear = event.target.value; renderTable(); });

    document.querySelectorAll('#status-filter-group [data-status]').forEach(button => {
        button.addEventListener('click', () => {
            document.querySelectorAll('#status-filter-group [data-status]').forEach(btn => btn.classList.remove('active'));
            button.classList.add('active');
            currentStatusFilter = button.dataset.status || 'all';
            renderTable();
        });
    });

    loadDocuments();
}

function onYearChange(event) {
    currentYear = parseInt(event.target.value, 10);
    if (byId('yearFilterDashboard')) byId('yearFilterDashboard').value = currentYear;
    if (byId('yearFilterDocuments')) byId('yearFilterDocuments').value = currentYear;
    renderDashboard();
    renderTable();
}

async function loadDocuments() {
    const body = await requestJson(`${API}?action=get`);
    if (!body.success) {
        showToast(body.message || 'Unable to load documents.', 'error');
        return;
    }

    documents = (body.data || []).map(item => ({
        ...item,
        id: Number(item.id),
        year: item.year ? Number(item.year) : null,
        month: item.month ? Number(item.month) : null,
        day: item.day ? Number(item.day) : null,
        archived: item.archived === true || item.archived === 1 || item.archived === '1',
    }));

    populateYearFilters();
    renderDashboard();
    renderTable();
    const alertCount = await updateNotifications();
    if (window.isAdmin && alertCount > 0 && autoShowNotificationsOnce) {
        modal('notificationsModal')?.show();
        autoShowNotificationsOnce = false;
    }
    if (document.querySelector('.nav-link.active')?.dataset.tab === 'trash') {
        await loadTrash();
    }
}

async function loadTrash() {
    const body = await requestJson(`${API}?action=trash`);
    if (!body.success) {
        showToast(body.message || 'Unable to load trash.', 'error');
        return;
    }
    trashDocuments = (body.data || []).map(item => ({
        ...item,
        id: Number(item.id),
        year: item.year ? Number(item.year) : null,
        month: item.month ? Number(item.month) : null,
        day: item.day ? Number(item.day) : null,
        days_left: Number(item.days_left ?? 0),
        archived: item.archived === true || item.archived === 1 || item.archived === '1',
    }));
    renderTrash();
}

function populateYearFilters() {
    const years = [...new Set(documents.map(doc => doc.year || doc.upload_year).filter(Boolean))].sort((a, b) => b - a);
    if (!years.includes(currentYear)) years.unshift(currentYear);

    const options = years.map(year => `<option value="${year}" ${year === currentYear ? 'selected' : ''}>${year}</option>`).join('');
    ['yearFilterDashboard', 'yearFilterDocuments'].forEach(id => { if (byId(id)) byId(id).innerHTML = options; });

    if (byId('yearFilterArchive')) {
        byId('yearFilterArchive').innerHTML = `<option value="all">All Years</option>${options}`;
        byId('yearFilterArchive').value = currentArchiveYear;
    }
}

function documentDate(doc) {
    if (doc.year) {
        const month = String(doc.month || 1).padStart(2, '0');
        const day = String(doc.day || 1).padStart(2, '0');
        return `${doc.year}-${month}-${day}`;
    }
    return doc.upload_date ? doc.upload_date.split(' ')[0] : '';
}

function formatDate(value) {
    if (!value) return '-';
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? String(value) : DATE_FORMAT.format(date);
}

function renderDashboard() {
    const active = documents.filter(doc => !doc.archived && (doc.year || doc.upload_year) === currentYear);
    setText('stat-total', active.length);
    setText('stat-available', active.filter(doc => doc.status === 'available').length);
    setText('stat-borrowed', active.filter(doc => doc.status === 'borrowed').length);
    setText('stat-archived', documents.filter(doc => doc.archived).length);
    renderChart('completionTrendChart', ['Available', 'Borrowed'],
        [active.filter(doc => doc.status === 'available').length,
         active.filter(doc => doc.status === 'borrowed').length], 'pie');
    renderCategoryChart(active);
    loadDashboardActiveBorrows();
}

async function loadDashboardActiveBorrows() {
    if (window.isAdmin) {
        // Admin: show active borrow records
        const body = await fetch('api/library_handler.php?action=book_borrow_requests_get&scope=active', { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        const container = byId('dashboard-active-borrows');
        if (!container) return;
        const rows = (body.data || []).filter(r => r.status === 'borrowed').slice(0, 8);
        const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        if (!rows.length) {
            container.innerHTML = '<div class="text-center text-muted py-4" style="font-size:.8rem;"><i class="fas fa-check-circle me-1" style="color:var(--success);"></i>No active borrows.</div>';
            return;
        }
        container.innerHTML = rows.map(r => {
            const overdue = r.due_at && new Date(r.due_at) < new Date();
            return `<div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:8px;">
                <div style="min-width:0;">
                    <div style="font-weight:600;font-size:.8rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(r.borrower_name || '—')}</div>
                    <div style="font-size:.72rem;color:var(--text-muted);">${itemSummary(r.items)}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    ${overdue
                        ? `<span style="background:var(--danger-light);color:var(--danger);padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">Overdue</span>`
                        : `<span style="font-size:.72rem;color:var(--text-muted);">Due: ${formatDateTime(r.due_at)}</span>`}
                    <div><button class="btn btn-sm btn-success" style="font-size:.68rem;padding:2px 8px;margin-top:3px;" onclick="openBookReturnModal(${r.id})">Return</button></div>
                </div>
            </div>`;
        }).join('');
    } else {
        // User: show my borrows + calculate stats
        const body = await fetch('api/library_handler.php?action=book_borrow_requests_get&scope=mine', { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        const rows = body.data || [];
        const now = new Date();
        const threeDays = new Date(now.getTime() + 3 * 86400000);
        const active   = rows.filter(r => r.status === 'borrowed');
        const overdue  = active.filter(r => r.due_at && new Date(r.due_at) < now);
        const dueSoon  = active.filter(r => r.due_at && new Date(r.due_at) >= now && new Date(r.due_at) <= threeDays);
        const pending  = rows.filter(r => r.status === 'pending');
        setText('user-stat-borrowed', active.length);
        setText('user-stat-due', dueSoon.length);
        setText('user-stat-overdue', overdue.length);
        setText('user-stat-pending', pending.length);
        const container = byId('user-active-borrows');
        if (!container) return;
        const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        if (!active.length) {
            container.innerHTML = '<div class="text-center text-muted py-4" style="font-size:.8rem;"><i class="fas fa-book-open me-1"></i>No active borrows.</div>';
            return;
        }
        container.innerHTML = `<div class="table-responsive"><table class="table table-hover mb-0" style="font-size:.8rem;">
            <thead><tr><th>Books</th><th>Borrowed</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>${active.slice(0, 6).map(r => {
                const isOvd = r.due_at && new Date(r.due_at) < now;
                return `<tr>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${itemSummary(r.items)}</td>
                    <td style="white-space:nowrap;">${formatDateTime(r.borrowed_at)}</td>
                    <td style="white-space:nowrap;${isOvd ? 'color:var(--danger);font-weight:700;' : ''}">${formatDateTime(r.due_at)}</td>
                    <td>${isOvd ? '<span style="background:var(--danger-light);color:var(--danger);padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">Overdue</span>' : '<span style="background:var(--success-light);color:var(--success);padding:2px 8px;border-radius:99px;font-size:.68rem;font-weight:600;">Active</span>'}</td>
                </tr>`;
            }).join('')}</tbody>
        </table></div>`;
    }
}

function setText(id, value) {
    const element = byId(id);
    if (element) element.textContent = value;
}

function renderChart(canvasId, labels, data, type) {
    const canvas = byId(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;
    const key = `${canvasId}Instance`;
    if (window[key]) window[key].destroy();
    window[key] = new Chart(canvas.getContext('2d'), {
        type,
        data: { labels, datasets: [{ data }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
}

function renderCategoryChart(items) {
    const counts = items.reduce((map, doc) => {
        const type = doc.document_type || 'Uncategorized';
        map[type] = (map[type] || 0) + 1;
        return map;
    }, {});
    renderChart('categorySummaryChart', Object.keys(counts), Object.values(counts), 'doughnut');
}

function filteredDocuments() {
    const activeTab = document.querySelector('.nav-link.active')?.dataset.tab || 'documents';
    let items = [...documents];

    if (activeTab === 'archive') {
        items = items.filter(doc => doc.archived);
        if (currentArchiveYear !== 'all') items = items.filter(doc => (doc.year || doc.upload_year) === Number(currentArchiveYear));
    } else {
        items = items.filter(doc => !doc.archived && (doc.year || doc.upload_year) === currentYear);
        if (currentStatusFilter !== 'all') items = items.filter(doc => doc.status === currentStatusFilter);
    }

    const searchId = activeTab === 'archive' ? 'search-input-archive' : 'search-input-documents';
    const search = (byId(searchId)?.value || '').trim().toLowerCase();
    if (search) {
        items = items.filter(doc => [doc.title, doc.document_type, doc.section, doc.borrowed_by].some(value => String(value || '').toLowerCase().includes(search)));
    }
    return items;
}

function renderTable() {
    const activeTab = document.querySelector('.nav-link.active')?.dataset.tab || 'documents';
    if (activeTab === 'trash') {
        renderTrash();
        return;
    }
    const tbody = byId(activeTab === 'archive' ? 'activities-table-body-archive' : 'activities-table-body-documents');
    if (!tbody) return;

    const items = filteredDocuments();
    const cols = window.isAdmin ? 8 : 6;
    if (!items.length) {
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-4">No records found.</td></tr>`;
        return;
    }
    tbody.innerHTML = items.map(buildRow).join('');
}

function buildRow(doc) {
    const file = doc.file_path
        ? `<a href="${fileUrl(doc.file_path)}" target="_blank" title="${escapeHtml(doc.file_name || 'Open file')}"><i class="fas fa-file-pdf text-danger"></i></a>`
        : '<span class="text-muted">—</span>';
    const status = documentStatusBadge(doc.status);
    const actions = window.isAdmin ? `<td class="action-buttons">${actionButtons(doc)}</td>` : ``;

    return `<tr>
        <td>${escapeHtml(doc.title)}</td>
        <td>${escapeHtml(doc.document_type)}</td>
        <td>${escapeHtml(doc.section)}</td>
        <td>${escapeHtml(formatDate(documentDate(doc)))}</td>
        ${window.isAdmin ? `<td>${escapeHtml(doc.borrowed_by || '-')}</td>` : ``}
        <td>${status}</td>
        <td>${file}</td>
        ${actions}
    </tr>`;
}

function documentStatusBadge(status) {
    const map = {
        available: ['badge-available',  'Available'],
        borrowed:  ['badge-borrowed',   'Borrowed'],
        archived:  ['badge-cancelled',  'Archived'],
    };
    const [cls, label] = map[status] || ['badge-cancelled', status || 'Unknown'];
    return `<span class="badge ${cls}">${label}</span>`;
}

function actionButtons(doc) {
    if (!window.isAdmin) return '';

    const qr = `<button class="btn btn-sm btn-secondary me-1" onclick="openQrModal(${doc.id})" title="Generate QR"><i class="fas fa-qrcode"></i></button>`;
    const history = `<button class="btn btn-sm btn-dark me-1" onclick="openVersionHistory(${doc.id})" title="Version History"><i class="fas fa-history"></i></button>`;
    const borrow = doc.status === 'borrowed'
        ? `<button class="btn btn-sm btn-success me-1" onclick="openReturnModal(${doc.id})" title="Return"><i class="fas fa-undo"></i></button>`
        : `<button class="btn btn-sm btn-primary me-1" onclick="openBorrowModal(${doc.id})" title="Borrow"><i class="fas fa-hand-holding"></i></button>`;

    if (doc.archived) {
        return `${qr}${history}<button class="btn btn-sm btn-warning me-1" onclick="toggleArchive(${doc.id}, 0)" title="Restore"><i class="fas fa-box-open"></i></button><button class="btn btn-sm btn-danger" onclick="deleteItem(${doc.id})" title="Trash"><i class="fas fa-trash"></i></button>`;
    }

    return `<button class="btn btn-sm btn-info me-1" onclick="openEditModal(${doc.id})" title="Edit"><i class="fas fa-edit"></i></button>${borrow}${qr}${history}<button class="btn btn-sm btn-warning me-1" onclick="toggleArchive(${doc.id}, 1)" title="Archive"><i class="fas fa-archive"></i></button><button class="btn btn-sm btn-danger" onclick="deleteItem(${doc.id})" title="Trash"><i class="fas fa-trash"></i></button>`;
}

function renderTrash() {
    const tbody = byId('trash-table-body');
    if (!tbody) return;

    const search = (byId('search-input-trash')?.value || '').trim().toLowerCase();
    const items = search
        ? trashDocuments.filter(doc => [doc.title, doc.document_type, doc.section, doc.file_name].some(value => String(value || '').toLowerCase().includes(search)))
        : [...trashDocuments];

    if (byId('trash-select-all')) byId('trash-select-all').checked = false;

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-4">Trash is empty.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(doc => {
        const file = doc.file_path
            ? `<a href="${fileUrl(doc.file_path)}" target="_blank" title="${escapeHtml(doc.file_name || 'Open file')}"><i class="fas fa-file-pdf text-danger"></i></a>`
            : '<span class="text-muted">-</span>';
        return `<tr>
            <td><input type="checkbox" class="trash-select" value="${doc.id}"></td>
            <td>${escapeHtml(doc.title)}</td>
            <td>${escapeHtml(doc.document_type)}</td>
            <td>${escapeHtml(doc.section)}</td>
            <td>${escapeHtml(formatDate(documentDate(doc)))}</td>
            <td>${escapeHtml(formatDate(doc.deleted_at || doc.updated_at))}</td>
            <td><span class="badge bg-secondary">${escapeHtml(doc.days_left)} days</span></td>
            <td>${file}</td>
            <td class="action-buttons">
                <button class="btn btn-sm btn-warning me-1" onclick="restoreDeleted(${doc.id})" title="Restore"><i class="fas fa-box-open"></i></button>
                <button class="btn btn-sm btn-danger" onclick="permanentlyDeleteTrash([${doc.id}])" title="Delete Permanently"><i class="fas fa-trash-alt"></i></button>
            </td>
        </tr>`;
    }).join('');
}

function openAddModal() {
    byId('add-document-form')?.reset();
    const dateInput = document.querySelector('#add-document-form input[name="date"]');
    if (dateInput) dateInput.value = new Date().toISOString().slice(0, 10);
    modal('addDocumentModal')?.show();
}

window.openEditModal = function (id) {
    const doc = documents.find(item => item.id === Number(id));
    if (!doc) return;
    byId('editDocumentId').value = doc.id;
    byId('editDocumentTitle').value = doc.title || '';
    byId('editDocumentType').value = doc.document_type || '';
    byId('editSection').value = doc.section || '';
    byId('editDate').value = documentDate(doc);
    byId('editNotes').value = doc.notes || '';
    modal('editDocumentModal')?.show();
};

function qrValue(doc) {
    return JSON.stringify({ system: 'SDO Library', document_id: doc.id, title: doc.title || '' });
}

function renderQrTo(elementId, value, size = 180) {
    const container = byId(elementId);
    if (!container) return;
    container.innerHTML = '';
    if (typeof QRCode === 'undefined') {
        container.innerHTML = '<div class="text-danger small">QR library did not load. Please check your internet connection or add qrcode.min.js locally.</div>';
        return;
    }
    new QRCode(container, { text: value, width: size, height: size, correctLevel: QRCode.CorrectLevel.H });
}

window.openQrModal = function (id) {
    if (!window.isAdmin) return;
    const doc = documents.find(item => item.id === Number(id));
    if (!doc) return;
    activeDocumentId = doc.id;
    if (byId('qrDocumentTitle')) byId('qrDocumentTitle').textContent = doc.title || 'Untitled';
    const value = qrValue(doc);
    if (byId('qrValueText')) byId('qrValueText').textContent = `Document ID: ${doc.id}`;
    renderQrTo('qrCanvas', value, 180);
    modal('qrModal')?.show();
};

window.openBatchQrModal = function () {
    if (!window.isAdmin) return;
    const list = byId('batchQrList');
    if (!list) return;
    const items = documents.filter(doc => !doc.archived);
    list.innerHTML = items.length ? items.map(doc => `
        <div class="col-md-3 col-sm-6">
            <div class="border rounded p-2 text-center h-100 qr-print-item">
                <div class="fw-semibold small mb-2">${escapeHtml(doc.title)}</div>
                <div id="batch-qr-${doc.id}" class="qr-preview-box mx-auto"></div>
                <div class="small text-muted mt-2">ID: ${escapeHtml(doc.id)}</div>
            </div>
        </div>`).join('') : '<div class="text-muted">No documents available.</div>';
    items.forEach(doc => renderQrTo(`batch-qr-${doc.id}`, qrValue(doc), 120));
    modal('batchQrModal')?.show();
};

function printCurrentQr() {
    const doc = documents.find(item => item.id === Number(activeDocumentId));
    const content = byId('qrCanvas')?.innerHTML || '';
    const title = escapeHtml(doc?.title || 'Document QR');
    const win = window.open('', '_blank');
    win.document.write(`<html><head><title>Print QR</title><style>body{text-align:center;font-family:Arial;padding:30px}.qr{display:inline-block;margin:20px}</style></head><body><h3>${title}</h3><div class="qr">${content}</div><p>SDO Library</p><script>window.onload=function(){window.print();}</scr` + `ipt></body></html>`);
    win.document.close();
}

function printBatchQr() {
    const content = byId('batchQrList')?.innerHTML || '';
    const win = window.open('', '_blank');
    win.document.write(`<html><head><title>Print Batch QR</title><style>body{font-family:Arial;padding:20px}.row{display:flex;flex-wrap:wrap;gap:12px}.col-md-3{width:23%;box-sizing:border-box}.border{border:1px solid #ddd}.rounded{border-radius:8px}.p-2{padding:8px}.text-center{text-align:center}.small{font-size:12px}.qr-preview-box{display:flex;justify-content:center}</style></head><body><h3>SDO Library - Document QR Codes</h3><div class="row">${content}</div><script>window.onload=function(){window.print();}</scr` + `ipt></body></html>`);
    win.document.close();
}

let html5QrScanner = null;

function parseQrValue(value) {
    const text = String(value || '').trim();
    if (!text) return null;
    try {
        const data = JSON.parse(text);
        return Number(data.document_id || data.id || data.documentId || 0) || null;
    } catch (e) {
        const match = text.match(/document[_-]?id["'\s:=]+(\d+)/i) || text.match(/\b(\d+)\b/);
        return match ? Number(match[1]) : null;
    }
}

function showScannedDocument(doc) {
    const result = byId('scanQrResult');
    if (!result) return;
    const action = window.isAdmin
        ? (doc.status === 'borrowed'
            ? `<button type="button" class="btn btn-sm btn-success" onclick="modal('scanQrModal')?.hide(); openReturnModal(${doc.id});"><i class="fas fa-undo me-1"></i>Return</button>`
            : `<button type="button" class="btn btn-sm btn-primary" onclick="modal('scanQrModal')?.hide(); openBorrowModal(${doc.id});"><i class="fas fa-hand-holding me-1"></i>Borrow</button>`)
        : '';
    const file = doc.file_path ? `<a class="btn btn-sm btn-outline-secondary" href="${fileUrl(doc.file_path)}" target="_blank"><i class="fas fa-file-pdf me-1"></i>Open File</a>` : '';
    result.innerHTML = `
        <div class="alert alert-success text-start mb-0">
            <div class="fw-semibold mb-1">${escapeHtml(doc.title || 'Untitled')}</div>
            <div class="small text-muted mb-2">${escapeHtml(doc.document_type || '-')} • ${escapeHtml(doc.section || '-')}</div>
            <div class="small mb-2">Status: ${documentStatusBadge(doc.status)}</div>
            <div class="d-flex gap-2 flex-wrap">${file}${action}</div>
        </div>`;
}

function scanQrValue(value) {
    const id = parseQrValue(value);
    const result = byId('scanQrResult');
    if (!id) {
        if (result) result.innerHTML = '<div class="alert alert-danger mb-0">Invalid QR code.</div>';
        return;
    }
    const doc = documents.find(item => Number(item.id) === Number(id));
    if (!doc) {
        if (result) result.innerHTML = '<div class="alert alert-danger mb-0">Document not found. Please reload and try again.</div>';
        return;
    }
    showScannedDocument(doc);
}

function scanQrManual() {
    scanQrValue(byId('scanQrInput')?.value || '');
}

window.openScanQrModal = function () {
    if (byId('scanQrInput')) byId('scanQrInput').value = '';
    if (byId('scanQrResult')) byId('scanQrResult').innerHTML = '<div class="text-muted small">Scan a QR code or paste the QR value.</div>';
    modal('scanQrModal')?.show();
};

async function startCameraQrScan() {
    const result = byId('scanQrResult');
    if (typeof Html5Qrcode === 'undefined') {
        if (result) result.innerHTML = '<div class="alert alert-warning mb-0">Camera QR scanner library did not load. You can paste the QR value instead.</div>';
        return;
    }
    try {
        if (html5QrScanner) await stopCameraQrScan();
        html5QrScanner = new Html5Qrcode('scanQrReader');
        await html5QrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            decodedText => {
                if (byId('scanQrInput')) byId('scanQrInput').value = decodedText;
                scanQrValue(decodedText);
                stopCameraQrScan();
            }
        );
    } catch (error) {
        console.error(error);
        if (result) result.innerHTML = '<div class="alert alert-danger mb-0">Unable to start camera. Use localhost or HTTPS and allow camera permission.</div>';
    }
}

async function stopCameraQrScan() {
    if (!html5QrScanner) return;
    try {
        await html5QrScanner.stop();
        await html5QrScanner.clear();
    } catch (e) {}
    html5QrScanner = null;
}

async function submitAddForm() {
    await submitForm('add-document-form', 'add', 'addDocumentModal', 'Document saved successfully.');
}

async function submitEditForm() {
    await submitForm('edit-document-form', 'update', 'editDocumentModal', 'Document updated successfully.');
}

async function submitForm(formId, action, modalId, successMessage) {
    const form = byId(formId);
    if (!form) return;
    const formData = new FormData(form);
    formData.append('action', action);
    const body = await requestJson(API, { method: 'POST', body: formData, headers: { 'X-CSRF-Token': csrfToken() } });
    if (!body.success) {
        showToast(body.message || 'Request failed.', 'error');
        return;
    }
    showToast(body.message || successMessage);
    modal(modalId)?.hide();
    form.reset();
    await loadDocuments();
}

window.toggleArchive = async function (id, archive) {
    await postAction({ action: 'archive', id, archive }, archive ? 'Document archived.' : 'Document restored.');
};

window.deleteItem = async function (id) {
    if (!confirm('Move this document to trash?')) return;
    await postAction({ action: 'delete', id }, 'Document moved to trash.');
};

window.restoreDeleted = async function (id) {
    await postAction({ action: 'restore_deleted', id }, 'Document restored.');
    await loadTrash();
};

window.permanentlyDeleteTrash = async function (ids) {
    if (!ids.length) return;
    if (!confirm('Permanently delete selected document(s)? This cannot be undone.')) return;
    const data = new URLSearchParams();
    data.append('action', 'permanent_delete');
    ids.forEach(id => data.append('ids[]', id));
    await postUrlEncoded(data, 'Selected trash documents permanently deleted.');
    await loadTrash();
};

function deleteSelectedTrash() {
    const ids = [...document.querySelectorAll('.trash-select:checked')].map(input => Number(input.value));
    if (!ids.length) {
        showToast('Please select at least one trash document.', 'error');
        return;
    }
    window.permanentlyDeleteTrash(ids);
}

async function emptyTrash() {
    if (!trashDocuments.length) {
        showToast('Trash is already empty.', 'error');
        return;
    }
    if (!confirm('Permanently delete all trash documents? This cannot be undone.')) return;
    await postAction({ action: 'permanent_delete_all' }, 'All trash documents permanently deleted.');
    await loadTrash();
}

async function postAction(data, successMessage) {
    const body = await requestJson(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken() },
        body: new URLSearchParams(data),
    });
    if (!body.success) {
        showToast(body.message || 'Request failed.', 'error');
        return false;
    }
    showToast(body.message || successMessage);
    await loadDocuments();
    return true;
}

window.openBorrowModal = function (id) {
    const doc = documents.find(item => item.id === Number(id));
    if (!doc) return;
    activeDocumentId = doc.id;
    byId('borrowDocumentTitle').textContent = doc.title || '';
    byId('borrow-document-form')?.reset();
    byId('borrowedAt').value = new Date().toISOString().slice(0, 16);
    modal('borrowDocumentModal')?.show();
};

async function submitBorrowForm() {
    const form = byId('borrow-document-form');
    if (!form || !activeDocumentId) return;
    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'borrow');
    data.append('document_id', activeDocumentId);
    await postUrlEncoded(data, 'Document marked as borrowed.', 'borrowDocumentModal');
}

window.openReturnModal = function (id) {
    const doc = documents.find(item => item.id === Number(id));
    if (!doc) return;
    activeDocumentId = doc.id;
    byId('returnDocumentTitle').textContent = doc.title || '';
    byId('return-document-form')?.reset();
    modal('returnDocumentModal')?.show();
};

async function submitReturnForm() {
    const form = byId('return-document-form');
    if (!form || !activeDocumentId) return;
    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'return');
    data.append('document_id', activeDocumentId);
    await postUrlEncoded(data, 'Document returned successfully.', 'returnDocumentModal');
}

async function postUrlEncoded(data, successMessage, modalId) {
    const body = await requestJson(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken() },
        body: data,
    });
    if (!body.success) {
        showToast(body.message || 'Request failed.', 'error');
        return;
    }
    showToast(body.message || successMessage);
    modal(modalId)?.hide();
    await loadDocuments();
    if (document.querySelector('.nav-link.active')?.dataset.tab === 'borrow-history') {
        await renderBorrowHistory();
    }
}

window.openVersionHistory = async function (id) {
    const doc = documents.find(item => item.id === Number(id));
    byId('historyDocumentTitle').textContent = doc?.title || '';
    byId('versionHistoryBody').innerHTML = '<tr><td colspan="6" class="text-center text-muted">Loading…</td></tr>';
    modal('versionHistoryModal')?.show();

    const body = await requestJson(`${API}?action=history&id=${encodeURIComponent(id)}`);
    const rows = body.data || [];
    byId('versionHistoryBody').innerHTML = rows.length ? rows.map(row => `
        <tr>
            <td>${escapeHtml(row.version_no || row.version_number)}</td>
            <td>${escapeHtml(versionChangeLabel(row.change_type))}</td>
            <td>${escapeHtml(row.title)}</td>
            <td>${escapeHtml(row.file_name || '-')}</td>
            <td>${escapeHtml(row.changed_by_name || '-')}</td>
            <td>${escapeHtml(formatDate(row.changed_at))}</td>
        </tr>`).join('') : '<tr><td colspan="6" class="text-center text-muted">No version history yet.</td></tr>';
};

function versionChangeLabel(changeType) {
    const labels = {
        created: 'Initial upload',
        updated: 'Document revised',
        file_upload: 'File uploaded',
        file_removed: 'File removed',
    };
    return labels[changeType] || changeType || '-';
}

async function renderBorrowHistory() {
    const tbody = byId('borrow-history-body');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Loading…</td></tr>';
    const body = await requestJson(`${API}?action=borrow_history&active=1`);
    const rows = body.data || [];
    tbody.innerHTML = rows.length ? rows.map(row => `
        <tr>
            <td>${escapeHtml(row.document_title)}</td>
            <td>${escapeHtml(row.borrower_name)}</td>
            <td>${escapeHtml(row.borrower_contact || '-')}</td>
            <td>${escapeHtml(formatDate(row.borrowed_at))}</td>
            <td>${escapeHtml(formatDate(row.expected_return_date))}</td>
            <td>${escapeHtml(row.notes || row.return_notes || '-')}</td>
            <td><button class="btn btn-sm btn-success" onclick="openReturnModal(${row.document_id})" title="Return"><i class="fas fa-undo me-1"></i> Return</button></td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-center text-muted">No actively borrowed documents found.</td></tr>';
}

async function updateNotifications() {
    if (!window.isAdmin) return 0;
    const countEl = byId('notif-count');
    const container = byId('notifications-container');
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const body = await requestJson(`${API}?action=borrow_history&active=1`);
    const rows = body.success ? (body.data || []) : [];
    const borrowed = rows.sort((a, b) => {
        const da = a.expected_return_date ? new Date(a.expected_return_date).getTime() : Number.MAX_SAFE_INTEGER;
        const db = b.expected_return_date ? new Date(b.expected_return_date).getTime() : Number.MAX_SAFE_INTEGER;
        return da - db;
    });

    if (countEl) {
        countEl.textContent = borrowed.length;
        countEl.style.display = borrowed.length ? 'inline-block' : 'none';
    }
    if (!container) return borrowed.length;

    container.innerHTML = borrowed.length ? borrowed.map(row => {
        const dueLine = row.expected_return_date
            ? `<div class="small ${deadlineClass(row.expected_return_date, today)}">${deadlineText(row.expected_return_date, today)}</div>`
            : '<div class="small text-muted">No expected return date set.</div>';
        return `
        <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
            <div>
                <div class="fw-semibold">${escapeHtml(row.document_title || 'Untitled')}</div>
                <div class="small text-warning">Borrowed by: ${escapeHtml(row.borrower_name || '-')}</div>
                <div class="small text-muted">Contact: ${escapeHtml(row.borrower_contact || '-')}</div>
                <div class="small text-muted">Borrowed: ${escapeHtml(formatDate(row.borrowed_at))}</div>
                ${dueLine}
            </div>
            <button class="btn btn-sm btn-outline-success" onclick="openReturnModal(${row.document_id})">Return</button>
        </div>`;
    }).join('') : '<div class="text-center text-muted py-4"><i class="fas fa-bell-slash fa-2x mb-3"></i><p class="mb-0">There are no borrowed document deadlines right now.</p></div>';
    return borrowed.length;
}

function daysUntil(dateValue, today) {
    const due = new Date(dateValue);
    due.setHours(0, 0, 0, 0);
    return Math.ceil((due - today) / 86400000);
}

function deadlineText(dateValue, today) {
    const days = daysUntil(dateValue, today);
    if (days < 0) return `Past due by ${Math.abs(days)}d`;
    if (days === 0) return 'Due today';
    return `Due ${escapeHtml(formatDate(dateValue))} (${days}d left)`;
}

function deadlineClass(dateValue, today) {
    return daysUntil(dateValue, today) < 0 ? 'text-danger' : 'text-primary';
}

document.addEventListener('DOMContentLoaded', init);






let allBooks = [];
let bookBorrowRecords = [];

async function loadBooks() {
    const body = await fetch('api/library_handler.php?action=books_get', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    allBooks = body.data || [];
    renderBooksTable();
    populateSubjectFilter();
    renderBooksMainTable();
    populateSubjectFilterMain();
}

function populateSubjectFilter() {
    const select = document.getElementById('book-subject-filter');
    if (!select) return;
    const subjects = [...new Set(allBooks.map(b => b.subject).filter(Boolean))].sort();
    select.innerHTML = '<option value="">All Subjects</option>' +
        subjects.map(s => `<option value="${s}">${s}</option>`).join('');
}

function filterBooksTable() { renderBooksTable(); }
function filterBooksMainTable() { renderBooksMainTable(); }

function renderBooksTable() {
    const tbody = document.getElementById('books-table-dashboard');
    if (!tbody) return;

    const search = (document.getElementById('book-search-dashboard')?.value || '').toLowerCase().trim();
    const subject = document.getElementById('book-subject-filter')?.value || '';

    let items = [...allBooks];
    if (search) items = items.filter(b => [b.title, b.author, b.subject, b.grade_level, b.location_label].some(v => String(v || '').toLowerCase().includes(search)));
    if (subject) items = items.filter(b => b.subject === subject);

    const cols = window.isStaff ? 7 : 6;
    if (!items.length) {
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-book-open me-2"></i>No books found.</td></tr>`;
        return;
    }

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    tbody.innerHTML = items.map(b => {
        const condBadge = { good: '<span class="badge badge-good">Good</span>', fair: '<span class="badge badge-fair">Fair</span>', poor: '<span class="badge badge-poor">Poor</span>' }[b.condition_status] || '<span class="badge badge-cancelled">—</span>';
        const availColor = b.quantity_available === 0 ? 'var(--danger)' : (b.quantity_available < 10 ? 'var(--warning)' : 'var(--success)');
        const staffActions = window.isStaff ? `<td class="action-buttons">
            <button class="btn btn-sm btn-outline-secondary" onclick="openEditBookModal(${b.id})" title="Edit"><i class="fas fa-pen"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteBook(${b.id})" title="Delete"><i class="fas fa-trash"></i></button>
        </td>` : '';
        return `<tr>
            <td>
                <div style="font-weight:600;font-size:.82rem;">${esc(b.title)}</div>
                ${b.author ? `<div style="font-size:.72rem;color:var(--text-muted);">${esc(b.author)}</div>` : ''}
            </td>
            <td>${esc(b.subject || '—')}</td>
            <td>${esc(b.grade_level || '—')}</td>
            <td>${b.location_label ? `<span style="background:var(--primary-light);color:var(--primary);padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:600;"><i class="fas fa-location-dot me-1"></i>${esc(b.location_label)}</span>` : '<span style="color:var(--text-light);">—</span>'}</td>
            <td><span style="font-weight:700;color:${availColor};">${b.quantity_available}</span><span style="color:var(--text-muted);font-size:.75rem;"> / ${b.quantity_total}</span></td>
            <td>${condBadge}</td>
            ${staffActions}
        </tr>`;
    }).join('');
}

function populateSubjectFilterMain() {
    const select = document.getElementById('book-subject-filter-main');
    if (!select) return;
    const subjects = [...new Set(allBooks.map(b => b.subject).filter(Boolean))].sort();
    select.innerHTML = '<option value="">All Subjects</option>' +
        subjects.map(s => `<option value="${s}">${s}</option>`).join('');
}

function renderBooksMainTable() {
    const tbody = document.getElementById('books-table-main');
    if (!tbody) return;

    const search = (document.getElementById('book-search-main')?.value || '').toLowerCase().trim();
    const subject = document.getElementById('book-subject-filter-main')?.value || '';

    let items = [...allBooks];
    if (search) items = items.filter(b => [b.title, b.author, b.subject, b.grade_level, b.location_label].some(v => String(v || '').toLowerCase().includes(search)));
    if (subject) items = items.filter(b => b.subject === subject);

    const cols = window.isStaff ? 9 : 8;
    if (!items.length) {
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-book-open me-2"></i>No books found.</td></tr>`;
        return;
    }

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    tbody.innerHTML = items.map(b => {
        const condBadge = { good: '<span class="badge badge-good">Good</span>', fair: '<span class="badge badge-fair">Fair</span>', poor: '<span class="badge badge-poor">Poor</span>' }[b.condition_status] || '<span class="badge badge-cancelled">—</span>';
        const availColor = b.quantity_available === 0 ? 'var(--danger)' : (b.quantity_available < 10 ? 'var(--warning)' : 'var(--success)');
        const actions = window.isStaff ? `<td class="action-buttons">
            <button class="btn btn-sm btn-outline-secondary" onclick="openEditBookModal(${b.id})" title="Edit"><i class="fas fa-pen"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteBook(${b.id})" title="Delete"><i class="fas fa-trash"></i></button>
        </td>` : '';
        return `<tr>
            <td>
                <div style="font-weight:600;font-size:.82rem;">${esc(b.title)}</div>
                ${b.author ? `<div style="font-size:.72rem;color:var(--text-muted);">${esc(b.author)}</div>` : ''}
            </td>
            <td>${esc(b.author || '—')}</td>
            <td>${esc(b.subject || '—')}</td>
            <td>${esc(b.grade_level || '—')}</td>
            <td>${b.location_label ? `<span style="background:var(--primary-light);color:var(--primary);padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:600;"><i class="fas fa-location-dot me-1"></i>${esc(b.location_label)}</span>` : '<span style="color:var(--text-light);">—</span>'}</td>
            <td><span style="font-weight:700;color:${availColor};">${b.quantity_available}</span><span style="color:var(--text-muted);font-size:.75rem;"> / ${b.quantity_total}</span></td>
            <td>${condBadge}</td>
            ${actions}
        </tr>`;
    }).join('');
}

function openAddBookModal() {
    document.getElementById('bookModalTitle').textContent = 'Add New Book';
    document.getElementById('book-form').reset();
    document.getElementById('bookId').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addBookModal')).show();
}

function openEditBookModal(id) {
    const book = allBooks.find(b => b.id === Number(id));
    if (!book) return;
    document.getElementById('bookModalTitle').textContent = 'Edit Book';
    document.getElementById('bookId').value = book.id;
    document.getElementById('bookTitle').value = book.title || '';
    document.getElementById('bookAuthor').value = book.author || '';
    document.getElementById('bookSubject').value = book.subject || '';
    document.getElementById('bookCategory').value = book.category || '';
    document.getElementById('bookGradeLevel').value = book.grade_level || '';
    document.getElementById('bookIsbn').value = book.isbn || '';
    document.getElementById('bookLocation').value = book.location_label || '';
    document.getElementById('bookQtyTotal').value = book.quantity_total || 0;
    document.getElementById('bookQtyAvailable').value = book.quantity_available || 0;
    document.getElementById('bookQtyDamaged').value = book.quantity_damaged || 0;
    document.getElementById('bookQtyMissing').value = book.quantity_missing || 0;
    document.getElementById('bookCondition').value = book.condition_status || 'good';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addBookModal')).show();
}

async function submitBookForm() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const id = document.getElementById('bookId').value;
    const data = new URLSearchParams(new FormData(document.getElementById('book-form')));
    data.append('action', id ? 'books_update' : 'books_add');
    const body = await fetch('api/library_handler.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken }, body: data }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) { alert(body.message || 'Failed.'); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addBookModal')).hide();
    await loadBooks();
}

async function deleteBook(id) {
    if (!confirm('Delete this book?')) return;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new URLSearchParams({ action: 'books_delete', id });
    const body = await fetch('api/library_handler.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken }, body: data }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) { alert(body.message || 'Failed.'); return; }
    await loadBooks();
}

async function loadBookStats() {
    const body = await fetch('api/library_handler.php?action=book_stats', { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    const data = body.data || {};

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    setText('book-stat-total', data.total_qty ?? 0);
    setText('book-stat-available', data.available_qty ?? 0);
    setText('book-stat-borrowed', data.borrowed_qty ?? 0);
    setText('book-stat-overdue', data.overdue_count ?? 0);
    setText('book-stat-today', data.today_transactions ?? 0);
}

async function loadDeliveries() {
    const body = await fetch('api/library_handler.php?action=delivery_get', { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    const container = document.getElementById('deliveries-container');
    if (!container) return;
    const deliveries = body.data || [];
    if (!deliveries.length) {
        container.innerHTML = '<div class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-truck me-2"></i>No deliveries logged yet.</div>';
        return;
    }
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    container.innerHTML = deliveries.slice(0, 5).map(d => {
        const itemsHtml = (d.items || []).map(i =>
            `<div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid var(--border);font-size:.78rem;align-items:center;">
                <span style="flex:1;font-weight:500;">${esc(i.title || 'Unknown')}</span>
                <span style="background:var(--success-light);color:var(--success);padding:1px 8px;border-radius:99px;font-size:.69rem;font-weight:600;">+${i.quantity_received}</span>
                ${i.quantity_damaged > 0 ? `<span style="background:var(--warning-light);color:var(--warning);padding:1px 8px;border-radius:99px;font-size:.69rem;font-weight:600;">${i.quantity_damaged} dmg</span>` : ''}
                ${i.quantity_missing > 0 ? `<span style="background:var(--danger-light);color:var(--danger);padding:1px 8px;border-radius:99px;font-size:.69rem;font-weight:600;">${i.quantity_missing} miss</span>` : ''}
            </div>`
        ).join('');
        return `<div style="padding:14px 18px;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:${itemsHtml ? '10px' : '0'};">
                <div>
                    <div style="font-weight:600;font-size:.85rem;"><i class="fas fa-truck me-2" style="color:var(--success);"></i>${esc(d.source)}</div>
                    <div style="font-size:.74rem;color:var(--text-muted);margin-top:2px;">
                        ${esc(d.delivery_date)}${d.logged_by_name ? ' &nbsp;·&nbsp; ' + esc(d.logged_by_name) : ''}
                        ${d.remarks ? ' &nbsp;·&nbsp; <em>' + esc(d.remarks) + '</em>' : ''}
                    </div>
                </div>
                <span style="background:var(--info-light);color:var(--info);padding:2px 10px;border-radius:99px;font-size:.69rem;font-weight:600;">${(d.items||[]).length} item${(d.items||[]).length !== 1 ? 's' : ''}</span>
            </div>
            ${itemsHtml ? `<div>${itemsHtml}</div>` : ''}
        </div>`;
    }).join('');
}

async function openAddDeliveryModal() {
    document.getElementById('deliveryDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('deliverySource').value = '';
    document.getElementById('deliveryRemarks').value = '';
    document.getElementById('delivery-items-body').innerHTML = '';

    if (!allBooks.length) {
        await loadBooks();
    }

    addDeliveryItemRow();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addDeliveryModal')).show();
}

function addDeliveryItemRow() {
    const tbody = document.getElementById('delivery-items-body');
    if (!tbody) return;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const options = allBooks.map(b => `<option value="${b.id}">${esc(b.title)} (${esc(b.subject || '')} ${esc(b.grade_level || '')})</option>`).join('');
    const row = document.createElement('tr');
    row.innerHTML = `<td><select class="form-select form-select-sm delivery-book-select"><option value="">Select book...</option>${options}</select></td><td><input type="number" class="form-control form-control-sm delivery-qty-received" min="0" value="0" style="width:80px;"></td><td><input type="number" class="form-control form-control-sm delivery-qty-damaged" min="0" value="0" style="width:80px;"></td><td><input type="number" class="form-control form-control-sm delivery-qty-missing" min="0" value="0" style="width:80px;"></td><td><input type="text" class="form-control form-control-sm delivery-notes" placeholder="Optional"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(row);
}

async function submitDeliveryForm() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const source = document.getElementById('deliverySource').value.trim();
    if (!source) { alert('Delivery source is required.'); return; }
    const rows = document.querySelectorAll('#delivery-items-body tr');
    const items = [];
    rows.forEach(row => {
        const bookId = row.querySelector('.delivery-book-select')?.value;
        if (bookId) items.push({ book_id: bookId, quantity_received: row.querySelector('.delivery-qty-received')?.value || 0, quantity_damaged: row.querySelector('.delivery-qty-damaged')?.value || 0, quantity_missing: row.querySelector('.delivery-qty-missing')?.value || 0, notes: row.querySelector('.delivery-notes')?.value || '' });
    });
    if (!items.length) { alert('Add at least one book item.'); return; }
    const data = new URLSearchParams();
    data.append('action', 'delivery_add');
    data.append('delivery_date', document.getElementById('deliveryDate').value);
    data.append('source', source);
    data.append('remarks', document.getElementById('deliveryRemarks').value);
    items.forEach((item, i) => Object.entries(item).forEach(([k, v]) => data.append(`items[${i}][${k}]`, v)));
    const body = await fetch('api/library_handler.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken }, body: data }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) { alert(body.message || 'Failed.'); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addDeliveryModal')).hide();
    await loadBooks();
    await loadDeliveries();
}

function openBorrowRequestModal() {
    document.getElementById('book-borrow-request-form')?.reset();
    document.getElementById('book-borrow-items-body').innerHTML = '';
    clearSelectedBorrower();
    addBookBorrowItemRow();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookBorrowRequestModal')).show();
}

function addBookBorrowItemRow() {
    const tbody = document.getElementById('book-borrow-items-body');
    if (!tbody) return;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const options = allBooks.map(b => `<option value="${b.id}">${esc(b.title)} (${esc(b.subject || '')} ${esc(b.grade_level || '')})</option>`).join('');
    const row = document.createElement('tr');
    row.innerHTML = `<td><select class="form-select form-select-sm borrow-book-select"><option value="">Select book...</option>${options}</select></td><td><input type="number" class="form-control form-control-sm borrow-qty" min="1" value="1" style="width:100px;"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>`;
    tbody.appendChild(row);
}

async function submitBookBorrowRequestForm() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const form = document.getElementById('book-borrow-request-form');
    if (!form) return;

    const borrowerName    = document.getElementById('selectedBorrowerNameInput')?.value?.trim()
                         || form.querySelector('[name="borrower_name"]')?.value?.trim() || '';
    if (!borrowerName) {
        alert('Please search for and select a borrower first.');
        return;
    }

    const borrowDate      = form.querySelector('[name="borrow_date"]')?.value || '';
    const returnDate      = form.querySelector('[name="return_date"]')?.value || '';
    const borrowType      = form.querySelector('[name="borrow_type"]')?.value || '';
    const borrowerContact = document.getElementById('selectedBorrowerContactInput')?.value?.trim()
                         || form.querySelector('[name="borrower_contact"]')?.value || '';

    if (!borrowDate || !returnDate) {
        alert('Please select both a Borrow Date and Return Date.');
        return;
    }
    if (new Date(returnDate) <= new Date(borrowDate)) {
        alert('Return Date must be after Borrow Date.');
        return;
    }

    // Collect book rows
    const rows = document.querySelectorAll('#book-borrow-items-body tr');
    const cleanItems = [];
    rows.forEach(row => {
        const bookId = row.querySelector('.borrow-book-select')?.value;
        const qty    = parseInt(row.querySelector('.borrow-qty')?.value || '1', 10);
        if (bookId && parseInt(bookId) > 0) {
            cleanItems.push({ book_id: bookId, quantity: Math.max(1, qty) });
        }
    });

    if (!cleanItems.length) {
        alert('Please select at least one valid book.');
        return;
    }

    const data = new URLSearchParams();
    data.append('action',           'book_borrow_request_add');
    data.append('borrower_name',    borrowerName);
    data.append('borrower_contact', borrowerContact);
    data.append('borrow_type',      borrowType);
    data.append('borrow_date',      borrowDate);
    data.append('return_date',      returnDate);

    cleanItems.forEach((item, i) => {
        data.append(`items[${i}][book_id]`,  item.book_id);
        data.append(`items[${i}][quantity]`, item.quantity);
    });

    const body = await fetch('api/library_handler.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: data
    }).then(r => r.json()).catch(() => ({ success: false }));

    if (!body.success) {
        alert(body.message || 'Failed to submit borrow request.');
        return;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookBorrowRequestModal')).hide();
    await loadBookBorrowRequests();
}

async function loadBookBorrowRequests() {
    const filter = document.getElementById('book-borrow-status-filter')?.value || 'active';
    const statusMap = { active: 'borrowed', pending: 'pending', returned: 'returned', rejected: 'rejected', cancelled: 'cancelled' };
    const status = filter === 'all' ? 'all' : (statusMap[filter] || 'borrowed');
    const scope = window.isStaff ? 'all' : 'mine';

    const body = await fetch(`api/library_handler.php?action=book_borrow_requests_get&status=${encodeURIComponent(status)}&scope=${encodeURIComponent(scope)}`, { credentials: 'same-origin' })
        .then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    bookBorrowRecords = body.data || [];

    if (window.isStaff) {
        const pending = await fetch('api/library_handler.php?action=book_borrow_requests_get&scope=pending', { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        renderBookBorrowPending(pending.success ? (pending.data || []) : []);
    }

    renderBookBorrowRecords(bookBorrowRecords);
}

function itemSummary(items) {
    if (!Array.isArray(items) || !items.length) return '-';
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    return items.map(i => `${esc(i.title || 'Book')} (${i.quantity})`).join(', ');
}

function statusBadge(status) {
    const map = {
        pending:   ['badge-pending',   'Pending'],
        borrowed:  ['badge-borrowed',  'Borrowed'],
        returned:  ['badge-returned',  'Returned'],
        rejected:  ['badge-rejected',  'Rejected'],
        cancelled: ['badge-cancelled', 'Cancelled'],
    };
    const [cls, label] = map[status] || ['badge-cancelled', status || 'Unknown'];
    return `<span class="badge ${cls}">${label}</span>`;
}

function formatDateTime(value) {
    if (!value) return '-';
    const d = new Date(String(value).replace(' ', 'T'));
    if (isNaN(d)) return String(value);
    return new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }).format(d);
}

function isOverdue(record) {
    if (!record?.due_at || record?.status !== 'borrowed') return false;
    const due = new Date(String(record.due_at).replace(' ', 'T'));
    return !isNaN(due) && due.getTime() < Date.now();
}

function renderBookBorrowPending(rows) {
    const tbody = document.getElementById('book-borrow-pending-body');
    if (!tbody) return;
    const badge = document.getElementById('pending-count-badge');
    if (badge) { badge.textContent = rows.length; badge.style.display = rows.length ? 'inline-block' : 'none'; }
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4" style="font-size:.82rem;"><i class="fas fa-check-circle me-2" style="color:var(--success);"></i>No pending requests.</td></tr>';
        return;
    }
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    tbody.innerHTML = rows.map(r => {
        const hrs = r.time_allowed_minutes ? `${(r.time_allowed_minutes/60).toFixed(1)} hrs` : '—';
        const typeLabel = r.borrow_type === 'inside' ? '<span style="background:var(--info-light);color:var(--info);padding:1px 8px;border-radius:99px;font-size:.7rem;font-weight:600;">Inside</span>' : '<span style="background:var(--purple-light);color:var(--purple);padding:1px 8px;border-radius:99px;font-size:.7rem;font-weight:600;">Outside</span>';
        return `<tr>
            <td><div style="font-weight:600;font-size:.82rem;">${esc(r.borrower_name || '—')}</div><div style="font-size:.72rem;color:var(--text-muted);">${esc(r.borrower_contact || '')}</div></td>
            <td>${typeLabel}</td>
            <td>${hrs}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${itemSummary(r.items)}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${formatDateTime(r.requested_at)}</td>
            <td class="action-buttons">
                <button class="btn btn-sm btn-success" onclick="approveBookBorrow(${r.id})" title="Approve"><i class="fas fa-check"></i></button>
                <button class="btn btn-sm btn-danger" onclick="rejectBookBorrow(${r.id})" title="Reject"><i class="fas fa-times"></i></button>
            </td>
        </tr>`;
    }).join('');
}

function renderBookBorrowRecords(rows) {
    const tbody = document.getElementById('book-borrow-records-body');
    if (!tbody) return;

    const baseCols = window.isStaff ? 8 : 7;
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${baseCols}" class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-list-check me-2"></i>No records found.</td></tr>`;
        return;
    }

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    tbody.innerHTML = rows.map(r => {
        const overdue = isOverdue(r);
        const dueCell = overdue
            ? `<span style="color:var(--danger);font-weight:700;">${formatDateTime(r.due_at)} <i class="fas fa-triangle-exclamation ms-1"></i></span>`
            : `<span style="color:var(--text-muted);font-size:.78rem;">${formatDateTime(r.due_at)}</span>`;
        let action = '—';
        if (r.status === 'pending') {
            action = window.isStaff
                ? `<div class="action-buttons"><button class="btn btn-sm btn-success" onclick="approveBookBorrow(${r.id})" title="Approve"><i class="fas fa-check"></i></button><button class="btn btn-sm btn-danger" onclick="rejectBookBorrow(${r.id})" title="Reject"><i class="fas fa-times"></i></button></div>`
                : `<button class="btn btn-sm btn-outline-danger" onclick="cancelBookBorrow(${r.id})">Cancel</button>`;
        } else if (r.status === 'borrowed' && window.isStaff) {
            action = `<button class="btn btn-sm btn-success" onclick="openBookReturnModal(${r.id})"><i class="fas fa-rotate-left me-1"></i>Return</button>`;
        }

        return `<tr ${overdue ? 'style="background:rgba(220,38,38,.03);"' : ''}>
            <td>${statusBadge(r.status)}${overdue ? ' <span class="badge badge-overdue" style="font-size:.65rem;">Overdue</span>' : ''}</td>
            <td><div style="font-weight:600;font-size:.82rem;">${esc(r.borrower_name || '—')}</div><div style="font-size:.72rem;color:var(--text-muted);">${esc(r.borrower_contact || '')}</div></td>
            ${window.isStaff ? `<td style="font-size:.78rem;color:var(--text-muted);">${esc(r.requested_by_name || '—')}</td>` : ''}
            <td style="font-size:.78rem;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;">${itemSummary(r.items)}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${formatDateTime(r.borrowed_at)}</td>
            <td>${dueCell}</td>
            <td style="font-size:.78rem;color:var(--text-muted);">${formatDateTime(r.returned_at)}</td>
            <td>${action}</td>
        </tr>`;
    }).join('');
}

async function postBorrowAction(data) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const body = await fetch('api/library_handler.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: new URLSearchParams(data)
    }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) {
        alert(body.message || 'Failed.');
        return false;
    }
    return true;
}

async function approveBookBorrow(id) {
    if (!confirm('Approve this borrow request?')) return;
    const ok = await postBorrowAction({ action: 'book_borrow_approve', id });
    if (!ok) return;
    await loadBooks();
    await loadBookStats();
    await loadBookBorrowRequests();
}

async function rejectBookBorrow(id) {
    if (!confirm('Reject this borrow request?')) return;
    const ok = await postBorrowAction({ action: 'book_borrow_reject', id });
    if (!ok) return;
    await loadBookBorrowRequests();
}

async function cancelBookBorrow(id) {
    if (!confirm('Cancel this borrow request?')) return;
    const ok = await postBorrowAction({ action: 'book_borrow_cancel', id });
    if (!ok) return;
    await loadBookBorrowRequests();
}

function openBookReturnModal(id) {
    const record = bookBorrowRecords.find(r => Number(r.id) === Number(id));
    if (!record) return;
    const form = document.getElementById('book-return-form');
    if (!form) return;
    document.getElementById('bookReturnBorrowId').value = record.id;
    form.querySelector('[name="return_notes"]').value = '';
    form.querySelector('[name="fine_amount"]').value = '';
    const tbody = document.getElementById('book-return-items-body');
    if (!tbody) return;

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    tbody.innerHTML = (record.items || []).map(item => {
        const remaining = Math.max(0, Number(item.quantity || 0) - Number(item.returned_quantity || 0));
        return `<tr data-book-id="${esc(item.book_id)}">
            <td>${esc(item.title || 'Book')}</td>
            <td><input type="number" class="form-control form-control-sm return-qty" min="0" max="${remaining}" value="${remaining}" ${remaining === 0 ? 'disabled' : ''}></td>
            <td><input type="number" class="form-control form-control-sm return-damaged" min="0" value="0" ${remaining === 0 ? 'disabled' : ''}></td>
            <td><input type="number" class="form-control form-control-sm return-missing" min="0" value="0" ${remaining === 0 ? 'disabled' : ''}></td>
        </tr>`;
    }).join('');

    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookReturnModal')).show();
}

async function submitBookReturnForm() {
    const form = document.getElementById('book-return-form');
    if (!form) return;
    const id = document.getElementById('bookReturnBorrowId')?.value || '';
    if (!id) return;

    const data = new URLSearchParams(new FormData(form));
    data.append('action', 'book_borrow_return');

    const rows = document.querySelectorAll('#book-return-items-body tr');
    let idx = 0;
    rows.forEach(row => {
        const bookId = row.getAttribute('data-book-id');
        if (!bookId) return;
        const qty = row.querySelector('.return-qty')?.value || 0;
        const damaged = row.querySelector('.return-damaged')?.value || 0;
        const missing = row.querySelector('.return-missing')?.value || 0;
        if (Number(qty) <= 0) return;
        data.append(`items[${idx}][book_id]`, bookId);
        data.append(`items[${idx}][returned_quantity]`, qty);
        data.append(`items[${idx}][returned_damaged]`, damaged);
        data.append(`items[${idx}][returned_missing]`, missing);
        idx += 1;
    });
    if (idx === 0) {
        alert('Nothing to return.');
        return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const body = await fetch('api/library_handler.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: data
    }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) {
        alert(body.message || 'Failed.');
        return;
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookReturnModal')).hide();
    await loadBooks();
    await loadBookStats();
    await loadBookBorrowRequests();
}

async function loadAnnouncements() {
    const body = await fetch('api/library_handler.php?action=announcements_get', { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    const container = document.getElementById('announcements-container');
    if (!container) return;
    const announcements = body.data || [];
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = v => { if (!v) return '-'; const d = new Date(String(v).replace(' ', 'T')); return isNaN(d) ? v : new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: '2-digit' }).format(d); };
    if (!announcements.length) {
        container.innerHTML = '<div class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-bullhorn me-2"></i>No announcements yet.</div>';
        return;
    }
    container.innerHTML = announcements.map(a => `
        <div style="padding:14px 18px;border-bottom:1px solid var(--border);transition:background .15s;">
            <div style="display:flex;justify-content:space-between;align-items:start;gap:12px;">
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:.85rem;margin-bottom:3px;">
                        ${esc(a.title)}
                        ${a.is_active == 0 ? '<span style="background:#f3f4f6;color:#6b7280;padding:1px 8px;border-radius:99px;font-size:.68rem;font-weight:600;margin-left:6px;">Draft</span>' : ''}
                    </div>
                    <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:5px;line-height:1.5;">${esc(a.body)}</div>
                    <div style="font-size:.72rem;color:var(--text-light);">
                        <i class="fas fa-user me-1"></i>${esc(a.posted_by_name || 'Admin')}
                        &nbsp;·&nbsp;
                        <i class="fas fa-clock me-1"></i>${fmt(a.created_at)}
                    </div>
                </div>
                ${window.isStaff ? `<button style="background:none;border:1px solid var(--border);color:var(--text-muted);width:28px;height:28px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0;" onclick="deleteAnnouncement(${a.id})" title="Delete"><i class="fas fa-trash"></i></button>` : ''}
            </div>
        </div>`).join('');
}

function openAddAnnouncementModal() {
    document.getElementById('announcementTitle').value = '';
    document.getElementById('announcementBody').value = '';
    document.getElementById('announcementStatus').value = '1';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addAnnouncementModal')).show();
}

async function submitAnnouncementForm() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const title = document.getElementById('announcementTitle').value.trim();
    const body_text = document.getElementById('announcementBody').value.trim();
    const is_active = document.getElementById('announcementStatus').value;
    if (!title || !body_text) { alert('Title and message are required.'); return; }
    const data = new URLSearchParams({ action: 'announcements_add', title, body: body_text, is_active });
    const body = await fetch('api/library_handler.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken }, body: data }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) { alert(body.message || 'Failed.'); return; }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('addAnnouncementModal')).hide();
    await loadAnnouncements();
}

async function deleteAnnouncement(id) {
    if (!confirm('Delete this announcement?')) return;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new URLSearchParams({ action: 'announcements_delete', id });
    const body = await fetch('api/library_handler.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken }, body: data }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) { alert(body.message || 'Failed.'); return; }
    await loadAnnouncements();
}

window.openAddBookModal = openAddBookModal;
window.openEditBookModal = openEditBookModal;
window.deleteBook = deleteBook;
window.openAddDeliveryModal = openAddDeliveryModal;
window.addDeliveryItemRow = addDeliveryItemRow;
window.openAddAnnouncementModal = openAddAnnouncementModal;
window.deleteAnnouncement = deleteAnnouncement;
window.filterBooksTable = filterBooksTable;
window.filterBooksMainTable = filterBooksMainTable;
window.openBorrowRequestModal = openBorrowRequestModal;
window.addBookBorrowItemRow = addBookBorrowItemRow;
window.loadBookBorrowRequests = loadBookBorrowRequests;
window.openBookReturnModal = openBookReturnModal;
window.approveBookBorrow = approveBookBorrow;
window.rejectBookBorrow = rejectBookBorrow;
window.cancelBookBorrow = cancelBookBorrow;

// ── BORROWER SEARCH ──────────────────────────────

let selectedBorrower = null;
let borrowerSearchTimeout = null;

function searchBorrowers() {
    clearTimeout(borrowerSearchTimeout);
    borrowerSearchTimeout = setTimeout(async () => {
        const query = document.getElementById('borrowerSearchInput')?.value.trim() || '';
        const type = document.getElementById('borrowerTypeFilter')?.value || '';
        const results = document.getElementById('borrowerSearchResults');
        if (!results) return;

        if (!query && !type) {
            results.style.display = 'none';
            results.innerHTML = '';
            return;
        }

        const params = new URLSearchParams({ action: 'borrowers_search' });
        if (query) params.append('q', query);
        if (type) params.append('type', type);

        const body = await fetch(`api/library_handler.php?${params}`, { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));

        if (!body.success || !body.data.length) {
            results.style.display = 'block';
            results.innerHTML = '<div class="list-group-item text-muted small">No borrowers found. Register a new one above.</div>';
            return;
        }

        const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        results.style.display = 'block';
        results.innerHTML = body.data.map(b => `
            <button type="button" class="list-group-item list-group-item-action py-2" onclick='selectBorrower(${JSON.stringify(b)})'>
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="fw-semibold">${esc(b.name)}</span>
                        <span class="badge ${b.borrower_type === 'school' ? 'bg-info' : 'bg-secondary'} ms-2">${esc(b.borrower_type)}</span>
                        ${b.lrn ? `<span class="text-muted small ms-2">LRN: ${esc(b.lrn)}</span>` : ''}
                    </div>
                    ${b.contact_person ? `<small class="text-muted">${esc(b.contact_person)}</small>` : ''}
                </div>
            </button>`).join('');
    }, 300);
}

function selectBorrower(borrower) {
    selectedBorrower = borrower;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    document.getElementById('selectedBorrowerName').textContent = borrower.name;
    document.getElementById('selectedBorrowerType').textContent = borrower.borrower_type;
    document.getElementById('selectedBorrowerLrn').textContent = borrower.lrn ? `LRN: ${borrower.lrn}` : '';
    document.getElementById('selectedBorrowerContact').textContent = borrower.contact ? `📞 ${borrower.contact}` : '';
    document.getElementById('selectedBorrowerId').value = borrower.id;
    document.getElementById('selectedBorrowerNameInput').value = borrower.name;
    document.getElementById('selectedBorrowerContactInput').value = borrower.contact || '';

    document.getElementById('borrowerSearchResults').style.display = 'none';
    document.getElementById('selectedBorrowerDisplay').style.display = 'block';
    document.getElementById('registerBorrowerForm').style.display = 'none';
}

function clearSelectedBorrower() {
    selectedBorrower = null;
    document.getElementById('selectedBorrowerId').value = '';
    document.getElementById('selectedBorrowerNameInput').value = '';
    document.getElementById('selectedBorrowerContactInput').value = '';
    document.getElementById('selectedBorrowerDisplay').style.display = 'none';
    document.getElementById('borrowerSearchInput').value = '';
    document.getElementById('borrowerSearchResults').style.display = 'none';
    document.getElementById('borrowerSearchResults').innerHTML = '';
}

function showRegisterBorrowerForm() {
    document.getElementById('registerBorrowerForm').style.display = 'block';
    document.getElementById('newBorrowerName').value = document.getElementById('borrowerSearchInput')?.value || '';
}

function hideRegisterBorrowerForm() {
    document.getElementById('registerBorrowerForm').style.display = 'none';
}

async function registerNewBorrower() {
    const name = document.getElementById('newBorrowerName').value.trim();
    const type = document.getElementById('newBorrowerType').value;
    const lrn = document.getElementById('newBorrowerLrn').value.trim();
    const contact = document.getElementById('newBorrowerContact').value.trim();
    const contactPerson = document.getElementById('newBorrowerContactPerson').value.trim();

    if (!name) { alert('Name is required.'); return; }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const data = new URLSearchParams({
        action: 'borrowers_add',
        name, borrower_type: type,
        lrn, contact, contact_person: contactPerson
    });

    const body = await fetch('api/library_handler.php', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfToken },
        body: data
    }).then(r => r.json()).catch(() => ({ success: false }));

    if (!body.success) { alert(body.message || 'Failed to register borrower.'); return; }

    selectBorrower({
        id: body.data.id,
        name: body.data.name,
        borrower_type: body.data.borrower_type,
        lrn, contact, contact_person: contactPerson
    });

    hideRegisterBorrowerForm();
}

window.searchBorrowers = searchBorrowers;
window.selectBorrower = selectBorrower;
window.clearSelectedBorrower = clearSelectedBorrower;
window.showRegisterBorrowerForm = showRegisterBorrowerForm;
window.hideRegisterBorrowerForm = hideRegisterBorrowerForm;
window.registerNewBorrower = registerNewBorrower;

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('save-book-btn')?.addEventListener('click', submitBookForm);
    document.getElementById('save-delivery-btn')?.addEventListener('click', submitDeliveryForm);
    document.getElementById('save-announcement-btn')?.addEventListener('click', submitAnnouncementForm);
    document.getElementById('save-book-borrow-request-btn')?.addEventListener('click', submitBookBorrowRequestForm);
    document.getElementById('save-book-return-btn')?.addEventListener('click', submitBookReturnForm);
    loadBooks();
    loadBookStats();
    loadAnnouncements();
    loadDeliveries();
    loadBookBorrowRequests();
});

// ── SIDEBAR COLLAPSE ─────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('mainSidebar');
    const btn = document.getElementById('sidebarToggle');
    const STORAGE_KEY = 'sdo_sidebar_collapsed';
    if (!sidebar || !btn) return;
    function applyState(collapsed) {
        sidebar.classList.toggle('collapsed', collapsed);
        btn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }
    applyState(localStorage.getItem(STORAGE_KEY) === 'true');
    btn.addEventListener('click', function () {
        const isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem(STORAGE_KEY, String(!isCollapsed));
        applyState(!isCollapsed);
    });
});