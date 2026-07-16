// Inventory Management — health overview + category navigation + grid/table/compact
// views with per-book status indicators and quick actions. Reads window.allBooks
// (populated by loadBooks in app.js) so it stays in sync with the rest of the app.
(function () {
    const LOW = 3;                         // low-stock threshold (available copies)
    let _cat = 'all', _view = 'grid', _sortKey = 'title', _sortDir = 'asc';

    // Sort accessors shared by the dropdown presets and the clickable table headers
    const SORT_ACCESSORS = {
        title:   b => String(b.title || '').toLowerCase(),
        author:  b => String(b.author || '').toLowerCase(),
        subject: b => String(b.subject || '').toLowerCase(),
        avail:   b => Number(b.quantity_available) || 0,
        total:   b => Number(b.quantity_total) || 0,
        recent:  b => new Date(b.created_at || 0).getTime(),
    };
    const NUMERIC_SORT = { avail: 1, total: 1, recent: 1 };
    // Dropdown preset value → [key, dir]
    const SORT_PRESETS = {
        title: ['title', 'asc'], author: ['author', 'asc'], subject: ['subject', 'asc'],
        avail_asc: ['avail', 'asc'], avail_desc: ['avail', 'desc'],
        total_desc: ['total', 'desc'], recent: ['recent', 'desc'],
    };
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const books = () => window.allBooks || [];
    const recentMs = 7 * 864e5;
    const isRecent = b => b.created_at && (Date.now() - new Date(String(b.created_at).replace(' ', 'T')).getTime()) < recentMs;

    // Category predicates (archived is its own bucket; everything else excludes archived)
    const CATS = [
        { id: 'all',          label: 'All Books',       icon: 'fa-book',                pred: b => !b.is_archived },
        { id: 'available',    label: 'Available',       icon: 'fa-circle-check',        pred: b => !b.is_archived && b.quantity_available > 0 },
        { id: 'borrowed',     label: 'Borrowed',        icon: 'fa-hand-holding',        pred: b => !b.is_archived && b.copies_on_loan > 0 },
        { id: 'reserved',     label: 'Reserved',        icon: 'fa-bookmark',            pred: b => !b.is_archived && b.copies_reserved > 0 },
        { id: 'low',          label: 'Low Stock',       icon: 'fa-triangle-exclamation',pred: b => !b.is_archived && b.quantity_available > 0 && b.quantity_available <= LOW },
        { id: 'out',          label: 'Out of Stock',    icon: 'fa-ban',                 pred: b => !b.is_archived && b.quantity_available === 0 },
        { id: 'overdue',      label: 'Overdue',         icon: 'fa-clock',               pred: b => !b.is_archived && b.has_overdue },
        { id: 'lost',         label: 'Lost',            icon: 'fa-circle-question',      pred: b => !b.is_archived && (b.quantity_missing || 0) > 0 },
        { id: 'damaged',      label: 'Damaged',         icon: 'fa-bug',                  pred: b => !b.is_archived && (b.quantity_damaged || 0) > 0 },
        { id: 'recent',       label: 'Recently Added',  icon: 'fa-sparkles',            pred: b => !b.is_archived && isRecent(b) },
        { id: 'archived',     label: 'Archived',        icon: 'fa-box-archive',         pred: b => b.is_archived },
    ];

    // Health indicator colour for a book
    function statusOf(b) {
        if (b.is_archived) return { color: 'var(--text-muted,#888)', label: 'Archived', cls: 'secondary' };
        if (b.quantity_available === 0) return { color: '#e24b4a', label: 'Out of stock', cls: 'danger' };
        if (b.has_overdue) return { color: '#ef9f27', label: 'Overdue copies', cls: 'warning' };
        if (b.quantity_available <= LOW) return { color: '#eab308', label: 'Low stock', cls: 'warning' };
        return { color: '#3b6d11', label: 'Healthy', cls: 'success' };
    }
    const coverHtml = (b, w, h) => b.cover_url
        ? `<img src="${esc(b.cover_url)}" alt="" loading="lazy" style="width:${w}px;height:${h}px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex-shrink:0;">`
        : `<div style="width:${w}px;height:${h}px;border-radius:6px;flex-shrink:0;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;">${esc((b.title || '?').slice(0, 2).toUpperCase())}</div>`;
    const isbnOf = b => b.isbn13 || b.isbn || '';

    // ── Entry point (called by loadBooks) ─────────────────────────────────────
    window.renderInventory = function () {
        // Stage 2 (opt-in, default OFF): when window.INV_SERVER_STATS is enabled, the
        // health cards + category counts come from the server aggregate endpoint, so
        // they stay accurate once the list is server-paginated. The default path below
        // is unchanged — client-side stats summed from window.allBooks.
        if (window.INV_SERVER_STATS) {
            invFetchServerStats().then(s => {
                if (s) { renderHealthFromStats(s); renderCategoriesFromStats(s); }
                else { renderHealth(); renderCategories(); }   // graceful fallback
                invApplyFilters();
            });
            return;
        }
        renderHealth();
        renderCategories();
        invApplyFilters();
    };

    // ── Stage 2 server-aggregate consumers (only used when INV_SERVER_STATS is on) ──
    async function invFetchServerStats() {
        try {
            const r = await fetch('api/library_handler.php?action=inventory_stats', { credentials: 'same-origin' }).then(r => r.json());
            return r && r.success ? r.data : null;
        } catch { return null; }
    }
    function renderHealthFromStats(s) {
        const el = document.getElementById('inventoryHealth');
        if (!el) return;
        const t = s.totals || {};
        const cards = [
            { label: 'Total Copies', val: t.copies, cat: 'all', c: 'var(--primary,#185fa5)' },
            { label: 'Titles', val: t.titles, cat: 'all', c: 'var(--primary,#185fa5)' },
            { label: 'Available', val: t.available, cat: 'available', c: '#3b6d11' },
            { label: 'Borrowed', val: t.borrowed, cat: 'borrowed', c: '#854f0b' },
            { label: 'Reserved', val: t.reserved, cat: 'reserved', c: '#185fa5' },
            { label: 'Overdue', val: t.overdue, cat: 'overdue', c: '#c2410c' },
            { label: 'Lost', val: t.lost, cat: 'lost', c: '#a32d2d' },
            { label: 'Damaged', val: t.damaged, cat: 'damaged', c: '#a32d2d' },
            { label: 'Recently Added', val: t.recent, cat: 'recent', c: '#534ab7' },
        ];
        el.innerHTML = cards.map(c => `
            <div class="col-6 col-md-4 col-lg-2" style="min-width:120px;">
                <div onclick="invSetCategory('${c.cat}')" role="button"
                     style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 12px;cursor:pointer;height:100%;"
                     onmouseover="this.style.borderColor='${c.c}'" onmouseout="this.style.borderColor='var(--border)'">
                    <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#9ca3af);">${c.label}</div>
                    <div style="font-size:1.5rem;font-weight:800;color:${c.c};line-height:1.15;">${Number(c.val || 0).toLocaleString()}</div>
                </div>
            </div>`).join('');
    }
    function renderCategoriesFromStats(s) {
        const el = document.getElementById('inventoryCategories');
        if (!el) return;
        const counts = s.categories || {};
        el.innerHTML = CATS.map(c => {
            const n = counts[c.id] || 0;
            if (c.id === 'archived' && n === 0) return '';
            const active = _cat === c.id;
            return `<button class="btn btn-sm ${active ? 'btn-primary' : 'btn-outline-secondary'}" onclick="invSetCategory('${c.id}')" style="font-size:.78rem;">
                <i class="fas ${c.icon} me-1"></i>${esc(c.label)} <span class="badge ${active ? 'bg-light text-dark' : 'bg-secondary'}" style="font-size:.62rem;">${n}</span>
            </button>`;
        }).join('');
    }

    function renderHealth() {
        const el = document.getElementById('inventoryHealth');
        if (!el) return;
        const bs = books().filter(b => !b.is_archived);
        const sum = (f) => bs.reduce((a, b) => a + (Number(f(b)) || 0), 0);
        const cards = [
            { label: 'Total Copies', val: sum(b => b.quantity_total), cat: 'all', c: 'var(--primary,#185fa5)' },
            { label: 'Titles', val: bs.length, cat: 'all', c: 'var(--primary,#185fa5)' },
            { label: 'Available', val: sum(b => b.quantity_available), cat: 'available', c: '#3b6d11' },
            { label: 'Borrowed', val: sum(b => b.copies_on_loan), cat: 'borrowed', c: '#854f0b' },
            { label: 'Reserved', val: sum(b => b.copies_reserved), cat: 'reserved', c: '#185fa5' },
            { label: 'Overdue', val: bs.filter(b => b.has_overdue).length, cat: 'overdue', c: '#c2410c' },
            { label: 'Lost', val: sum(b => b.quantity_missing), cat: 'lost', c: '#a32d2d' },
            { label: 'Damaged', val: sum(b => b.quantity_damaged), cat: 'damaged', c: '#a32d2d' },
            { label: 'Recently Added', val: bs.filter(isRecent).length, cat: 'recent', c: '#534ab7' },
        ];
        el.innerHTML = cards.map(c => `
            <div class="col-6 col-md-4 col-lg-2" style="min-width:120px;">
                <div onclick="invSetCategory('${c.cat}')" role="button"
                     style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 12px;cursor:pointer;height:100%;"
                     onmouseover="this.style.borderColor='${c.c}'" onmouseout="this.style.borderColor='var(--border)'">
                    <div style="font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#9ca3af);">${c.label}</div>
                    <div style="font-size:1.5rem;font-weight:800;color:${c.c};line-height:1.15;">${Number(c.val).toLocaleString()}</div>
                </div>
            </div>`).join('');
    }

    function renderCategories() {
        const el = document.getElementById('inventoryCategories');
        if (!el) return;
        const all = books();
        el.innerHTML = CATS.map(c => {
            const n = all.filter(c.pred).length;
            if (c.id === 'archived' && n === 0) return '';
            const active = _cat === c.id;
            return `<button class="btn btn-sm ${active ? 'btn-primary' : 'btn-outline-secondary'}" onclick="invSetCategory('${c.id}')" style="font-size:.78rem;">
                <i class="fas ${c.icon} me-1"></i>${esc(c.label)} <span class="badge ${active ? 'bg-light text-dark' : 'bg-secondary'}" style="font-size:.62rem;">${n}</span>
            </button>`;
        }).join('');
    }

    window.invSetCategory = function (cat) { _cat = cat; renderCategories(); invApplyFilters(); };
    window.invSetView = function (v) {
        _view = v;
        document.querySelectorAll('.inv-view-btn').forEach(b => b.classList.toggle('active', b.dataset.view === v));
        invApplyFilters();
    };

    function currentSet() {
        const cat = CATS.find(c => c.id === _cat) || CATS[0];
        let items = books().filter(cat.pred);
        const q = (document.getElementById('inv-search')?.value || '').toLowerCase().trim();
        if (q) items = items.filter(b => [b.title, b.author, isbnOf(b), b.subject, b.category, b.grade_level, b.location_label, String(b.id)]
            .some(v => String(v || '').toLowerCase().includes(q)));
        const get = SORT_ACCESSORS[_sortKey] || SORT_ACCESSORS.title;
        const dir = _sortDir === 'desc' ? -1 : 1;
        items.sort((a, b) => {
            const va = get(a), vb = get(b);
            if (va < vb) return -1 * dir;
            if (va > vb) return 1 * dir;
            return String(a.title || '').localeCompare(b.title || '');   // stable tiebreak
        });
        return items;
    }

    // Dropdown → state
    window.invSetSort = function (preset) {
        const p = SORT_PRESETS[preset];
        if (p) { _sortKey = p[0]; _sortDir = p[1]; }
        invApplyFilters();
    };
    // Clickable column header → state (toggles direction on repeat)
    window.invSortBy = function (key) {
        if (!SORT_ACCESSORS[key]) return;
        if (_sortKey === key) _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
        else { _sortKey = key; _sortDir = NUMERIC_SORT[key] ? 'desc' : 'asc'; }
        // Keep the dropdown in sync when the combination matches a preset
        const match = Object.keys(SORT_PRESETS).find(k => SORT_PRESETS[k][0] === _sortKey && SORT_PRESETS[k][1] === _sortDir);
        const sel = document.getElementById('inv-sort');
        if (sel && match) sel.value = match;
        invApplyFilters();
    };
    const sortArrow = key => _sortKey === key ? ` <i class="fas fa-caret-${_sortDir === 'asc' ? 'up' : 'down'}"></i>` : '';

    window.invApplyFilters = function () {
        const el = document.getElementById('inventoryContainer');
        if (!el) return;
        const items = currentSet();
        if (!items.length) {
            el.innerHTML = `<div class="text-center text-muted py-5"><i class="fas fa-book-open fa-2x mb-2 d-block"></i>No books in this view.</div>`;
            return;
        }
        el.innerHTML = _view === 'table' ? tableHtml(items) : _view === 'compact' ? compactHtml(items) : gridHtml(items);
    };

    function actionsMenu(b) {
        if (!window.isStaff) return '';
        const arch = b.is_archived
            ? `<li><a class="dropdown-item" href="#" onclick="invArchive(${b.id},0);return false;"><i class="fas fa-rotate-left fa-fw me-2"></i>Restore</a></li>`
            : `<li><a class="dropdown-item" href="#" onclick="invArchive(${b.id},1);return false;"><i class="fas fa-box-archive fa-fw me-2"></i>Archive</a></li>`;
        return `<div class="dropdown">
            <button class="btn btn-sm btn-link text-muted p-1" data-bs-toggle="dropdown" aria-label="Actions"><i class="fas fa-ellipsis-vertical"></i></button>
            <ul class="dropdown-menu dropdown-menu-end" style="font-size:.82rem;">
                <li><a class="dropdown-item" href="#" onclick="invAddCopies(${b.id});return false;"><i class="fas fa-plus fa-fw me-2"></i>Add copies</a></li>
                <li><a class="dropdown-item" href="#" onclick="openEditBookModal(${b.id});return false;"><i class="fas fa-pen fa-fw me-2"></i>Edit information</a></li>
                <li><a class="dropdown-item" href="#" onclick="switchTabById('borrowing');return false;"><i class="fas fa-clock-rotate-left fa-fw me-2"></i>Borrowing history</a></li>
                <li><a class="dropdown-item" href="#" onclick="switchTabById('reservations');return false;"><i class="fas fa-bookmark fa-fw me-2"></i>Reservation queue</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#" onclick="invMark(${b.id},'lost');return false;"><i class="fas fa-circle-question fa-fw me-2"></i>Mark as lost</a></li>
                <li><a class="dropdown-item" href="#" onclick="invMark(${b.id},'damaged');return false;"><i class="fas fa-bug fa-fw me-2"></i>Mark as damaged</a></li>
                ${arch}
                <li><a class="dropdown-item" href="#" onclick="openBookQrModal(${b.id});return false;"><i class="fas fa-qrcode fa-fw me-2"></i>QR code</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#" onclick="deleteBook(${b.id});return false;"><i class="fas fa-trash fa-fw me-2"></i>Delete</a></li>
            </ul>
        </div>`;
    }

    function circChips(b) {
        const chip = (n, label, color) => n > 0 ? `<span style="font-size:.64rem;background:${color}1a;color:${color};padding:1px 7px;border-radius:99px;font-weight:600;">${n} ${label}</span>` : '';
        return [chip(b.copies_on_loan, 'out', '#854f0b'), chip(b.copies_reserved, 'reserved', '#185fa5'),
                b.has_overdue ? `<span style="font-size:.64rem;background:#c2410c1a;color:#c2410c;padding:1px 7px;border-radius:99px;font-weight:600;">overdue</span>` : '',
                (b.quantity_missing > 0) ? chip(b.quantity_missing, 'lost', '#a32d2d') : '',
                (b.quantity_damaged > 0) ? chip(b.quantity_damaged, 'damaged', '#a32d2d') : ''].filter(Boolean).join(' ');
    }

    // Details affordance shared by all three views. Borrowing intentionally has
    // ONE entry point — the Add to Borrow Cart button inside Book Details — so
    // users always see availability and their borrowing rules before committing.
    function detailBtn(b) {
        return `<button class="btn btn-sm btn-outline-primary" style="font-size:.7rem;padding:2px 9px;"
                        onclick="event.stopPropagation();openBookDetails(${b.id})">
                    <i class="fas fa-circle-info me-1"></i>View details</button>`;
    }

    function gridHtml(items) {
        return '<div class="row g-2">' + items.map(b => {
            const s = statusOf(b);
            const availColor = b.quantity_available === 0 ? '#e24b4a' : (b.quantity_available <= LOW ? '#eab308' : '#3b6d11');
            return `<div class="col-12 col-md-6 col-xl-4">
                <div role="button" tabindex="0" onclick="openBookDetails(${b.id})"
                     onkeydown="if(event.key==='Enter')openBookDetails(${b.id})"
                     style="border:1px solid var(--border);border-left:4px solid ${s.color};border-radius:12px;background:var(--surface);padding:12px;height:100%;display:flex;gap:12px;cursor:pointer;transition:box-shadow .15s,border-color .15s;"
                     onmouseover="this.style.boxShadow='0 4px 14px rgba(0,0,0,.08)'" onmouseout="this.style.boxShadow='none'">
                    ${coverHtml(b, 56, 82)}
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;gap:6px;align-items:flex-start;">
                            <div style="font-weight:600;font-size:.86rem;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;" title="${esc(b.title)}">${esc(b.title)}</div>
                            <span onclick="event.stopPropagation()">${actionsMenu(b)}</span>
                        </div>
                        <div class="text-muted" style="font-size:.74rem;margin-bottom:2px;">${esc(b.author || '—')}</div>
                        <div class="text-muted" style="font-size:.68rem;font-family:monospace;margin-bottom:6px;">${isbnOf(b) ? 'ISBN ' + esc(isbnOf(b)) : 'No ISBN'}</div>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-size:.84rem;"><strong style="color:${availColor};">${b.quantity_available}</strong> <span class="text-muted">/ ${b.quantity_total}</span></span>
                            <span class="badge bg-${s.cls}" style="font-size:.62rem;">${esc(s.label)}</span>
                        </div>
                        <div style="margin-top:6px;display:flex;gap:5px;flex-wrap:wrap;">${circChips(b)}</div>
                        <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;">${detailBtn(b)}</div>
                    </div>
                </div>
            </div>`;
        }).join('') + '</div>';
    }

    function compactHtml(items) {
        return '<div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;">' + items.map(b => {
            const s = statusOf(b);
            return `<div role="button" tabindex="0" onclick="openBookDetails(${b.id})"
                 onkeydown="if(event.key==='Enter')openBookDetails(${b.id})"
                 style="display:flex;align-items:center;gap:12px;padding:9px 14px;border-bottom:1px solid var(--border);border-left:3px solid ${s.color};cursor:pointer;"
                 onmouseover="this.style.background='var(--bg,#f8fafc)'" onmouseout="this.style.background='transparent'">
                <div style="flex:1;min-width:0;">
                    <span style="font-weight:600;font-size:.82rem;">${esc(b.title)}</span>
                    <span class="text-muted" style="font-size:.74rem;"> · ${esc(b.author || '—')}</span>
                </div>
                <span class="text-muted" style="font-size:.7rem;font-family:monospace;">${esc(isbnOf(b) || '—')}</span>
                <span style="font-size:.8rem;white-space:nowrap;"><strong>${b.quantity_available}</strong><span class="text-muted">/${b.quantity_total}</span></span>
                <span class="badge bg-${s.cls}" style="font-size:.6rem;">${esc(s.label)}</span>
                <span onclick="event.stopPropagation()" style="display:flex;gap:6px;align-items:center;">${detailBtn(b)}${actionsMenu(b)}</span>
            </div>`;
        }).join('') + '</div>';
    }

    function tableHtml(items) {
        const rows = items.map(b => {
            const s = statusOf(b);
            return `<tr role="button" onclick="openBookDetails(${b.id})" style="cursor:pointer;">
                <td><div style="font-weight:600;font-size:.8rem;">${esc(b.title)}</div></td>
                <td style="font-size:.78rem;">${esc(b.author || '—')}</td>
                <td style="font-size:.72rem;font-family:monospace;">${esc(isbnOf(b) || '—')}</td>
                <td style="font-size:.78rem;">${esc(b.subject || '—')}</td>
                <td style="font-size:.78rem;">${esc(b.location_label || '—')}</td>
                <td><strong>${b.quantity_available}</strong> <span class="text-muted">/ ${b.quantity_total}</span></td>
                <td><span class="badge bg-${s.cls}" style="font-size:.62rem;">${esc(s.label)}</span></td>
                <td class="text-end" onclick="event.stopPropagation()"><span style="display:inline-flex;gap:6px;align-items:center;">${detailBtn(b)}${actionsMenu(b)}</span></td>
            </tr>`;
        }).join('');
        const th = (key, label) => key
            ? `<th role="button" onclick="invSortBy('${key}')" style="cursor:pointer;user-select:none;white-space:nowrap;">${label}${sortArrow(key)}</th>`
            : `<th>${label}</th>`;
        return `<div class="card"><div class="card-body p-0"><div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr>
                    ${th('title', 'Title')}${th('author', 'Author')}${th(null, 'ISBN')}${th('subject', 'Subject')}
                    ${th(null, 'Location')}${th('avail', 'Avail / Total')}${th(null, 'Status')}${th(null, '')}
                </tr></thead>
                <tbody>${rows}</tbody>
            </table></div></div></div>`;
    }

    // ── Quick actions ─────────────────────────────────────────────────────────
    async function invPost(action, params) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        return fetch('api/library_handler.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: new URLSearchParams(Object.assign({ action }, params)),
        }).then(r => r.json()).catch(() => ({ success: false }));
    }
    function toast(res) { if (typeof showToast === 'function') showToast(res.message || (res.success ? 'Done.' : 'Failed.'), res.success ? 'success' : 'error'); else if (!res.success) alert(res.message || 'Failed.'); }

    window.invAddCopies = async function (id) {
        const n = parseInt(prompt('How many copies to add?', '1'), 10);
        if (!n || n < 1) return;
        const res = await invPost('book_add_copies', { id, quantity: n });
        toast(res); if (res.success && typeof loadBooks === 'function') loadBooks();
    };
    window.invMark = async function (id, type) {
        const n = parseInt(prompt(`How many copies to mark as ${type}?`, '1'), 10);
        if (!n || n < 1) return;
        const res = await invPost('book_mark_condition', { id, quantity: n, type });
        toast(res); if (res.success && typeof loadBooks === 'function') loadBooks();
    };
    window.invArchive = async function (id, archive) {
        if (archive && !confirm('Archive this book? It will be hidden from the active inventory.')) return;
        const res = await invPost('book_archive', { id, archive });
        toast(res); if (res.success && typeof loadBooks === 'function') loadBooks();
    };

    // Fetch covers / descriptions / subjects for books missing them (online lookup)
    window.invBackfill = async function () {
        const btn = document.getElementById('inv-enrich-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Enriching…'; }
        const res = await invPost('book_backfill', { limit: 15 });
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-cloud-arrow-down me-1"></i>Enrich Metadata'; }
        toast(res);
        if (res.success && typeof loadBooks === 'function') loadBooks();
    };
})();
