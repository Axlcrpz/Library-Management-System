<?php // templates/audit_logs.php — Audit Trail ?>

<div class="page-header">
    <div>
        <h1 class="page-title">Audit Logs</h1>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline-secondary btn-sm" onclick="loadAuditLogs()">
            <i class="fas fa-rotate-right me-1"></i>Refresh
        </button>
    </div>
</div>

<!-- Filter Bar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <div class="input-group input-group-sm" style="width:220px;">
        <span class="input-group-text"><i class="fas fa-search" style="font-size:.7rem;"></i></span>
        <input type="text" id="auditSearch" class="form-control" placeholder="Action, description, user…" oninput="filterAuditLogs()">
    </div>
    <select id="auditModuleFilter" class="form-select form-select-sm" style="width:160px;" onchange="filterAuditLogs()">
        <option value="">All Modules</option>
        <option value="books">Books</option>
        <option value="borrowing">Borrowing</option>
        <option value="delivery-log">Delivery</option>
        <option value="settings">Settings</option>
        <option value="members">Members</option>
    </select>
    <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('auditSearch').value='';document.getElementById('auditModuleFilter').value='';filterAuditLogs()">
        <i class="fas fa-xmark me-1"></i>Clear
    </button>
</div>

<!-- Logs Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="font-size:.78rem;width:150px;">Timestamp</th>
                        <th style="font-size:.78rem;">User</th>
                        <th style="font-size:.78rem;">Action</th>
                        <th style="font-size:.78rem;">Module</th>
                        <th style="font-size:.78rem;">Description</th>
                        <th style="font-size:.78rem;width:110px;">IP Address</th>
                    </tr>
                </thead>
                <tbody id="auditTableBody">
                    <tr><td colspan="6" class="text-center text-muted py-5" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading audit logs…
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let allAuditLogs = [];

async function loadAuditLogs() {
    const module = document.getElementById('auditModuleFilter')?.value || '';
    const url = 'api/library_handler.php?action=audit_logs_get&limit=200' + (module ? `&module=${encodeURIComponent(module)}` : '');
    const body = await fetch(url, { credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ success: false }));
    if (!body.success) return;
    allAuditLogs = body.data || [];
    renderAuditTable(allAuditLogs);
}

function filterAuditLogs() {
    const q      = (document.getElementById('auditSearch')?.value || '').toLowerCase().trim();
    const module = document.getElementById('auditModuleFilter')?.value || '';
    let filtered = [...allAuditLogs];
    if (q)      filtered = filtered.filter(l => [l.action, l.description, l.user_name, l.module].some(v => String(v||'').toLowerCase().includes(q)));
    if (module) filtered = filtered.filter(l => l.module === module);
    renderAuditTable(filtered);
}

function renderAuditTable(rows) {
    const tbody = document.getElementById('auditTableBody');
    if (!tbody) return;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtDT = v => { if (!v) return '—'; const d = new Date(String(v).replace(' ','T')); return isNaN(d)?v:d.toLocaleString('en-PH',{year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'}); };

    const moduleColors = {
        books:'var(--primary)', borrowing:'var(--success)', 'delivery-log':'var(--info)',
        settings:'var(--purple)', members:'var(--warning)'
    };
    const actionIcons = {
        book_added:'fa-book-medical', book_deleted:'fa-trash', borrow_approved:'fa-circle-check',
        book_returned:'fa-rotate-left', delivery_added:'fa-truck-ramp-box', settings_updated:'fa-gear',
        borrower_updated:'fa-user-pen'
    };

    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-5" style="font-size:.82rem;"><i class="fas fa-clipboard-list me-2"></i>No audit logs found.</td></tr>';
        return;
    }

    tbody.innerHTML = rows.map(l => {
        const color = moduleColors[l.module] || 'var(--text-muted)';
        const icon  = actionIcons[l.action]  || 'fa-circle-dot';
        return `<tr>
            <td style="font-size:.76rem;color:var(--text-muted);white-space:nowrap;">${fmtDT(l.created_at)}</td>
            <td style="font-size:.78rem;font-weight:500;">${esc(l.user_name || 'System')}</td>
            <td>
                <span style="display:inline-flex;align-items:center;gap:5px;background:${color}18;color:${color};padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:700;">
                    <i class="fas ${icon}" style="font-size:.6rem;"></i>
                    ${esc(l.action.replace(/_/g,' '))}
                </span>
            </td>
            <td style="font-size:.75rem;font-weight:600;color:${color};">${esc(l.module || '—')}</td>
            <td style="font-size:.76rem;color:var(--text-muted);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(l.description||'')}">${esc(l.description || '—')}</td>
            <td style="font-size:.73rem;color:var(--text-muted);font-family:monospace;">${esc(l.ip_address || '—')}</td>
        </tr>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', loadAuditLogs);
window.loadAuditLogs   = loadAuditLogs;
window.filterAuditLogs = filterAuditLogs;
</script>
