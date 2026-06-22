// Intelligent Book Discovery — catalog grid + dedup-aware add.
// Talks to ?action=discovery_search (Google Books → Open Library) and
// ?action=book_add_from_discovery (server re-fetches + dedupes by ISBN).
(function () {
    let _page = 0, _query = '', _busy = false, _done = false, _observer = null;
    let _favs = new Set();       // isbn13s the user has bookmarked
    const _cardData = {};        // key → full result DTO (for Preview / Similar)
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    window.openBookDiscovery = function () {
        const m = document.getElementById('bookDiscoveryModal');
        if (!m) return;
        const dt = document.getElementById('discoveryDetail'); if (dt) dt.style.display = 'none';
        document.getElementById('discoveryResults').style.display = 'grid';
        document.getElementById('discoveryResults').innerHTML = '';
        document.getElementById('discoveryBanner').innerHTML = '';
        document.getElementById('discoveryQuery').value = '';
        _query = ''; _page = 0; _done = false;
        fetch('api/library_handler.php?action=favorites_get', { credentials: 'same-origin' })
            .then(r => r.json()).then(r => { if (r.success) _favs = new Set((r.data || []).map(f => f.isbn13)); }).catch(() => {});
        bootstrap.Modal.getOrCreateInstance(m).show();
        setTimeout(() => document.getElementById('discoveryQuery')?.focus(), 350);
        if (!_observer) {
            _observer = new IntersectionObserver(function (entries) {
                if (entries[0].isIntersecting && _query && !_busy && !_done) window.runDiscovery(_page + 1);
            }, { root: document.querySelector('#bookDiscoveryModal .modal-body'), rootMargin: '220px' });
            _observer.observe(document.getElementById('discoverySentinel'));
        }
    };

    window.runDiscovery = async function (page) {
        const q = document.getElementById('discoveryQuery').value.trim();
        if (q.length < 2) return;
        if (page === 0) {
            _query = q; _page = 0; _done = false;
            if (typeof closeDiscoveryDetail === 'function') closeDiscoveryDetail();
            document.getElementById('discoveryResults').innerHTML = '';
            document.getElementById('discoveryBanner').innerHTML = '';
        }
        if (_busy || _done) return;
        _busy = true;
        const status = document.getElementById('discoveryStatus');
        status.style.display = 'block';
        status.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Searching…';

        const res = await fetch('api/library_handler.php?action=discovery_search&q=' + encodeURIComponent(_query) + '&page=' + page, { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        _busy = false;

        if (!res.success) { status.innerHTML = '<span class="text-danger">' + esc(res.message || 'Search failed.') + '</span>'; return; }
        const data = res.data || {};
        const items = data.results || [];

        if (data.stale) {
            document.getElementById('discoveryBanner').innerHTML =
                '<div class="alert alert-warning py-2" style="font-size:.8rem;"><i class="fas fa-triangle-exclamation me-1"></i>Showing cached results — couldn\'t reach the catalog service.</div>';
        }

        if (page === 0 && !items.length) {
            status.style.display = 'none';
            document.getElementById('discoveryResults').innerHTML =
                '<div class="text-center text-muted py-5"><i class="fas fa-book-open fa-2x mb-2 d-block"></i>No results for &ldquo;' + esc(_query) +
                '&rdquo;. <a href="#" onclick="bootstrap.Modal.getInstance(document.getElementById(\'bookDiscoveryModal\')).hide();openAddBookModal();return false;">Add it manually</a>.</div>';
            _done = true; return;
        }
        document.getElementById('discoveryResults').insertAdjacentHTML('beforeend', items.map(cardHtml).join(''));
        _page = page;
        if (!data.has_more || !items.length) { _done = true; status.innerHTML = '<span class="text-muted">End of results.</span>'; }
        else status.style.display = 'none';
    };

    function cardHtml(b) {
        const authors = (b.authors || []).join(', ') || 'Unknown author';
        const meta = [b.published_year, b.page_count ? b.page_count + ' pages' : null, b.publisher].filter(Boolean).join(' · ');
        const isbns = [b.isbn13 ? 'ISBN13 ' + b.isbn13 : null, b.isbn10 ? 'ISBN10 ' + b.isbn10 : null].filter(Boolean).join('   ');
        const cover = b.cover_url
            ? '<img src="' + esc(b.cover_url) + '" alt="" loading="lazy" style="width:96px;height:144px;object-fit:cover;border-radius:8px;background:#f1f5f9;flex-shrink:0;">'
            : '<div style="width:96px;height:144px;border-radius:8px;flex-shrink:0;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.4rem;">' + esc((b.title || '?').slice(0, 2).toUpperCase()) + '</div>';
        const key = (b.isbn13 || b.source_id || b.title).replace(/[^A-Za-z0-9]/g, '');
        _cardData[key] = b;
        const payload = encodeURIComponent(JSON.stringify({ isbn13: b.isbn13 || '', source: b.source, source_id: b.source_id || '' }));
        const action = b.in_library
            ? '<span class="badge bg-success" style="font-size:.72rem;"><i class="fas fa-check me-1"></i>In library &middot; ' + (b.available != null ? b.available : 0) + ' available</span>'
            : '<button class="btn btn-primary btn-sm" data-disc="' + payload + '" onclick="addFromDiscovery(this)"><i class="fas fa-plus me-1"></i>Add to Library</button>';
        // Favorite + Preview + Similar (favorite/similar need a real ISBN-13)
        const faved = b.isbn13 && _favs.has(b.isbn13);
        const favBtn = b.isbn13
            ? '<button class="btn btn-sm btn-outline-secondary" title="Bookmark" data-key="' + key + '" onclick="favoriteDiscovery(this)"><i class="' + (faved ? 'fas' : 'far') + ' fa-heart"></i></button>'
            : '';
        const previewBtn = '<button class="btn btn-sm btn-outline-secondary" title="Preview" onclick="previewDiscovery(\'' + key + '\')"><i class="fas fa-eye"></i></button>';
        const similarBtn = (b.isbn13 || b.in_library)
            ? '<button class="btn btn-sm btn-outline-secondary" title="View similar" data-key="' + key + '" onclick="similarDiscovery(this)"><i class="fas fa-layer-group me-1"></i>Similar</button>'
            : '';
        return '' +
            '<div style="border:1px solid var(--border);border-radius:12px;padding:14px;background:var(--surface);">' +
              '<div style="display:flex;gap:16px;">' +
                cover +
                '<div style="flex:1;min-width:0;">' +
                    '<div style="font-weight:600;font-size:.95rem;line-height:1.3;">' + esc(b.title) + '</div>' +
                    '<div class="text-muted" style="font-size:.82rem;margin:2px 0 6px;">' + esc(authors) + '</div>' +
                    (meta ? '<div style="font-size:.78rem;margin-bottom:3px;">' + esc(meta) + '</div>' : '') +
                    (isbns ? '<div class="text-muted" style="font-size:.72rem;font-family:monospace;margin-bottom:6px;">' + esc(isbns) + '</div>' : '') +
                    (b.description ? '<div class="text-muted" style="font-size:.78rem;line-height:1.5;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">' + esc(b.description) + '</div>' : '') +
                    '<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">' + action + favBtn + previewBtn + similarBtn + '</div>' +
                '</div>' +
              '</div>' +
              '<div class="disc-similar" data-for="' + key + '" style="display:none;margin-top:12px;border-top:1px solid var(--border);padding-top:12px;"></div>' +
            '</div>';
    }

    const SOURCE_LABEL = { google: 'Google Books', openlibrary: 'Open Library', loc: 'Library of Congress', archive: 'Internet Archive' };

    // Full inspection view: all metadata + cover + in-library status + sections
    window.previewDiscovery = function (key) {
        const b = _cardData[key]; if (!b) return;
        const detail = document.getElementById('discoveryDetail');
        const results = document.getElementById('discoveryResults');
        const status = document.getElementById('discoveryStatus');
        const sentinel = document.getElementById('discoverySentinel');
        if (!detail) return;
        results.style.display = 'none'; if (status) status.style.display = 'none'; if (sentinel) sentinel.style.display = 'none';
        detail.style.display = 'block';

        const cover = b.cover_url
            ? `<img src="${esc(b.cover_url)}" alt="" style="width:150px;height:225px;object-fit:cover;border-radius:10px;background:#f1f5f9;flex-shrink:0;">`
            : `<div style="width:150px;height:225px;border-radius:10px;flex-shrink:0;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:2rem;">${esc((b.title || '?').slice(0, 2).toUpperCase())}</div>`;
        const inLib = b.in_library
            ? `<div class="alert alert-success py-2" style="font-size:.82rem;"><i class="fas fa-circle-check me-1"></i>Already in your library — <strong>${b.available != null ? b.available : 0}</strong> available.</div>`
            : `<div class="alert alert-secondary py-2" style="font-size:.82rem;"><i class="fas fa-circle-info me-1"></i>Not yet in your catalog.</div>`;
        const payload = encodeURIComponent(JSON.stringify({ isbn13: b.isbn13 || '', source: b.source, source_id: b.source_id || '' }));
        const actionBtn = b.in_library ? ''
            : `<button class="btn btn-primary" data-disc='${payload}' onclick="addFromDiscovery(this)"><i class="fas fa-plus me-1"></i>Add to Library</button>`;
        const favBtn = b.isbn13 ? `<button class="btn btn-outline-secondary" data-key="${key}" onclick="favoriteDiscovery(this)"><i class="${_favs.has(b.isbn13) ? 'fas' : 'far'} fa-heart me-1"></i>Bookmark</button>` : '';

        const meta = [
            ['Subtitle', b.subtitle], ['Author(s)', (b.authors || []).join(', ')], ['Publisher', b.publisher],
            ['Published', b.published_year], ['Pages', b.page_count], ['Language', (b.lang || '').toUpperCase()],
            ['Format', b.book_format], ['Edition', b.edition], ['Series', b.series],
            ['ISBN-13', b.isbn13], ['ISBN-10', b.isbn10],
            ['LC Classification', b.lcc], ['Dewey (DDC)', b.ddc],
            ['Categories', (b.categories || []).join(', ')], ['Source', SOURCE_LABEL[b.source] || b.source],
        ].filter(r => r[1]).map(r =>
            `<tr><td style="color:var(--text-muted);padding:4px 16px 4px 0;white-space:nowrap;vertical-align:top;font-size:.78rem;">${r[0]}</td><td style="padding:4px 0;font-size:.82rem;">${esc(r[1])}</td></tr>`).join('');

        detail.innerHTML = `
            <button class="btn btn-sm btn-outline-secondary mb-3" onclick="closeDiscoveryDetail()"><i class="fas fa-arrow-left me-1"></i>Back to results</button>
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex-shrink:0;">${cover}</div>
                <div style="flex:1;min-width:260px;">
                    <h4 style="font-weight:700;margin-bottom:2px;">${esc(b.title)}</h4>
                    ${b.subtitle ? `<div class="text-muted" style="font-size:.95rem;margin-bottom:4px;">${esc(b.subtitle)}</div>` : ''}
                    <div class="text-muted" style="margin-bottom:12px;">${esc((b.authors || []).join(', ') || 'Unknown author')}</div>
                    ${inLib}
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">${actionBtn}${favBtn}</div>
                    <table style="width:100%;"><tbody>${meta}</tbody></table>
                </div>
            </div>
            ${b.description ? `<div style="margin-top:20px;"><div style="font-weight:600;margin-bottom:6px;">Description</div><div style="font-size:.86rem;line-height:1.6;color:var(--text);">${esc(b.description)}</div></div>` : ''}
            <div id="detail-sections" style="margin-top:24px;"></div>`;

        loadDetailSections(b);
    };

    window.closeDiscoveryDetail = function () {
        const detail = document.getElementById('discoveryDetail');
        const results = document.getElementById('discoveryResults');
        const sentinel = document.getElementById('discoverySentinel');
        if (detail) detail.style.display = 'none';
        if (results) results.style.display = 'grid';
        if (sentinel) sentinel.style.display = 'block';
    };

    // "Same author" + "Other editions" sections, fetched live
    async function loadDetailSections(b) {
        const host = document.getElementById('detail-sections');
        if (!host) return;
        const author = (b.authors || [])[0];
        const sections = [];
        if (author) sections.push({ title: 'More by ' + author, q: 'inauthor:' + author });
        sections.push({ title: 'Other editions & related', q: 'intitle:' + b.title });

        host.innerHTML = sections.map((s, i) => `<div class="disc-section" data-i="${i}" style="margin-bottom:18px;">
            <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-bottom:8px;">${esc(s.title)}</div>
            <div class="disc-section-body text-muted" style="font-size:.8rem;"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</div>
        </div>`).join('');

        for (let i = 0; i < sections.length; i++) {
            const res = await fetch('api/library_handler.php?action=discovery_search&q=' + encodeURIComponent(sections[i].q) + '&page=0', { credentials: 'same-origin' })
                .then(r => r.json()).catch(() => ({ success: false }));
            const body = host.querySelector(`.disc-section[data-i="${i}"] .disc-section-body`);
            if (!body) continue;
            const items = (res.success ? res.data.results : []).filter(x => x.title !== b.title || x.isbn13 !== b.isbn13).slice(0, 8);
            body.classList.remove('text-muted');
            body.innerHTML = items.length
                ? '<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:6px;">' + items.map(miniBook).join('') + '</div>'
                : '<div class="text-muted" style="font-size:.8rem;">Nothing found.</div>';
        }
    }
    function miniBook(c) {
        const cover = c.cover_url
            ? `<img src="${esc(c.cover_url)}" alt="" loading="lazy" style="width:78px;height:116px;object-fit:cover;border-radius:6px;background:#f1f5f9;">`
            : `<div style="width:78px;height:116px;border-radius:6px;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;">${esc((c.title || '?').slice(0, 2).toUpperCase())}</div>`;
        return `<div style="width:78px;flex-shrink:0;">${cover}
            <div style="font-size:.66rem;line-height:1.25;margin-top:4px;max-height:2.5em;overflow:hidden;" title="${esc(c.title)}">${esc(c.title)}</div>
            ${c.in_library ? '<div style="font-size:.6rem;color:var(--success,#3b6d11);"><i class="fas fa-check"></i> in library</div>' : (c.published_year ? `<div style="font-size:.6rem;color:var(--text-muted);">${esc(c.published_year)}</div>` : '')}
        </div>`;
    }

    window.favoriteDiscovery = async function (btn) {
        const b = _cardData[btn.dataset.key]; if (!b || !b.isbn13) return;
        const res = await fetch('api/library_handler.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf() },
            body: new URLSearchParams({ action: 'favorite_toggle', isbn13: b.isbn13, title: b.title, author: (b.authors || []).join(', '), cover_url: b.cover_url || '', source: b.source || '' }),
        }).then(r => r.json()).catch(() => ({ success: false }));
        if (!res.success) return;
        if (res.data.favorited) { _favs.add(b.isbn13); btn.innerHTML = '<i class="fas fa-heart" style="color:#e24b4a;"></i>'; }
        else { _favs.delete(b.isbn13); btn.innerHTML = '<i class="far fa-heart"></i>'; }
    };

    window.similarDiscovery = async function (btn) {
        const b = _cardData[btn.dataset.key]; if (!b) return;
        const panel = document.querySelector('.disc-similar[data-for="' + btn.dataset.key + '"]');
        if (!panel) return;
        if (panel.style.display !== 'none') { panel.style.display = 'none'; return; }
        panel.style.display = 'block';
        panel.innerHTML = '<div class="text-muted" style="font-size:.8rem;"><i class="fas fa-spinner fa-spin me-2"></i>Finding similar books…</div>';

        let cards = [];
        if (b.in_library && b.book_id) {
            const res = await fetch('api/library_handler.php?action=book_similar&book_id=' + b.book_id, { credentials: 'same-origin' })
                .then(r => r.json()).catch(() => ({ success: false }));
            cards = (res.success ? res.data.relations : []).map(r => ({ title: r.title, authors: [r.author], cover_url: r.cover_url, tag: r.relation_type.replace('_', ' '), in_library: r.in_library }));
        }
        if (!cards.length) {
            const author = (b.authors || [])[0];
            if (author) {
                const res = await fetch('api/library_handler.php?action=discovery_search&q=' + encodeURIComponent('inauthor:' + author) + '&page=0', { credentials: 'same-origin' })
                    .then(r => r.json()).catch(() => ({ success: false }));
                cards = (res.success ? res.data.results : []).filter(x => x.title !== b.title).slice(0, 8).map(x => ({ title: x.title, authors: x.authors, cover_url: x.cover_url, tag: 'same author', in_library: x.in_library }));
            }
        }
        panel.innerHTML = cards.length
            ? '<div style="font-size:.74rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);margin-bottom:8px;">Similar books</div>' +
              '<div style="display:flex;gap:12px;overflow-x:auto;padding-bottom:4px;">' + cards.map(miniCard).join('') + '</div>'
            : '<div class="text-muted" style="font-size:.8rem;">No similar books found yet.</div>';
    };

    function miniCard(c) {
        const cover = c.cover_url
            ? '<img src="' + esc(c.cover_url) + '" alt="" loading="lazy" style="width:72px;height:104px;object-fit:cover;border-radius:6px;background:#f1f5f9;">'
            : '<div style="width:72px;height:104px;border-radius:6px;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;">' + esc((c.title || '?').slice(0, 2).toUpperCase()) + '</div>';
        return '<div style="width:72px;flex-shrink:0;">' + cover +
            '<div style="font-size:.66rem;line-height:1.25;margin-top:4px;max-height:2.5em;overflow:hidden;" title="' + esc(c.title) + '">' + esc(c.title) + '</div>' +
            (c.in_library ? '<div style="font-size:.6rem;color:var(--success,#3b6d11);"><i class="fas fa-check"></i> in library</div>' : '<div style="font-size:.6rem;color:var(--text-muted);">' + esc(c.tag) + '</div>') +
            '</div>';
    }

    window.addFromDiscovery = async function (btn) {
        const d = JSON.parse(decodeURIComponent(btn.dataset.disc));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Adding…';
        const res = await fetch('api/library_handler.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
            body: new URLSearchParams({ action: 'book_add_from_discovery', isbn13: d.isbn13, source: d.source, source_id: d.source_id, quantity: 1 }),
        }).then(r => r.json()).catch(() => ({ success: false }));

        if (!res.success) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus me-1"></i>Add to Library';
            alert(res.message || 'Failed to add.');
            return;
        }
        btn.outerHTML = '<span class="badge bg-success" style="font-size:.72rem;"><i class="fas fa-check me-1"></i>' +
            (res.data.action === 'linked' ? 'Copies added' : 'Added') + '</span>';
        if (typeof showToast === 'function') showToast(res.message, 'success');
        if (typeof loadBooks === 'function') loadBooks();
        if (typeof loadBookStats === 'function') loadBookStats();
    };
})();
