// Book Catalog Experience — dedicated Book Details view + Borrow Cart.
//
// Turns the flat "search → table → borrow" flow into a modern catalog workflow:
//   browse/search → open a full Book Details page → add to Borrow Cart →
//   review eligibility → submit ONE request for everything.
//
// Self-contained: injects its own modals + floating cart button, reads the same
// APIs the rest of the app uses (book_detail, borrow_eligibility,
// book_borrow_request_add), and persists the cart per-user in localStorage.
(function () {
    'use strict';

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
    const CART_KEY = 'lms_borrow_cart_' + ((window.currentUser && window.currentUser.id) || 'anon');

    // ── Cart state ─────────────────────────────────────────────────────────────
    function cartGet() {
        try {
            const items = JSON.parse(localStorage.getItem(CART_KEY)) || [];
            // Normalize: carts saved before quantity support get qty = 1
            items.forEach(i => { i.qty = Math.max(1, parseInt(i.qty, 10) || 1); });
            return items;
        } catch { return []; }
    }
    const cartCopies = items => items.reduce((s, i) => s + (parseInt(i.qty, 10) || 1), 0);
    function cartSet(items) {
        localStorage.setItem(CART_KEY, JSON.stringify(items));
        updateCartBadge();
    }
    function cartHas(id) { return cartGet().some(i => Number(i.id) === Number(id)); }

    let _justAddedId = null;   // drives the inline "added!" confirmation in Book Details

    window.catalogAddToCart = function (book) {
        if (typeof book === 'number') {
            const found = (window.allBooks || []).find(b => Number(b.id) === book);
            if (!found) return;
            book = found;
        }
        if (cartHas(book.id)) { toast('Already in your borrow cart.', 'info'); return; }
        const items = cartGet();
        items.push({
            id: Number(book.id), title: book.title || '', author: book.author || '',
            cover_url: book.cover_url || '', isbn: book.isbn13 || book.isbn || '',
            qty: 1,
        });
        cartSet(items);
        toast('"' + (book.title || 'Book') + '" added to your borrow cart.', 'success');
        // In Book Details, replace the button with a clear confirmation + next steps
        if (document.getElementById('bookDetailsModal')?.classList.contains('show') && _detailBook
            && Number(_detailBook.id) === Number(book.id)) {
            _justAddedId = Number(book.id);
            renderDetail(_detailBook, _detailElig);
            _justAddedId = null;
        }
    };

    window.catalogRemoveFromCart = function (id) {
        cartSet(cartGet().filter(i => Number(i.id) !== Number(id)));
        renderCart();
    };

    // Quantity change from the cart's − / input / + controls.
    // Clamped to 1..available (live stock fetched when the cart opened); the
    // eligibility panel and submit re-validate on every change.
    window.catalogSetQty = function (id, qty) {
        const items = cartGet();
        const item = items.find(i => Number(i.id) === Number(id));
        if (!item) return;
        const stock = _cartStock[item.id];
        const max = stock ? Math.max(1, stock.available) : 99;
        item.qty = Math.min(max, Math.max(1, parseInt(qty, 10) || 1));
        cartSet(items);
        renderCart();
    };

    window.catalogCartHas = cartHas;

    function updateCartBadge() {
        const items = cartGet();
        const n = cartCopies(items);   // badge counts copies — matches the borrowing limit
        const badge = document.getElementById('cart-fab-count');
        const fab = document.getElementById('cart-fab');
        if (badge) badge.textContent = n;
        if (fab) fab.style.display = items.length > 0 ? 'flex' : 'none';
    }

    function toast(msg, type) {
        if (typeof window.showToast === 'function') window.showToast(msg, type || 'info');
    }

    // ── Static UI (modals + floating cart button) ──────────────────────────────
    function injectUi() {
        if (document.getElementById('bookDetailsModal')) return;
        const host = document.createElement('div');
        host.innerHTML = `
<div class="modal fade" id="bookDetailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="padding:12px 20px;">
        <span style="font-weight:700;font-size:.92rem;"><i class="fas fa-book-open me-2" style="color:var(--primary);"></i>Book Details</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="bookDetailsBody" style="min-height:280px;"></div>
    </div>
  </div>
</div>
<div class="modal fade" id="borrowCartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="padding:12px 20px;">
        <span style="font-weight:700;font-size:.92rem;"><i class="fas fa-basket-shopping me-2" style="color:var(--primary);"></i>My Borrow Cart</span>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="borrowCartBody"></div>
    </div>
  </div>
</div>
<button id="cart-fab" onclick="openBorrowCart()" title="My Borrow Cart" aria-label="Open borrow cart"
        style="display:none;position:fixed;right:22px;bottom:22px;z-index:1040;width:56px;height:56px;border-radius:50%;
               background:var(--primary,#003087);color:#fff;border:none;box-shadow:0 6px 20px rgba(0,0,0,.25);
               align-items:center;justify-content:center;font-size:1.15rem;cursor:pointer;">
  <i class="fas fa-basket-shopping"></i>
  <span id="cart-fab-count" style="position:absolute;top:-4px;right:-4px;background:var(--danger,#dc3545);color:#fff;
        border-radius:99px;font-size:.68rem;font-weight:700;min-width:20px;height:20px;display:flex;align-items:center;
        justify-content:center;padding:0 5px;border:2px solid #fff;">0</span>
</button>`;
        while (host.firstChild) document.body.appendChild(host.firstChild);
        updateCartBadge();
    }

    // ── Book Details ────────────────────────────────────────────────────────────
    let _detailBook = null, _detailElig = null;

    window.openBookDetails = async function (id) {
        injectUi();
        const body = document.getElementById('bookDetailsBody');
        body.innerHTML = '<div class="text-center text-muted py-5" style="font-size:.85rem;"><i class="fas fa-spinner fa-spin me-2"></i>Loading book details…</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('bookDetailsModal')).show();

        const [bookRes, eligRes] = await Promise.all([
            fetch('api/library_handler.php?action=book_detail&id=' + Number(id), { credentials: 'same-origin' })
                .then(r => r.json()).catch(() => ({ success: false })),
            fetch('api/library_handler.php?action=borrow_eligibility', { credentials: 'same-origin' })
                .then(r => r.json()).catch(() => ({ success: false })),
        ]);
        if (!bookRes.success) {
            body.innerHTML = '<div class="alert alert-danger" style="font-size:.85rem;">' + esc(bookRes.message || 'Could not load this book.') + '</div>';
            return;
        }
        _detailBook = bookRes.data;
        _detailElig = eligRes.success ? eligRes.data : null;
        renderDetail(_detailBook, _detailElig);
    };

    function availabilityBanner(b) {
        if (b.is_archived) return `<div class="alert alert-secondary py-2 mb-3" style="font-size:.82rem;"><i class="fas fa-box-archive me-2"></i>This title is archived and not available for borrowing.</div>`;
        const avail = Number(b.quantity_available) || 0;
        if (avail <= 0) {
            return `<div class="alert alert-warning py-2 mb-3" style="font-size:.82rem;">
                <i class="fas fa-clock me-2"></i><strong>All copies are currently out.</strong>
                You can reserve it and be notified when a copy returns.
            </div>`;
        }
        const low = avail <= 3 ? ' <span class="text-muted">(limited copies — borrow soon)</span>' : '';
        return `<div class="alert alert-success py-2 mb-3" style="font-size:.82rem;">
            <i class="fas fa-circle-check me-2"></i><strong>${avail} of ${Number(b.quantity_total) || 0} copies available</strong> on the shelf now.${low}
        </div>`;
    }

    function renderDetail(b, elig) {
        const body = document.getElementById('bookDetailsBody');
        if (!body) return;

        const cover = b.cover_url
            ? `<img src="${esc(b.cover_url)}" alt="Book cover" style="width:170px;height:250px;object-fit:cover;border-radius:12px;background:#f1f5f9;box-shadow:0 8px 22px rgba(0,0,0,.14);">`
            : `<div style="width:170px;height:250px;border-radius:12px;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:2.4rem;box-shadow:0 8px 22px rgba(0,0,0,.1);">${esc((b.title || '?').slice(0, 2).toUpperCase())}</div>`;

        const chips = [b.subject, b.category, b.grade_level, b.reading_level]
            .filter(Boolean)
            .map(t => `<span class="badge" style="background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);font-size:.68rem;font-weight:600;">${esc(t)}</span>`)
            .join(' ');

        // Every relevant catalog field, shown only when present
        const rows = [
            ['Accession No.', 'SDO-B' + String(b.id).padStart(5, '0')],
            ['ISBN-13', b.isbn13], ['ISBN', b.isbn && b.isbn !== b.isbn13 ? b.isbn : null],
            ['Author', b.author], ['Publisher', b.publisher],
            ['Publication Year', b.published_year || b.publish_year],
            ['Edition', b.edition], ['Series', b.series], ['Format', b.book_format],
            ['Pages', b.page_count], ['Language', (b.lang || '').toUpperCase() || null],
            ['Dewey (DDC)', b.ddc], ['LC Class', b.lcc],
            ['Subject', b.subject], ['Category', b.category], ['Grade Level', b.grade_level],
            ['Shelf Location', b.location_label || 'Ask the librarian'],
            ['Condition', b.condition_status],
        ].filter(r => r[1] !== null && r[1] !== undefined && String(r[1]).trim() !== '')
         .map(r => `<tr>
                <td style="color:var(--text-muted);padding:4px 14px 4px 0;white-space:nowrap;font-size:.76rem;vertical-align:top;">${r[0]}</td>
                <td style="padding:4px 0;font-size:.82rem;font-weight:500;">${esc(r[1])}</td>
            </tr>`).join('');

        const circ = `
            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:2px 0 14px;">
              ${[['Available', b.quantity_available, 'var(--success,#198754)'],
                 ['On loan', b.copies_on_loan, 'var(--warning,#b45309)'],
                 ['Reserved', b.copies_reserved, 'var(--info,#0d6efd)'],
                 ['Total copies', b.quantity_total, 'var(--text,#111827)']]
                .map(([l, v, c]) => `<div style="text-align:center;">
                    <div style="font-size:1.25rem;font-weight:800;color:${c};line-height:1.1;">${Number(v) || 0}</div>
                    <div style="font-size:.64rem;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">${l}</div>
                </div>`).join('')}
            </div>`;

        const rules = elig ? `
            <div style="background:var(--bg,#f8fafc);border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:6px;">
                    <i class="fas fa-scale-balanced me-1"></i>Your borrowing privileges</div>
                <div style="font-size:.78rem;line-height:1.7;color:var(--text);">
                    Loan period: <strong>${elig.max_borrow_days} days</strong> ·
                    Limit: <strong>${elig.max_books ?? elig.max_books_per_borrow ?? '—'} books</strong> ·
                    You can still borrow: <strong style="color:${elig.remaining > 0 ? 'var(--success,#198754)' : 'var(--danger,#dc3545)'};">${elig.remaining}</strong>
                </div>
            </div>` : '';

        const inCart = cartHas(b.id);
        const avail = Number(b.quantity_available) || 0;
        let actions = '';
        if (!b.is_archived) {
            if (avail > 0) {
                if (b.user_has_active) {
                    actions += `<div class="alert alert-info py-2 mb-2" style="font-size:.78rem;"><i class="fas fa-circle-info me-1"></i>You already have an active loan or request for this title.</div>`;
                } else if (Number(_justAddedId) === Number(b.id)) {
                    actions += `<div style="width:100%;">
                        <div class="alert alert-success py-2 mb-2" style="font-size:.82rem;">
                            <i class="fas fa-circle-check me-1"></i><strong>Added to your borrow cart!</strong> What would you like to do next?</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="btn btn-outline-primary" onclick="bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'))?.hide();switchTabById('books');">
                                <i class="fas fa-arrow-left me-1"></i>Continue Browsing</button>
                            <button class="btn btn-primary" onclick="bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'))?.hide();openBorrowCart();">
                                <i class="fas fa-basket-shopping me-1"></i>View Borrow Cart</button>
                        </div></div>`;
                } else if (inCart) {
                    actions += `<button class="btn btn-success" disabled><i class="fas fa-check me-1"></i>In your cart</button>
                           <button class="btn btn-outline-primary" onclick="bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'))?.hide();openBorrowCart();"><i class="fas fa-basket-shopping me-1"></i>View Borrow Cart</button>`;
                } else {
                    actions += `<button class="btn btn-primary" onclick='catalogAddToCart(${JSON.stringify({ id: b.id, title: b.title, author: b.author, cover_url: b.cover_url, isbn13: b.isbn13, isbn: b.isbn }).replace(/'/g, "&#39;")})'>
                               <i class="fas fa-basket-shopping me-1"></i>Add to Borrow Cart</button>`;
                }
            } else {
                actions += `<button class="btn btn-warning" onclick="bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'))?.hide();switchTabById('reservations');">
                    <i class="fas fa-bookmark me-1"></i>Reserve this book</button>`;
            }
        }
        if (window.isStaff) {
            actions += ` <button class="btn btn-outline-secondary" onclick="bootstrap.Modal.getInstance(document.getElementById('bookDetailsModal'))?.hide();openEditBookModal(${Number(b.id)});">
                <i class="fas fa-pen me-1"></i>Edit</button>`;
        }

        body.innerHTML = `
            <div style="display:flex;gap:24px;flex-wrap:wrap;">
                <div style="flex-shrink:0;margin:0 auto;">${cover}</div>
                <div style="flex:1;min-width:260px;">
                    <h4 style="font-weight:800;margin-bottom:2px;line-height:1.25;">${esc(b.title)}</h4>
                    ${b.subtitle ? `<div class="text-muted" style="font-size:.92rem;margin-bottom:2px;">${esc(b.subtitle)}</div>` : ''}
                    <div class="text-muted" style="font-size:.88rem;margin-bottom:8px;">${esc(b.author || 'Unknown author')}</div>
                    ${chips ? `<div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px;">${chips}</div>` : ''}
                    ${availabilityBanner(b)}
                    ${circ}
                    ${rules}
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;">${actions}</div>
                </div>
            </div>
            ${b.description ? `<div style="margin-top:18px;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:6px;">About this book</div>
                <div style="font-size:.85rem;line-height:1.65;color:var(--text);">${esc(b.description)}</div>
            </div>` : ''}
            <div style="margin-top:18px;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:6px;">Catalog information</div>
                <table style="width:100%;"><tbody>${rows}</tbody></table>
            </div>`;
    }

    // ── Borrow Cart ────────────────────────────────────────────────────────────
    window.openBorrowCart = async function () {
        injectUi();
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('borrowCartModal'));
        modal.show();
        renderCart();  // instant paint from local state…
        const res = await fetch('api/library_handler.php?action=borrow_eligibility', { credentials: 'same-origin' })
            .then(r => r.json()).catch(() => ({ success: false }));
        _cartElig = res.success ? res.data : null;
        await refreshCartAvailability();
        renderCart();  // …then re-render with live eligibility + stock
    };

    let _cartElig = null;
    let _cartStock = {};   // book_id → quantity_available (live)

    async function refreshCartAvailability() {
        _cartStock = {};
        const items = cartGet();
        if (!items.length) return;
        // one round-trip per cart item (carts are small; keeps the API surface unchanged)
        const results = await Promise.all(items.map(i =>
            fetch('api/library_handler.php?action=book_detail&id=' + Number(i.id), { credentials: 'same-origin' })
                .then(r => r.json()).catch(() => ({ success: false }))));
        results.forEach((r, idx) => {
            if (r.success) {
                _cartStock[items[idx].id] = {
                    available: Number(r.data.quantity_available) || 0,
                    archived: !!r.data.is_archived,
                    userHasActive: !!r.data.user_has_active,
                };
            }
        });
    }

    function cartProblems(items) {
        const problems = [];
        items.forEach(i => {
            const s = _cartStock[i.id];
            if (!s) return;
            if (s.archived) problems.push({ id: i.id, msg: `"${i.title}" is no longer available (archived).` });
            else if (s.available <= 0) problems.push({ id: i.id, msg: `"${i.title}" has no copies available right now — remove it or reserve it instead.` });
            else if (s.userHasActive) problems.push({ id: i.id, msg: `You already have an active loan or request for "${i.title}".` });
            else if (i.qty > s.available) problems.push({ id: i.id, msg: `Only ${s.available} cop${s.available === 1 ? 'y is' : 'ies are'} available for "${i.title}" — lower the quantity.` });
        });
        return problems;
    }

    function renderCart() {
        const body = document.getElementById('borrowCartBody');
        if (!body) return;
        const items = cartGet();

        if (!items.length) {
            body.innerHTML = `<div class="text-center text-muted py-5">
                <i class="fas fa-basket-shopping fa-2x mb-3 d-block" style="opacity:.35;"></i>
                <div style="font-size:.9rem;font-weight:600;margin-bottom:4px;">Your borrow cart is empty</div>
                <div style="font-size:.78rem;margin-bottom:16px;">Browse the catalog and add the books you'd like to borrow.</div>
                <button class="btn btn-primary btn-sm" onclick="bootstrap.Modal.getInstance(document.getElementById('borrowCartModal'))?.hide();switchTabById('books');">
                    <i class="fas fa-magnifying-glass me-1"></i>Browse the catalog</button>
            </div>`;
            return;
        }

        const problems = cartProblems(items);
        const problemIds = new Set(problems.map(p => p.id));

        const rowsHtml = items.map(i => {
            const s = _cartStock[i.id];
            const bad = problemIds.has(i.id);
            const max = s ? Math.max(1, s.available) : 99;
            const cover = i.cover_url
                ? `<img src="${esc(i.cover_url)}" alt="" style="width:44px;height:64px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex-shrink:0;">`
                : `<div style="width:44px;height:64px;border-radius:6px;flex-shrink:0;background:var(--primary-light,#e6f1fb);color:var(--primary,#185fa5);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;">${esc((i.title || '?').slice(0, 2).toUpperCase())}</div>`;
            const stepper = `
                <div style="display:flex;align-items:center;gap:0;flex-shrink:0;" role="group" aria-label="Copies of ${esc(i.title)}">
                    <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px 0 0 8px;padding:3px 10px;" ${i.qty <= 1 ? 'disabled' : ''}
                            onclick="catalogSetQty(${i.id}, ${i.qty - 1})" title="One copy less" aria-label="Decrease quantity"><i class="fas fa-minus" style="font-size:.68rem;"></i></button>
                    <input type="number" class="form-control form-control-sm" value="${i.qty}" min="1" max="${max}" inputmode="numeric"
                           style="width:54px;text-align:center;border-radius:0;border-left:0;border-right:0;font-weight:700;"
                           onchange="catalogSetQty(${i.id}, this.value)" aria-label="Number of copies">
                    <button class="btn btn-sm btn-outline-secondary" style="border-radius:0 8px 8px 0;padding:3px 10px;" ${i.qty >= max ? 'disabled' : ''}
                            onclick="catalogSetQty(${i.id}, ${i.qty + 1})" title="One copy more" aria-label="Increase quantity"><i class="fas fa-plus" style="font-size:.68rem;"></i></button>
                </div>`;
            return `<div style="display:flex;align-items:center;gap:12px;padding:10px 4px;border-bottom:1px solid var(--border,#eef1f5);flex-wrap:wrap;${bad ? 'opacity:.8;' : ''}">
                ${cover}
                <div style="flex:1;min-width:150px;">
                    <div style="font-weight:600;font-size:.84rem;line-height:1.3;">
                        <a href="#" onclick="openBookDetails(${i.id});return false;" style="color:var(--text);text-decoration:none;">${esc(i.title)}</a>
                    </div>
                    <div class="text-muted" style="font-size:.74rem;">${esc(i.author || '—')}</div>
                    ${s ? (bad
                        ? `<div style="font-size:.7rem;color:var(--danger,#dc3545);font-weight:600;"><i class="fas fa-triangle-exclamation me-1"></i>${s.archived ? 'No longer available' : (s.available <= 0 ? 'No copies available' : (s.userHasActive ? 'Already borrowed/requested' : `Only ${s.available} available`))}</div>`
                        : `<div style="font-size:.7rem;color:var(--success,#198754);"><i class="fas fa-circle-check me-1"></i>${s.available} cop${s.available === 1 ? 'y' : 'ies'} available</div>`) : ''}
                </div>
                ${stepper}
                <button class="btn btn-sm btn-outline-danger" onclick="catalogRemoveFromCart(${i.id})" title="Remove from cart" aria-label="Remove ${esc(i.title)}">
                    <i class="fas fa-trash-can"></i></button>
            </div>`;
        }).join('');

        // Eligibility panel — the allowance counts COPIES, so quantities matter
        const copies = cartCopies(items);
        let eligHtml = '', blocked = false, blockMsg = '';
        if (_cartElig) {
            const over = copies > _cartElig.remaining;
            if (_cartElig.has_overdue) { blocked = true; blockMsg = 'You have overdue books. Please return them before requesting new loans.'; }
            else if (over) { blocked = true; blockMsg = `This request totals ${copies} copies but your remaining allowance is ${_cartElig.remaining} of ${_cartElig.max_books}. Lower the quantities or remove books.`; }
            const barPct = Math.min(100, Math.round(((_cartElig.active_items + _cartElig.pending_items + copies) / _cartElig.max_books) * 100));
            eligHtml = `
            <div style="background:var(--bg,#f8fafc);border:1px solid var(--border,#e5e7eb);border-radius:10px;padding:12px 14px;margin:14px 0;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);">
                        <i class="fas fa-scale-balanced me-1"></i>Borrowing eligibility</span>
                    <span style="font-size:.74rem;color:var(--text-muted);">${_cartElig.active_items} on loan · ${_cartElig.pending_items} pending · ${copies} in cart</span>
                </div>
                <div style="height:7px;border-radius:99px;background:var(--border,#e5e7eb);overflow:hidden;margin-bottom:6px;">
                    <div style="width:${barPct}%;height:100%;background:${blocked ? 'var(--danger,#dc3545)' : 'var(--success,#198754)'};border-radius:99px;"></div>
                </div>
                <div style="font-size:.76rem;color:var(--text);">
                    Limit for your account type: <strong>${_cartElig.max_books} books</strong> ·
                    Loan period: <strong>${_cartElig.max_borrow_days} days</strong>
                </div>
            </div>`;
        }

        if (problems.length && !blocked) { blocked = true; blockMsg = 'Fix the highlighted items before submitting.'; }

        const today = new Date();
        const maxDays = _cartElig ? _cartElig.max_borrow_days : 14;
        const defDue = new Date(today.getTime() + maxDays * 864e5);
        const fmt = d => d.toISOString().slice(0, 10);
        const minDate = fmt(new Date(today.getTime() + 864e5));

        body.innerHTML = `
            <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:4px;">${items.length} title${items.length === 1 ? '' : 's'} · ${copies} cop${copies === 1 ? 'y' : 'ies'} selected</div>
            <div>${rowsHtml}</div>
            ${problems.length ? `<div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:.78rem;">${problems.map(p => `<div><i class="fas fa-triangle-exclamation me-1"></i>${esc(p.msg)}</div>`).join('')}</div>` : ''}
            ${eligHtml}
            <div class="row g-2 align-items-end mb-3">
                <div class="col-12 col-sm-6">
                    <label class="form-label fw-semibold mb-1" style="font-size:.76rem;">Return by <span class="text-muted fw-normal">(max ${maxDays} days)</span></label>
                    <input type="date" class="form-control form-control-sm" id="cart-return-date"
                           value="${fmt(defDue)}" min="${minDate}" max="${fmt(defDue)}">
                </div>
                <div class="col-12 col-sm-6 text-sm-end">
                    <button class="btn btn-outline-secondary btn-sm me-1" onclick="bootstrap.Modal.getInstance(document.getElementById('borrowCartModal'))?.hide();switchTabById('books');">
                        <i class="fas fa-arrow-left me-1"></i>Keep browsing</button>
                    <button class="btn btn-primary btn-sm" id="cart-submit-btn" onclick="submitBorrowCart()" ${blocked ? 'disabled' : ''}>
                        <i class="fas fa-paper-plane me-1"></i>Submit Borrow Request</button>
                </div>
            </div>
            ${blocked ? `<div class="alert alert-danger py-2 mb-0" style="font-size:.78rem;"><i class="fas fa-circle-exclamation me-1"></i>${esc(blockMsg)}</div>`
                      : `<div class="text-muted" style="font-size:.72rem;"><i class="fas fa-circle-info me-1"></i>Your request goes to the librarian for approval. You'll see its status under <strong>Borrowing → My Borrow Requests</strong>.</div>`}
        `;
    }

    window.submitBorrowCart = async function () {
        const items = cartGet();
        if (!items.length) return;
        const btn = document.getElementById('cart-submit-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Submitting…'; }

        const params = new URLSearchParams({ action: 'book_borrow_request_add' });
        // Staff accounts must name a borrower; the cart always borrows as yourself.
        if (window.isStaff && window.currentUser?.full_name) {
            params.append('borrower_name', window.currentUser.full_name);
        }
        const due = document.getElementById('cart-return-date')?.value || '';
        if (due) params.append('return_date', due);
        items.forEach((i, idx) => {
            params.append(`items[${idx}][book_id]`, i.id);
            params.append(`items[${idx}][quantity]`, Math.max(1, parseInt(i.qty, 10) || 1));
        });

        const res = await fetch('api/library_handler.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf() },
            body: params,
        }).then(r => r.json()).catch(() => ({ success: false, message: 'Network error — please try again.' }));

        if (!res.success) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Submit Borrow Request'; }
            toast(res.message || 'Failed to submit the request.', 'error');
            return;
        }

        cartSet([]);
        const body = document.getElementById('borrowCartBody');
        if (body) body.innerHTML = `<div class="text-center py-5">
            <div style="width:64px;height:64px;border-radius:50%;background:var(--success-light,#d1fae5);color:var(--success,#198754);display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 14px;">
                <i class="fas fa-check"></i></div>
            <div style="font-weight:700;font-size:1rem;margin-bottom:4px;">Request submitted!</div>
            <div class="text-muted" style="font-size:.8rem;margin-bottom:18px;">The librarian will review it shortly. You'll find it under My Borrow Requests.</div>
            <button class="btn btn-primary btn-sm" onclick="bootstrap.Modal.getInstance(document.getElementById('borrowCartModal'))?.hide();switchTabById('borrowing');">
                <i class="fas fa-list-check me-1"></i>View my requests</button>
        </div>`;
        if (typeof window.loadBookBorrowRequests === 'function') window.loadBookBorrowRequests();
    };

    document.addEventListener('DOMContentLoaded', injectUi);
})();
