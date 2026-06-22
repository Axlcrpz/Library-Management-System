<?php
$types = ['Book', 'Reference Book', 'Textbook', 'Research Paper', 'Thesis', 'Journal', 'Magazine', 'Manual', 'Report', 'Memorandum', 'Other'];
?>

<div class="modal fade" id="addDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="add-document-form" enctype="multipart/form-data">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" name="title" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Document Type <span class="text-danger">*</span></label>
                            <select name="document_type" class="form-select form-select-sm" required>
                                <option value="">Select type</option>
                                <?php foreach ($types as $type): ?><option><?= htmlspecialchars($type) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Section / Shelf Location</label>
                            <input type="text" class="form-control form-control-sm" name="section" placeholder="e.g. Filipiniana, Reference, Shelf A1">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Document Date</label>
                            <input type="date" class="form-control form-control-sm" name="date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Attach Files <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="file" class="form-control form-control-sm" name="files[]" multiple
                               accept=".pdf,.xlsx,.xls,.csv,.docx,.doc,.pptx,.ppt,.jpg,.jpeg,.png,.gif,.webp,.bmp,.txt">
                        <div class="form-text">Supported: PDF, Excel, CSV, Word, PowerPoint, Images · Max 20 MB per file</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-document-btn">Save Document</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="edit-document-form" enctype="multipart/form-data">
                    <input type="hidden" id="editDocumentId" name="id">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="editDocumentTitle" name="title" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Document Type <span class="text-danger">*</span></label>
                            <select id="editDocumentType" name="document_type" class="form-select form-select-sm" required>
                                <?php foreach ($types as $type): ?><option><?= htmlspecialchars($type) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Section / Shelf Location</label>
                            <input type="text" class="form-control form-control-sm" id="editSection" name="section" placeholder="e.g. Filipiniana, Reference, Shelf A1">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Document Date</label>
                            <input type="date" class="form-control form-control-sm" id="editDate" name="date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control form-control-sm" id="editNotes" name="notes" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Upload Additional PDF</label>
                        <input type="file" class="form-control form-control-sm" name="files[]" multiple accept="application/pdf">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-edit-btn">Update Document</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="borrowDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Borrow Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="borrowDocumentTitle"></p>
                <form id="borrow-document-form">
                    <?php if ($isStaff): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Borrower Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="borrower_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact</label>
                        <input type="text" class="form-control form-control-sm" name="borrower_contact">
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info py-2 mb-3 d-flex align-items-center" style="font-size:.85rem;">
                        <i class="fas fa-user-check me-2"></i>
                        <span>Borrowing as <strong><?= htmlspecialchars($userName) ?></strong> — your account is linked automatically.</span>
                    </div>
                    <input type="hidden" name="borrower_name" value="<?= htmlspecialchars($userName) ?>">
                    <?php endif; ?>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Borrowed Date</label>
                            <input type="datetime-local" class="form-control form-control-sm" id="borrowedAt" name="borrowed_at">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Expected Return</label>
                            <input type="date" class="form-control form-control-sm" name="expected_return_date">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea class="form-control form-control-sm" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-borrow-btn">Borrow</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="returnDocumentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Return Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="returnDocumentTitle"></p>
                <form id="return-document-form">
                    <label class="form-label fw-semibold">Return Notes</label>
                    <textarea class="form-control form-control-sm" name="return_notes" rows="3"></textarea>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="save-return-btn">Return</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="versionHistoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document Version History: <span id="historyDocumentTitle"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>Version</th><th>Document Event</th><th>Title</th><th>File</th><th>Updated By</th><th>Date</th></tr>
                        </thead>
                        <tbody id="versionHistoryBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="notificationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Borrowed Document Deadlines</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="notifications-container" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="goto-documents-btn">View Documents</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="qrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Document QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <h6 id="qrDocumentTitle" class="mb-3"></h6>
                <div id="qrCanvas" class="qr-preview-box mx-auto mb-3"></div>
                <p class="small text-muted mb-0" id="qrValueText"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="print-qr-btn">Print QR</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="batchQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Batch QR Codes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="batchQrList" class="row g-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="print-batch-qr-btn">Print All</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="scanQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Scan QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="scanQrReader" class="qr-reader border rounded mb-3"></div>
                <div class="input-group input-group-sm mb-3">
                    <input type="text" class="form-control" id="scanQrInput" placeholder="Paste QR value or document ID">
                    <button class="btn btn-outline-secondary" type="button" id="scan-qr-manual-btn">Lookup</button>
                </div>
                <div id="scanQrResult" class="mt-2 text-muted small">Scan a QR code or paste the QR value.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="stop-camera-qr-btn">Stop Camera</button>
                <button type="button" class="btn btn-primary" id="start-camera-qr-btn">Start Camera</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addBookModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="min-height:560px;">

            <div class="modal-header py-2">
                <h5 class="modal-title" id="bookModalTitle" style="font-size:.92rem;font-weight:700;">
                    <i class="fas fa-book me-2 text-primary"></i>Add / Import Book
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <!-- Tab bar -->
            <div style="background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:10px 20px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <button type="button" id="btab-isbn"   onclick="bTab('isbn')"
                        class="btn btn-primary btn-sm" style="font-size:.78rem;">
                    <i class="fas fa-barcode me-1"></i>Search ISBN
                </button>
                <button type="button" id="btab-manual" onclick="bTab('form')"
                        class="btn btn-outline-secondary btn-sm" style="font-size:.78rem;">
                    <i class="fas fa-pencil me-1"></i>Manual Entry
                </button>
                <div style="flex:1;"></div>
                <button type="button" id="btab-favs" onclick="bTab('favs')"
                        class="btn btn-outline-warning btn-sm" style="font-size:.78rem;">
                    <i class="fas fa-star me-1" style="color:#f59e0b;"></i>Favorites
                    <span id="bfav-badge" style="display:none;margin-left:3px;background:#f59e0b;color:#fff;border-radius:99px;padding:1px 6px;font-size:.65rem;font-weight:700;"></span>
                </button>
            </div>

            <div class="modal-body p-0">

                <!-- ── ISBN SEARCH PANEL ──────────────────────────────── -->
                <div id="bpanel-isbn" style="padding:20px;">
                    <div style="display:flex;gap:8px;margin-bottom:14px;align-items:flex-end;flex-wrap:wrap;">
                        <div style="flex:1;min-width:180px;">
                            <label style="font-size:.74rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">
                                ISBN-10 or ISBN-13
                            </label>
                            <div style="position:relative;">
                                <i class="fas fa-barcode" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.8rem;pointer-events:none;"></i>
                                <input type="text" id="bisbn-input"
                                       style="width:100%;padding:9px 36px 9px 32px;font-size:.92rem;font-family:monospace;letter-spacing:.05em;border:1.5px solid #d1d5db;border-radius:9px;outline:none;transition:border-color .15s;"
                                       placeholder="9780140449136"
                                       maxlength="17"
                                       oninput="this.value=this.value.replace(/[^\dXx\-]/g,'').slice(0,17);bisbnChanged()"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault();bisbnSearch();}">
                                <button type="button" id="bisbn-clear-btn" onclick="bisbnClear()"
                                        style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#9ca3af;cursor:pointer;font-size:.82rem;padding:0;line-height:1;">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="bisbnSearch()"
                                style="font-size:.82rem;white-space:nowrap;padding:8px 20px;">
                            <i class="fas fa-search me-1"></i>Fetch Metadata
                        </button>
                    </div>

                    <!-- Source toggles -->
                    <div style="display:flex;gap:16px;margin-bottom:16px;align-items:center;flex-wrap:wrap;">
                        <span style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;letter-spacing:.05em;">Sources:</span>
                        <label style="font-size:.74rem;display:flex;align-items:center;gap:5px;cursor:pointer;color:#374151;user-select:none;">
                            <input type="checkbox" id="bsrc-google" checked style="accent-color:#4285f4;width:13px;height:13px;">
                            <span style="display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-circle" style="color:#4285f4;font-size:.4rem;"></i>Google Books</span>
                        </label>
                        <label style="font-size:.74rem;display:flex;align-items:center;gap:5px;cursor:pointer;color:#374151;user-select:none;">
                            <input type="checkbox" id="bsrc-oplib" checked style="accent-color:#e8a020;width:13px;height:13px;">
                            <span style="display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-circle" style="color:#e8a020;font-size:.4rem;"></i>Open Library</span>
                        </label>
                    </div>

                    <!-- Results area -->
                    <div id="bres-idle" style="text-align:center;padding:44px 20px;color:#9ca3af;">
                        <i class="fas fa-magnifying-glass-arrow-right" style="font-size:2.4rem;display:block;margin-bottom:12px;opacity:.28;"></i>
                        <div style="font-size:.86rem;font-weight:600;color:#6b7280;margin-bottom:4px;">Search by ISBN</div>
                        <div style="font-size:.78rem;line-height:1.6;">Enter an ISBN above to retrieve title, author, publisher,<br>cover image, and more from multiple sources simultaneously.</div>
                    </div>
                    <div id="bres-loading" style="display:none;text-align:center;padding:36px 20px;">
                        <div style="font-size:.84rem;color:#6b7280;margin-bottom:12px;"><i class="fas fa-spinner fa-spin me-2"></i>Fetching from sources…</div>
                        <div style="display:flex;justify-content:center;gap:24px;flex-wrap:wrap;">
                            <span id="bstat-google" style="font-size:.74rem;color:#9ca3af;display:flex;align-items:center;gap:5px;">
                                <i class="fas fa-spinner fa-spin" style="font-size:.65rem;"></i>Google Books
                            </span>
                            <span id="bstat-oplib" style="font-size:.74rem;color:#9ca3af;display:flex;align-items:center;gap:5px;">
                                <i class="fas fa-spinner fa-spin" style="font-size:.65rem;"></i>Open Library
                            </span>
                        </div>
                    </div>
                    <div id="bres-list"  style="display:none;"></div>
                    <div id="bres-empty" style="display:none;text-align:center;padding:36px 20px;color:#9ca3af;">
                        <i class="fas fa-circle-xmark" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3;"></i>
                        <div style="font-size:.82rem;margin-bottom:12px;">No results found for this ISBN from any source.</div>
                        <button type="button" onclick="bTab('form')" class="btn btn-outline-primary btn-sm" style="font-size:.76rem;">
                            <i class="fas fa-pencil me-1"></i>Enter Details Manually
                        </button>
                    </div>
                </div>

                <!-- ── FAVORITES PANEL ────────────────────────────────── -->
                <div id="bpanel-favs" style="display:none;padding:20px;">
                    <p style="font-size:.8rem;color:#6b7280;margin-bottom:14px;">
                        Books you've starred during ISBN searches. Click <strong>Use</strong> to pre-fill the entry form.
                    </p>
                    <div id="bfavs-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px;"></div>
                    <div id="bfavs-empty" style="text-align:center;padding:44px 20px;color:#9ca3af;">
                        <i class="fas fa-star" style="font-size:2.2rem;display:block;margin-bottom:10px;opacity:.28;"></i>
                        <div style="font-size:.82rem;">No favorites saved yet.<br>Star a search result to save it here for quick re-use.</div>
                    </div>
                </div>

                <!-- ── FORM PANEL ─────────────────────────────────────── -->
                <div id="bpanel-form" style="display:none;padding:20px;">

                    <!-- Import notice (shown when coming from search) -->
                    <div id="bimport-notice" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:.8rem;color:#15803d;align-items:center;gap:8px;">
                        <i class="fas fa-circle-check" style="flex-shrink:0;"></i>
                        <span>Metadata imported from <strong id="bimport-src"></strong>. Fill in the library-specific fields below to complete.</span>
                        <button type="button" onclick="bTab('isbn')"
                                style="margin-left:auto;background:none;border:none;color:#15803d;font-size:.74rem;cursor:pointer;white-space:nowrap;text-decoration:underline;padding:0;">
                            <i class="fas fa-arrow-left me-1"></i>Back to search
                        </button>
                    </div>

                    <form id="book-form">
                        <input type="hidden" id="bookId" name="id">

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="bookTitle" name="title" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Author(s)</label>
                                <input type="text" class="form-control form-control-sm" id="bookAuthor" name="author">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Subject</label>
                                <input type="text" class="form-control form-control-sm" id="bookSubject" name="subject" placeholder="e.g. Math, Science">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Category</label>
                                <input type="text" class="form-control form-control-sm" id="bookCategory" name="category" placeholder="e.g. Textbook, Reference">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Grade Level</label>
                                                                <input type="text" class="form-control form-control-sm" id="bookGradeLevel" name="grade_level" placeholder="e.g. Grade 1, All">
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">ISBN</label>
                                <input type="text" class="form-control form-control-sm" id="bookIsbn" name="isbn">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Location Label</label>
                                <input type="text" class="form-control form-control-sm" id="bookLocation" name="location_label" placeholder="e.g. Shelf A-3">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Condition</label>
                                <select class="form-select form-select-sm" id="bookCondition" name="condition_status">
                                    <option value="good">Good</option>
                                    <option value="fair">Fair</option>
                                    <option value="poor">Poor</option>
                                </select>
                            </div>
                        </div>
                        <hr class="my-3" style="border-color:#f1f5f9;">
                        <div style="font-size:.72rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">Library Quantities</div>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small">Total</label>
                                <input type="number" class="form-control form-control-sm" id="bookQtyTotal" name="quantity_total" min="0" value="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small">Available</label>
                                <input type="number" class="form-control form-control-sm" id="bookQtyAvailable" name="quantity_available" min="0" value="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small">Damaged</label>
                                <input type="number" class="form-control form-control-sm" id="bookQtyDamaged" name="quantity_damaged" min="0" value="0">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label fw-semibold small">Missing</label>
                                <input type="number" class="form-control form-control-sm" id="bookQtyMissing" name="quantity_missing" min="0" value="0">
                            </div>
                        </div>
                    </form>
                </div>
            </div><!-- /modal-body -->

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="save-book-btn" style="display:none;">
                    <i class="fas fa-save me-1"></i>Save Book
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addDeliveryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Log Delivery</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Delivery Date</label>
                        <input type="date" class="form-control form-control-sm" id="deliveryDate">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Source <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" id="deliverySource" placeholder="e.g. Division Office, Donor, Supplier">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Remarks</label>
                        <input type="text" class="form-control form-control-sm" id="deliveryRemarks" placeholder="Optional">
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Delivery Items</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addDeliveryItemRow()">
                        <i class="fas fa-plus me-1"></i> Add Item
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:260px;">Book</th>
                                <th style="width:110px;">Received</th>
                                <th style="width:110px;">Damaged</th>
                                <th style="width:110px;">Missing</th>
                                <th>Notes</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="delivery-items-body"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="save-delivery-btn">Save Delivery</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Post Announcement</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="announcementTitle" placeholder="Announcement title">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Category</label>
                        <select class="form-select" id="announcementCategory">
                            <option value="general">General</option>
                            <option value="urgent">Urgent</option>
                            <option value="event">Event</option>
                            <option value="academic">Academic</option>
                            <option value="library">Library</option>
                            <option value="memorandum">Memorandum</option>
                        </select>
                    </div>
                </div>

                <label class="form-label fw-semibold">Content <span class="text-danger">*</span></label>
                <div class="ann-editor-wrap" style="border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                    <div id="announcementEditorToolbar">
                        <span class="ql-formats">
                            <select class="ql-header" aria-label="Text style">
                                <option value="1">Heading 1</option>
                                <option value="2">Heading 2</option>
                                <option value="3">Heading 3</option>
                                <option selected>Body text</option>
                            </select>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-bold" aria-label="Bold"></button>
                            <button class="ql-italic" aria-label="Italic"></button>
                            <button class="ql-underline" aria-label="Underline"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-list" value="ordered" aria-label="Numbered list"></button>
                            <button class="ql-list" value="bullet" aria-label="Bulleted list"></button>
                            <button class="ql-blockquote" aria-label="Quote"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-callout" value="info" title="Info notice" aria-label="Info notice"><i class="fas fa-circle-info"></i></button>
                            <button class="ql-callout" value="warning" title="Warning notice" aria-label="Warning notice"><i class="fas fa-triangle-exclamation"></i></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-link" aria-label="Link"></button>
                            <button class="ql-image" aria-label="Insert image"></button>
                        </span>
                        <span class="ql-formats">
                            <button class="ql-clean" aria-label="Clear formatting"></button>
                        </span>
                    </div>
                    <div id="announcementEditor" style="min-height:240px;max-height:48vh;overflow-y:auto;"></div>
                </div>
                <input type="hidden" id="announcementBody">
                <div class="form-text mb-3" style="font-size:.72rem;">
                    Use <strong>Heading</strong> styles for structure and the notice buttons for important callouts. Pasted content from Word or Docs is cleaned automatically.
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select form-select-sm" id="announcementStatus">
                            <option value="1">Publish</option>
                            <option value="0">Save as Draft</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Priority</label>
                        <select class="form-select form-select-sm" id="announcementPriority">
                            <option value="normal">Normal</option>
                            <option value="important">Important</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Publish on <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                        <input type="datetime-local" class="form-control form-control-sm" id="announcementPublish">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">Expires on <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                        <input type="date" class="form-control form-control-sm" id="announcementExpire">
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="announcementPinned">
                        <label class="form-check-label" for="announcementPinned" style="font-size:.85rem;"><i class="fas fa-thumbtack me-1 text-muted"></i>Pin to top</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="announcementFeatured">
                        <label class="form-check-label" for="announcementFeatured" style="font-size:.85rem;"><i class="fas fa-star me-1 text-muted"></i>Feature</label>
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label fw-semibold">Attachments <span class="text-muted" style="font-weight:400;">(optional)</span></label>
                    <input type="file" class="form-control form-control-sm" id="announcementFiles" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp,.bmp">
                    <div class="form-text" style="font-size:.72rem;">
                        PDF, Word, Excel, PowerPoint, or images. Max 20&nbsp;MB each.
                    </div>
                </div>
                <div class="form-text" style="font-size:.72rem;">
                    <i class="fas fa-circle-info me-1"></i>Only <strong>Published</strong> announcements appear to users. Drafts are visible to staff only.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-announcement-btn">Save Announcement</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Book Discovery (search external catalogs, add with dedup) ────────────── -->
<div class="modal fade" id="bookDiscoveryModal" tabindex="-1" aria-hidden="true" aria-labelledby="bookDiscoveryTitle">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bookDiscoveryTitle"><i class="fas fa-wand-magic-sparkles me-2" style="color:var(--primary);"></i>Discover &amp; Add Books</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group mb-1">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="text" class="form-control" id="discoveryQuery"
                           placeholder="Search by title, author, or ISBN…" onkeydown="if(event.key==='Enter')runDiscovery(0)">
                    <button class="btn btn-primary" onclick="runDiscovery(0)" id="discoverySearchBtn">Search</button>
                </div>
                <div class="form-text mb-3" style="font-size:.74rem;">
                    Searches Google Books, falling back to Open Library. ISBNs match exactly; keywords match title &amp; author.
                </div>
                <div id="discoveryBanner"></div>
                <div id="discoveryResults" style="display:grid;grid-template-columns:1fr;gap:14px;"></div>
                <div id="discoverySentinel" style="height:1px;"></div>
                <div id="discoveryStatus" class="text-center text-muted py-3" style="font-size:.82rem;display:none;"></div>
                <!-- Rich preview / inspection panel (replaces results while open) -->
                <div id="discoveryDetail" style="display:none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Avatar Gallery (choose a system avatar) ─────────────────────────────── -->
<div class="modal fade" id="avatarGalleryModal" tabindex="-1" aria-hidden="true" aria-labelledby="avatarGalleryTitle">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="avatarGalleryTitle">Choose an avatar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-1 mb-3 flex-wrap" id="avatarCatTabs"></div>
                <div id="avatarGalleryGrid"
                     style="display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:12px;">
                    <div class="text-center text-muted py-4" style="grid-column:1/-1;">
                        <i class="fas fa-spinner fa-spin me-2"></i>Loading avatars…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Announcement Reader (full-screen reading view) ──────────────────────── -->
<div class="modal fade" id="announcementReaderModal" tabindex="-1" aria-hidden="true" aria-labelledby="annReaderTitle">
    <div class="modal-dialog modal-fullscreen-md-down modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border:none;">
            <div class="modal-header" style="gap:10px;align-items:center;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal" aria-label="Back to announcements">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </button>
                <span class="ms-auto text-muted" style="font-size:.78rem;"><i class="fas fa-bullhorn me-1"></i>Announcement</span>
                <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body ann-reader-text-wrap" id="annReaderBody" style="max-width:740px;margin:0 auto;width:100%;padding:24px clamp(16px,4vw,40px) 48px;">
                <div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-2"></i>Loading…</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bookBorrowRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Borrow Request</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="book-borrow-request-form">

                    <?php if ($isStaff): ?>
                    <!-- BORROWER SEARCH (staff only — regular users always borrow as themselves) -->
                    <div class="card mb-3 border-primary">
                        <div class="card-header bg-primary text-white py-2">
                            <i class="fas fa-search me-2"></i> Search Borrower
                        </div>
                        <div class="card-body">
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Search by Name<?php if ($isAdmin): ?> or LRN<?php endif; ?></label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control" id="borrowerSearchInput" placeholder="Type school name or LRN..." oninput="searchBorrowers()">
                                        <button class="btn btn-outline-secondary" type="button" onclick="searchBorrowers()">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Filter by Type</label>
                                    <select class="form-select form-select-sm" id="borrowerTypeFilter" onchange="searchBorrowers()">
                                        <option value="">All</option>
                                        <option value="school">School</option>
                                        <option value="individual">Individual</option>
                                    </select>
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-outline-success w-100" onclick="showRegisterBorrowerForm()">
                                        <i class="fas fa-user-plus me-1"></i> Register New Borrower
                                    </button>
                                </div>
                            </div>

                            <!-- SEARCH RESULTS -->
                            <div id="borrowerSearchResults" class="list-group mb-2" style="max-height:180px;overflow-y:auto;display:none;"></div>

                            <!-- SELECTED BORROWER DISPLAY -->
                            <div id="selectedBorrowerDisplay" style="display:none;">
                                <div class="alert alert-success py-2 mb-0 d-flex justify-content-between align-items-center">
                                    <div>
                                        <i class="fas fa-user-check me-2"></i>
                                        <strong id="selectedBorrowerName"></strong>
                                        <span class="badge bg-primary ms-2" id="selectedBorrowerType"></span>
                                        <?php if ($isAdmin): ?><span class="text-muted small ms-2" id="selectedBorrowerLrn"></span><?php endif; ?>
                                        <span class="text-muted small ms-2" id="selectedBorrowerContact"></span>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSelectedBorrower()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- REGISTER NEW BORROWER FORM -->
                            <div id="registerBorrowerForm" style="display:none;" class="border rounded p-3 mt-2 bg-light">
                                <h6 class="mb-3"><i class="fas fa-user-plus me-2"></i>Register New Borrower</h6>
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                                        <select class="form-select form-select-sm" id="newBorrowerType">
                                            <option value="individual">Individual</option>
                                            <option value="school">School</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-sm" id="newBorrowerName" placeholder="Full name or school name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">LRN</label>
                                        <input type="text" class="form-control form-control-sm" id="newBorrowerLrn" placeholder="Learning Resource Number">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Contact</label>
                                        <input type="text" class="form-control form-control-sm" id="newBorrowerContact" placeholder="Phone or email">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-semibold">Contact Person</label>
                                        <input type="text" class="form-control form-control-sm" id="newBorrowerContactPerson" placeholder="e.g. School Librarian">
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end gap-2">
                                        <button type="button" class="btn btn-sm btn-success w-100" onclick="registerNewBorrower()">
                                            <i class="fas fa-save me-1"></i> Save
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary w-100" onclick="hideRegisterBorrowerForm()">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- HIDDEN BORROWER ID -->
                    <input type="hidden" name="borrower_id" id="selectedBorrowerId">
                    <input type="hidden" name="borrower_name" id="selectedBorrowerNameInput">
                    <input type="hidden" name="borrower_contact" id="selectedBorrowerContactInput">
                    <?php else: ?>
                    <!-- Identity comes from the logged-in session; nothing to type, nothing exposed -->
                    <div class="alert alert-info py-2 mb-3 d-flex align-items-center" style="font-size:.85rem;">
                        <i class="fas fa-user-check me-2"></i>
                        <span>Borrowing as <strong><?= htmlspecialchars($userName) ?></strong></span>
                    </div>
                    <?php endif; ?>

                    <!-- BORROW DETAILS -->
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Borrow Type</label>
                            <select class="form-select form-select-sm" name="borrow_type">
                                <option value="inside">Inside Library</option>
                                <option value="outside">Outside / Overnight</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Borrow Date</label>
                            <input type="date" class="form-control form-control-sm" name="borrow_date" id="borrow-date-input">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Return Date</label>
                            <input type="date" class="form-control form-control-sm" name="return_date" id="return-date-input">
                            <div id="borrow-duration-display" style="font-size:.75rem;color:var(--text-muted);margin-top:4px;"></div>
                        </div>
                    </div>

                    <!-- BOOKS TO BORROW -->
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Books to Borrow</h6>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addBookBorrowItemRow()">
                            <i class="fas fa-plus me-1"></i> Add Book
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:320px;">Book</th>
                                    <th style="width:120px;">Quantity</th>
                                    <th style="width:60px;"></th>
                                </tr>
                            </thead>
                            <tbody id="book-borrow-items-body"></tbody>
                        </table>
                    </div>

                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-book-borrow-request-btn">Submit Request</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="bookReturnModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Return Books</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="book-return-form">
                    <input type="hidden" name="id" id="bookReturnBorrowId">

                    <!-- Auto-fine banner (populated by JS) -->
                    <div id="bookReturnFineAlert" style="display:none;background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.25);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:none;align-items:center;gap:10px;">
                        <i class="fas fa-circle-exclamation" style="color:var(--danger);font-size:1rem;flex-shrink:0;"></i>
                        <div style="flex:1;">
                            <div style="font-weight:700;font-size:.82rem;color:var(--danger);">Overdue — Auto-calculated Fine</div>
                            <div id="bookReturnFineDetail" style="font-size:.78rem;color:var(--text-muted);margin-top:2px;"></div>
                        </div>
                        <div style="font-size:1.2rem;font-weight:800;color:var(--danger);" id="bookReturnFineAmt"></div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Return Notes</label>
                            <input type="text" class="form-control form-control-sm" name="return_notes">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Fine Amount (PHP)</label>
                            <input type="number" class="form-control form-control-sm" name="fine_amount" min="0" step="0.01" placeholder="0.00">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width:320px;">Book</th>
                                    <th style="width:120px;">Return Qty</th>
                                    <th style="width:120px;">Damaged</th>
                                    <th style="width:120px;">Missing</th>
                                </tr>
                            </thead>
                            <tbody id="book-return-items-body"></tbody>
                        </table>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="save-book-return-btn">Save Return</button>
            </div>
        </div>
    </div>
</div>
<script>
// ── Book modal ISBN search & favorites ────────────────────────────────────────
let _bresults = [];

function bTab(tab) {
    ['isbn','favs','form'].forEach(t => {
        document.getElementById('bpanel-' + t).style.display = 'none';
    });
    document.getElementById('bpanel-' + tab).style.display = 'block';

    const styles = {
        isbn:   { isbn:'btn-primary', manual:'btn-outline-secondary', favs:'btn-outline-warning' },
        form:   { isbn:'btn-outline-secondary', manual:'btn-primary', favs:'btn-outline-warning' },
        favs:   { isbn:'btn-outline-secondary', manual:'btn-outline-secondary', favs:'btn-warning' },
    };
    const s = styles[tab] || styles.isbn;
    ['isbn','manual','favs'].forEach(t => {
        const el = document.getElementById('btab-' + t);
        if (el) el.className = 'btn btn-sm ' + (s[t] || 'btn-outline-secondary');
    });
    document.getElementById('save-book-btn').style.display = (tab === 'form') ? 'block' : 'none';
    if (tab === 'favs') bRenderFavs();
}

function bisbnChanged() {
    const v  = document.getElementById('bisbn-input').value;
    const cl = document.getElementById('bisbn-clear-btn');
    if (cl) cl.style.display = v ? 'block' : 'none';
}

function bisbnClear() {
    document.getElementById('bisbn-input').value = '';
    document.getElementById('bisbn-clear-btn').style.display = 'none';
    ['bres-idle','bres-loading','bres-list','bres-empty'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = id === 'bres-idle' ? 'block' : 'none';
    });
    _bresults = [];
}

async function bisbnSearch() {
    const raw  = document.getElementById('bisbn-input').value.trim();
    const isbn = raw.replace(/[\-\s]/g, '');
    if (!isbn || isbn.length < 10) { document.getElementById('bisbn-input').focus(); return; }

    const useG = document.getElementById('bsrc-google').checked;
    const useO = document.getElementById('bsrc-oplib').checked;
    if (!useG && !useO) return;

    document.getElementById('bres-idle').style.display  = 'none';
    document.getElementById('bres-list').style.display  = 'none';
    document.getElementById('bres-empty').style.display = 'none';
    document.getElementById('bres-loading').style.display = 'block';
    document.getElementById('bstat-google').style.display = useG ? 'flex' : 'none';
    document.getElementById('bstat-oplib').style.display  = useO ? 'flex' : 'none';
    document.getElementById('bstat-google').innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size:.65rem;"></i>Google Books';
    document.getElementById('bstat-oplib').innerHTML  = '<i class="fas fa-spinner fa-spin" style="font-size:.65rem;"></i>Open Library';

    _bresults = [];
    const todo = [];
    if (useG) todo.push(bFetchGoogle(isbn));
    if (useO) todo.push(bFetchOpLib(isbn));

    const done = await Promise.allSettled(todo);
    done.forEach(d => { if (d.status === 'fulfilled') _bresults.push(...d.value); });

    document.getElementById('bres-loading').style.display = 'none';
    if (!_bresults.length) {
        document.getElementById('bres-empty').style.display = 'block';
    } else {
        bRenderResults(_bresults);
        document.getElementById('bres-list').style.display = 'block';
    }
}

async function bFetchGoogle(isbn) {
    try {
        const r = await fetch('https://www.googleapis.com/books/v1/volumes?q=isbn:' + isbn + '&maxResults=5');
        const d = await r.json();
        document.getElementById('bstat-google').innerHTML =
            '<i class="fas fa-circle-check" style="color:#22c55e;font-size:.7rem;"></i>Google Books';
        return (d.items || []).map(item => {
            const v   = item.volumeInfo || {};
            const ids = v.industryIdentifiers || [];
            return {
                src: 'Google Books', srcColor: '#4285f4',
                title:  v.title || '',
                author: (v.authors || []).join(', '),
                publisher: v.publisher || '',
                year:   (v.publishedDate || '').slice(0, 4),
                isbn13: (ids.find(x => x.type === 'ISBN_13') || {}).identifier || isbn,
                isbn10: (ids.find(x => x.type === 'ISBN_10') || {}).identifier || '',
                pages:  v.pageCount || '',
                subject: (v.categories || []).join(', '),
                lang:   v.language || '',
                desc:   v.description ? v.description.slice(0, 240) + (v.description.length > 240 ? '…' : '') : '',
                cover:  (v.imageLinks || {}).thumbnail?.replace('http:', 'https:') || '',
            };
        });
    } catch {
        document.getElementById('bstat-google').innerHTML =
            '<i class="fas fa-circle-xmark" style="color:#ef4444;font-size:.7rem;"></i>Google Books (failed)';
        return [];
    }
}

async function bFetchOpLib(isbn) {
    try {
        const r = await fetch('https://openlibrary.org/api/books?bibkeys=ISBN:' + isbn + '&format=json&jscmd=data');
        const d = await r.json();
        document.getElementById('bstat-oplib').innerHTML =
            '<i class="fas fa-circle-check" style="color:#22c55e;font-size:.7rem;"></i>Open Library';
        return Object.keys(d).map(k => {
            const b = d[k] || {};
            return {
                src: 'Open Library', srcColor: '#e8a020',
                title:  b.title || '',
                author: (b.authors || []).map(a => a.name).join(', '),
                publisher: (b.publishers || []).map(p => p.name).join(', '),
                year:   (b.publish_date || '').replace(/.*(\d{4}).*/, '$1'),
                isbn13: (b.identifiers?.isbn_13 || [])[0] || isbn,
                isbn10: (b.identifiers?.isbn_10 || [])[0] || '',
                pages:  b.number_of_pages || '',
                subject: (b.subjects || []).slice(0, 4)
                    .map(s => typeof s === 'string' ? s : (s.name || '')).join(', '),
                lang: (b.language?.key || '').replace('/languages/', ''),
                desc: typeof b.notes === 'string' ? b.notes.slice(0, 240) : '',
                cover: b.cover?.large || b.cover?.medium || b.cover?.small || '',
            };
        });
    } catch {
        document.getElementById('bstat-oplib').innerHTML =
            '<i class="fas fa-circle-xmark" style="color:#ef4444;font-size:.7rem;"></i>Open Library (failed)';
        return [];
    }
}

function bRenderResults(results) {
    const favKeys = new Set(bLoadFavs().map(f => f.isbn13 || f.title));
    const html = results.map((r, i) => {
        const fav   = favKeys.has(r.isbn13 || r.title);
        const thumb = r.cover
            ? `<img src="${r.cover}" style="width:52px;height:74px;object-fit:cover;border-radius:5px;box-shadow:0 2px 6px rgba(0,0,0,.12);" onerror="this.parentNode.innerHTML='${bNoCoverHtml()}'">`
            : bNoCoverHtml();
        const metaLine = [
            r.publisher ? bEsc(r.publisher) : null,
            r.year || null,
            r.pages ? bEsc(r.pages) + ' pp.' : null,
            r.lang  ? bEsc(r.lang.toUpperCase()) : null,
        ].filter(Boolean).join(' &middot; ');
        return `
        <div style="display:flex;gap:14px;padding:14px;border:1.5px solid #e5e7eb;border-radius:11px;margin-bottom:10px;background:#fff;transition:box-shadow .15s,border-color .15s;"
             onmouseover="this.style.borderColor='#c7d4f5';this.style.boxShadow='0 4px 16px rgba(0,48,135,.07)'"
             onmouseout="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'">
            <div style="flex-shrink:0;">${thumb}</div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.9rem;font-weight:700;color:#111827;line-height:1.3;margin-bottom:3px;">${bEsc(r.title)}</div>
                <div style="font-size:.78rem;color:#4b5563;margin-bottom:3px;">
                    <i class="fas fa-user-pen" style="width:14px;text-align:center;color:#9ca3af;font-size:.68rem;"></i>
                    ${bEsc(r.author || 'Unknown author')}
                </div>
                ${metaLine ? `<div style="font-size:.73rem;color:#9ca3af;margin-bottom:5px;">${metaLine}</div>` : ''}
                ${r.isbn13 ? `<div style="font-size:.69rem;color:#9ca3af;font-family:monospace;margin-bottom:5px;">ISBN-13: ${bEsc(r.isbn13)}</div>` : ''}
                ${r.subject ? `<div style="font-size:.72rem;color:#6b7280;margin-bottom:5px;">${bEsc(r.subject.slice(0, 90))}</div>` : ''}
                ${r.desc    ? `<div style="font-size:.73rem;color:#6b7280;line-height:1.55;margin-bottom:7px;">${bEsc(r.desc)}</div>` : ''}
                <span style="font-size:.66rem;font-weight:700;padding:2px 8px;border-radius:99px;background:${r.srcColor}18;color:${r.srcColor};border:1px solid ${r.srcColor}44;">
                    ${bEsc(r.src)}
                </span>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;padding-top:2px;align-items:flex-end;">
                <button type="button" onclick="bSelectResult(${i})"
                        style="font-size:.74rem;padding:7px 15px;background:#003087;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:700;white-space:nowrap;"
                        onmouseover="this.style.background='#002070'" onmouseout="this.style.background='#003087'">
                    <i class="fas fa-check me-1"></i>Use This
                </button>
                <button type="button" onclick="bToggleFav(${i})"
                        title="${fav ? 'Remove from favorites' : 'Save to favorites'}"
                        style="font-size:.78rem;padding:5px 11px;background:${fav ? '#fef3c7' : '#fff'};border:1.5px solid ${fav ? '#f59e0b' : '#e5e7eb'};border-radius:8px;cursor:pointer;transition:all .15s;"
                        onmouseover="this.style.borderColor='#f59e0b'" onmouseout="this.style.borderColor='${fav ? '#f59e0b' : '#e5e7eb'}'">
                    <i class="fas fa-star" style="color:${fav ? '#f59e0b' : '#d1d5db'};"></i>
                </button>
            </div>
        </div>`;
    }).join('');

    document.getElementById('bres-list').innerHTML =
        `<div style="font-size:.76rem;color:#6b7280;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
            <i class="fas fa-circle-check" style="color:#22c55e;"></i>
            Found <strong style="color:#111827;">${results.length}</strong> result${results.length !== 1 ? 's' : ''}
            &nbsp;—&nbsp; click <strong style="color:#003087;">Use This</strong> to import into the form
        </div>${html}`;
}

function bNoCoverHtml() {
    return `<div style="width:52px;height:74px;background:#f3f4f6;border-radius:5px;display:flex;align-items:center;justify-content:center;"><i class="fas fa-book" style="color:#d1d5db;font-size:1.2rem;"></i></div>`;
}

function bEsc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function bSelectResult(idx) {
    const r = _bresults[idx];
    if (!r) return;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    set('bookTitle',    r.title);
    set('bookAuthor',   r.author);
    set('bookSubject',  r.subject);
    set('bookCategory', r.subject ? r.subject.split(',')[0].trim() : '');
    set('bookIsbn',     r.isbn13 || r.isbn10);
    // Reset library-specific fields so admin fills them fresh
    set('bookGradeLevel', '');
    set('bookLocation', '');
    ['bookQtyTotal','bookQtyAvailable','bookQtyDamaged','bookQtyMissing'].forEach(id => set(id, '0'));
    const cond = document.getElementById('bookCondition');
    if (cond) cond.value = 'good';

    const notice = document.getElementById('bimport-notice');
    const src    = document.getElementById('bimport-src');
    if (src)    src.textContent = r.src;
    if (notice) notice.style.display = 'flex';

    bTab('form');
}

// ── Favorites ─────────────────────────────────────────────────────────────────
function bLoadFavs() {
    try { return JSON.parse(localStorage.getItem('lms_bkfavs') || '[]'); } catch { return []; }
}
function bSaveFavs(favs) {
    localStorage.setItem('lms_bkfavs', JSON.stringify(favs));
    const badge = document.getElementById('bfav-badge');
    if (badge) { badge.textContent = favs.length; badge.style.display = favs.length ? 'inline' : 'none'; }
}
function bToggleFav(idx) {
    const r = _bresults[idx]; if (!r) return;
    const favs = bLoadFavs();
    const key  = r.isbn13 || r.title;
    const pos  = favs.findIndex(f => (f.isbn13 || f.title) === key);
    if (pos === -1) favs.push(r); else favs.splice(pos, 1);
    bSaveFavs(favs);
    bRenderResults(_bresults);
}
function bRenderFavs() {
    const favs  = bLoadFavs();
    const grid  = document.getElementById('bfavs-grid');
    const empty = document.getElementById('bfavs-empty');
    if (!grid) return;
    if (!favs.length) { grid.innerHTML = ''; if (empty) empty.style.display = 'block'; return; }
    if (empty) empty.style.display = 'none';
    grid.innerHTML = favs.map((f, i) => {
        const thumb = f.cover
            ? `<img src="${f.cover}" style="width:42px;height:60px;object-fit:cover;border-radius:5px;flex-shrink:0;" onerror="this.style.display='none'">`
            : `<div style="width:42px;height:60px;background:#f3f4f6;border-radius:5px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-book" style="color:#d1d5db;"></i></div>`;
        return `
        <div style="background:#fff;border:1.5px solid #fde68a;border-radius:10px;padding:10px;display:flex;gap:10px;align-items:flex-start;">
            ${thumb}
            <div style="flex:1;min-width:0;">
                <div style="font-size:.78rem;font-weight:700;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${bEsc(f.title)}</div>
                <div style="font-size:.7rem;color:#92400e;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${bEsc((f.author || '').slice(0, 40))}</div>
                ${f.year ? `<div style="font-size:.68rem;color:#9ca3af;margin-top:1px;">${bEsc(f.year)}</div>` : ''}
                <div style="display:flex;gap:5px;margin-top:8px;">
                    <button type="button" onclick="bUseFav(${i})"
                            style="font-size:.7rem;padding:3px 10px;background:#003087;color:#fff;border:none;border-radius:5px;cursor:pointer;font-weight:600;">
                        <i class="fas fa-check me-1"></i>Use
                    </button>
                    <button type="button" onclick="bDelFav(${i})"
                            style="font-size:.7rem;padding:3px 8px;background:none;border:1.5px solid #fde68a;border-radius:5px;cursor:pointer;color:#92400e;">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>`;
    }).join('');
}
function bUseFav(i) {
    const fav = bLoadFavs()[i]; if (!fav) return;
    _bresults.push(fav);
    bSelectResult(_bresults.length - 1);
}
function bDelFav(i) {
    const favs = bLoadFavs(); favs.splice(i, 1); bSaveFavs(favs); bRenderFavs();
}

// ── Override openAddBookModal (runs after app.js) ─────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    window.openAddBookModal = function (data) {
        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('addBookModal'));
        const set   = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
        set('bookId',           data?.id || '');
        set('bookTitle',        data?.title || '');
        set('bookAuthor',       data?.author || '');
        set('bookSubject',      data?.subject || '');
        set('bookCategory',     data?.category || '');
        set('bookGradeLevel',   data?.grade_level || '');
        set('bookIsbn',         data?.isbn || '');
        set('bookLocation',     data?.location_label || '');
        set('bookQtyTotal',     data?.quantity_total ?? 0);
        set('bookQtyAvailable', data?.quantity_available ?? 0);
        set('bookQtyDamaged',   data?.quantity_damaged ?? 0);
        set('bookQtyMissing',   data?.quantity_missing ?? 0);
        const cond = document.getElementById('bookCondition');
        if (cond) cond.value = data?.condition_status || 'good';

        document.getElementById('bookModalTitle').innerHTML = data?.id
            ? '<i class="fas fa-pencil me-2 text-primary"></i>Edit Book'
            : '<i class="fas fa-book me-2 text-primary"></i>Add / Import Book';

        document.getElementById('bimport-notice').style.display = 'none';

        // Edits go straight to form; new books start on ISBN search
        if (data?.id) {
            bTab('form');
        } else {
            bisbnClear();
            bTab('isbn');
        }

        // Refresh favorites badge
        const favs  = bLoadFavs();
        const badge = document.getElementById('bfav-badge');
        if (badge) { badge.textContent = favs.length; badge.style.display = favs.length ? 'inline' : 'none'; }

        _bresults = [];
        modal.show();
    };
});
</script>

<!-- ── Book QR Code Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="bookQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:400px;">
        <div class="modal-content">
            <div class="modal-header" style="padding:12px 18px;">
                <div style="display:flex;align-items:center;gap:9px;">
                    <i class="fas fa-qrcode" style="color:var(--primary);font-size:1rem;"></i>
                    <span id="bookQrModalTitle" style="font-weight:700;font-size:.9rem;">Book QR Code</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div id="bookQrCanvas" style="display:inline-block;padding:12px;background:#fff;border-radius:10px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.08);"></div>
                <div id="bookQrMeta" style="margin-top:14px;font-size:.78rem;color:var(--text-muted);line-height:1.6;"></div>
            </div>
            <div class="modal-footer" style="justify-content:center;gap:8px;padding:12px 18px;">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="downloadBookQr()">
                    <i class="fas fa-download me-1"></i>Download PNG
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="printBookQr()">
                    <i class="fas fa-print me-1"></i>Print
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
let _currentBookQrData = null;
let _currentQrInstance = null;

function openBookQrModal(bookId) {
    const book = (window.allBooks || []).find(b => Number(b.id) === Number(bookId));
    if (!book) return;

    const titleEl = document.getElementById('bookQrModalTitle');
    const canvas  = document.getElementById('bookQrCanvas');
    const meta    = document.getElementById('bookQrMeta');

    if (titleEl) titleEl.textContent = 'QR Code — ' + (book.title || 'Book');
    if (meta) meta.innerHTML = `
        <strong>${book.title || ''}</strong><br>
        ${book.author ? 'Author: ' + book.author + '<br>' : ''}
        ${book.isbn ? 'ISBN: ' + book.isbn + '<br>' : ''}
        ID: ${book.id}`;

    // QR payload: JSON with core book identifiers
    const payload = JSON.stringify({
        sys: 'sdo-lib',
        type: 'book',
        id: book.id,
        isbn: book.isbn || '',
        title: book.title || ''
    });

    _currentBookQrData = { book, payload };

    if (canvas) {
        canvas.innerHTML = '';
        _currentQrInstance = null;
        if (typeof QRCode !== 'undefined') {
            _currentQrInstance = new QRCode(canvas, {
                text: payload,
                width: 220,
                height: 220,
                colorDark: '#003087',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        } else {
            canvas.innerHTML = '<div style="font-size:.75rem;color:var(--danger);padding:20px;">QRCode library not loaded.</div>';
        }
    }

    bootstrap.Modal.getOrCreateInstance(document.getElementById('bookQrModal')).show();
}

function downloadBookQr() {
    const canvas = document.querySelector('#bookQrCanvas canvas') || document.querySelector('#bookQrCanvas img');
    if (!canvas || !_currentBookQrData) return;

    const book = _currentBookQrData.book;
    const fname = 'qr_' + (book.isbn || 'book_' + book.id) + '.png';

    if (canvas.tagName === 'CANVAS') {
        const link = document.createElement('a');
        link.download = fname;
        link.href = canvas.toDataURL('image/png');
        link.click();
    } else if (canvas.tagName === 'IMG') {
        const link = document.createElement('a');
        link.download = fname;
        link.href = canvas.src;
        link.click();
    }
}

function printBookQr() {
    const canvas = document.querySelector('#bookQrCanvas canvas') || document.querySelector('#bookQrCanvas img');
    if (!canvas || !_currentBookQrData) return;
    const book = _currentBookQrData.book;
    const src  = canvas.tagName === 'CANVAS' ? canvas.toDataURL('image/png') : canvas.src;
    const w = window.open('', '_blank', 'width=400,height=500');
    w.document.write(`<!DOCTYPE html><html><head><title>Book QR — ${book.title}</title>
    <style>body{font-family:sans-serif;text-align:center;padding:20px;}img{display:block;margin:0 auto 12px;border:1px solid #ddd;border-radius:8px;padding:8px;}h3{margin:0 0 4px;font-size:1rem;color:#003087;}p{margin:2px 0;font-size:.78rem;color:#555;}</style>
    </head><body onload="window.print();window.close();">
    <img src="${src}" width="200" height="200">
    <h3>${book.title || ''}</h3>
    ${book.author ? `<p>${book.author}</p>` : ''}
    ${book.isbn   ? `<p>ISBN: ${book.isbn}</p>` : ''}
    <p style="color:#888;font-size:.7rem;">SDO Quirino Library · ID ${book.id}</p>
    </body></html>`);
    w.document.close();
}

window.openBookQrModal  = openBookQrModal;
window.downloadBookQr   = downloadBookQr;
window.printBookQr      = printBookQr;
</script>

<!-- ══════════════════════════════════════════════════════════════════════
     BULK BOOK IMPORT MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="bulkImportModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" style="font-size:.93rem;font-weight:700;">
          <i class="fas fa-file-import me-2 text-primary"></i>Bulk Book Import
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <!-- Step 1: Upload -->
        <div id="bimport-step-upload">
          <p style="font-size:.82rem;color:#6b7280;margin-bottom:14px;">
            Upload a <strong>CSV</strong> or <strong>Excel (.xlsx/.xls)</strong> file.
            The header row is detected automatically — even when title/banner rows sit above it —
            and columns are auto-matched to inventory fields. You can remap anything before importing.
          </p>
          <!-- Drop zone -->
          <div id="bimport-dropzone"
               style="border:2px dashed #c7d2fe;border-radius:14px;padding:36px 20px;text-align:center;cursor:pointer;transition:.2s;background:#f5f3ff;"
               onclick="document.getElementById('bimport-file-input').click()"
               ondragover="event.preventDefault();this.style.borderColor='#6366f1'"
               ondragleave="this.style.borderColor='#c7d2fe'"
               ondrop="bimportHandleDrop(event)">
            <i class="fas fa-cloud-upload-alt" style="font-size:2.2rem;color:#a5b4fc;display:block;margin-bottom:10px;"></i>
            <div style="font-weight:600;font-size:.88rem;color:#4f46e5;">Click or drag a file here</div>
            <div style="font-size:.76rem;color:#9ca3af;margin-top:4px;">CSV, Excel (.xlsx / .xls) · Max 10 MB</div>
          </div>
          <input type="file" id="bimport-file-input" accept=".csv,.xlsx,.xls" style="display:none;" onchange="bimportHandleFile(this.files[0])">
          <div id="bimport-file-info" style="margin-top:10px;font-size:.8rem;color:#374151;display:none;"></div>
          <div id="bimport-parse-error" class="alert alert-danger mt-2 mb-0 py-2" style="font-size:.8rem;display:none;"></div>
        </div>

        <!-- Step 2: Field mapping + preview -->
        <div id="bimport-step-preview" style="display:none;">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <span style="font-size:.85rem;font-weight:600;">Field Mapping &amp; Preview</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="bimportReset()">
              <i class="fas fa-arrow-left me-1"></i>Change File
            </button>
          </div>

          <!-- Mapping row -->
          <div class="card mb-3">
            <div class="card-body p-2">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:4px;">
                <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;">Map Columns to Fields</span>
                <span id="bimport-map-info" style="font-size:.7rem;color:#6366f1;font-weight:600;"></span>
              </div>
              <div id="bimport-mapping-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;"></div>
            </div>
          </div>

          <!-- Required-field warning -->
          <div id="bimport-required-warning" class="alert alert-danger py-2 mb-3" style="font-size:.78rem;display:none;"></div>

          <!-- Duplicate mode -->
          <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
            <span style="font-size:.8rem;font-weight:600;">On Duplicate:</span>
            <label style="font-size:.8rem;cursor:pointer;"><input type="radio" name="bimport_dup" value="skip" checked class="me-1">Skip</label>
            <label style="font-size:.8rem;cursor:pointer;"><input type="radio" name="bimport_dup" value="update" class="me-1">Update quantities</label>
            <label style="font-size:.8rem;cursor:pointer;"><input type="radio" name="bimport_dup" value="create" class="me-1">Create new entry</label>
          </div>

          <!-- Preview table -->
          <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:6px;">
            Preview <span id="bimport-row-count" style="font-weight:400;color:#374151;"></span>
          </div>
          <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
            <table class="table table-sm table-hover mb-0" style="font-size:.78rem;">
              <thead class="table-light sticky-top">
                <tr>
                  <th>#</th><th>Title</th><th>Author</th><th>ISBN</th><th>Subject</th>
                  <th>Category</th><th>Grade</th><th>Location</th><th>Qty</th><th>Condition</th>
                </tr>
              </thead>
              <tbody id="bimport-preview-body"></tbody>
            </table>
          </div>
          <div id="bimport-validation-warnings" class="mt-2" style="display:none;"></div>
        </div>

        <!-- Step 3: Result -->
        <div id="bimport-step-result" style="display:none;">
          <div id="bimport-result-content"></div>
        </div>

      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary btn-sm" id="bimport-confirm-btn" style="display:none;"
                onclick="bimportConfirm()">
          <i class="fas fa-file-import me-1"></i>Import Records
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     DELIVERY DOCUMENT ATTACH MODAL
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="deliveryDocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title" style="font-size:.92rem;font-weight:700;">
          <i class="fas fa-paperclip me-2 text-primary"></i>Delivery Documents
          <span id="ddoc-delivery-label" style="font-weight:400;color:#6b7280;"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Existing docs list -->
        <div id="ddoc-list" style="margin-bottom:16px;"></div>

        <!-- Upload new doc -->
        <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;">
          <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-bottom:10px;">Attach New Document</div>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label fw-semibold small mb-1">Label / Description</label>
              <input type="text" id="ddoc-label" class="form-control form-control-sm"
                     placeholder="e.g. Purchase Order, Delivery Receipt">
            </div>
            <div class="col-md-7">
              <label class="form-label fw-semibold small mb-1">File</label>
              <input type="file" id="ddoc-file" class="form-control form-control-sm"
                     accept=".pdf,.xlsx,.xls,.csv,.docx,.doc,.jpg,.jpeg,.png,.gif,.webp">
              <div class="form-text">PDF, Excel, Word, Images · Max 20 MB</div>
            </div>
          </div>
          <button class="btn btn-primary btn-sm mt-3" onclick="ddocUpload()">
            <i class="fas fa-upload me-1"></i>Upload &amp; Attach
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// ════════════════════════════════════════════════════════════════════════
//  BULK IMPORT MODULE
// ════════════════════════════════════════════════════════════════════════
(function () {
  'use strict';

  const BOOK_FIELDS = [
    { key: 'title',          label: 'Title *' },
    { key: 'author',         label: 'Author' },
    { key: 'isbn',           label: 'ISBN' },
    { key: 'subject',        label: 'Subject' },
    { key: 'category',       label: 'Category' },
    { key: 'grade_level',    label: 'Grade Level' },
    { key: 'location_label', label: 'Location / Shelf' },
    { key: 'quantity',       label: 'Quantity' },
    { key: 'condition',      label: 'Condition' },
    { key: 'ignore',         label: '— Ignore —' },
  ];

  let _headers  = [];
  let _rawRows  = [];
  let _mappedRows = [];
  let _sourceFile = '';
  let _colMap   = {}; // { colIndex: fieldKey }
  let _headerRowIdx = 0;
  let _lastExcluded = 0;

  // ── Public entry points ─────────────────────────────────────────────
  window.openBulkImportModal = function () {
    bimportReset();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('bulkImportModal')).show();
  };

  window.bimportReset = function () {
    _headers = []; _rawRows = []; _mappedRows = []; _colMap = {};
    _headerRowIdx = 0; _lastExcluded = 0;
    byId('bimport-step-upload').style.display  = '';
    byId('bimport-step-preview').style.display = 'none';
    byId('bimport-step-result').style.display  = 'none';
    byId('bimport-confirm-btn').style.display  = 'none';
    byId('bimport-confirm-btn').disabled       = false;
    byId('bimport-file-info').style.display    = 'none';
    byId('bimport-parse-error').style.display  = 'none';
    byId('bimport-file-input').value           = '';
    const warn = byId('bimport-required-warning');
    if (warn) { warn.style.display = 'none'; warn.innerHTML = ''; }
    const mapInfo = byId('bimport-map-info');
    if (mapInfo) mapInfo.textContent = '';
  };

  // ── Drop / file select handlers ─────────────────────────────────────
  window.bimportHandleDrop = function (e) {
    e.preventDefault();
    document.getElementById('bimport-dropzone').style.borderColor = '#c7d2fe';
    const file = e.dataTransfer.files[0];
    if (file) bimportHandleFile(file);
  };

  window.bimportHandleFile = function (file) {
    if (!file) return;
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['csv','xlsx','xls'].includes(ext)) {
      showParseError('Please upload a CSV or Excel file (.csv, .xlsx, .xls).');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      showParseError('File is too large (max 10 MB).');
      return;
    }
    _sourceFile = file.name;
    byId('bimport-parse-error').style.display = 'none';

    const info = byId('bimport-file-info');
    info.style.display = '';
    info.innerHTML = `<i class="fas fa-file-alt me-1 text-primary"></i><strong>${esc(file.name)}</strong> — parsing…`;

    if (ext === 'csv') {
      const reader = new FileReader();
      reader.onload = ev => {
        try { processRawMatrix(parseCSVText(String(ev.target.result))); }
        catch (err) { showParseError('Failed to parse CSV file: ' + err.message); }
      };
      reader.onerror = () => showParseError('Could not read the file.');
      reader.readAsText(file);
    } else {
      if (typeof XLSX === 'undefined') {
        showParseError('Excel parsing library not loaded (no internet connection?). Save the sheet as CSV and upload that instead.');
        return;
      }
      const reader = new FileReader();
      reader.onload = ev => {
        try {
          const wb = XLSX.read(new Uint8Array(ev.target.result), { type: 'array' });
          let data = [];
          for (const name of wb.SheetNames) {   // first sheet that actually has rows
            data = XLSX.utils.sheet_to_json(wb.Sheets[name], { header: 1, raw: false, defval: '' });
            if (data.some(r => r.some(c => String(c).trim() !== ''))) break;
          }
          processRawMatrix(data);
        } catch (err) {
          showParseError('Failed to parse Excel file: ' + err.message);
        }
      };
      reader.onerror = () => showParseError('Could not read the file.');
      reader.readAsArrayBuffer(file);
    }
  };

  // ── CSV parser (RFC-4180: quoted fields, "" escapes, CRLF/CR/LF) ────
  function parseCSVText(text) {
    if (text.charCodeAt(0) === 0xFEFF) text = text.slice(1);   // strip BOM

    // Sniff delimiter on the first line, ignoring quoted sections
    const nlPos = text.indexOf('\n');
    const firstLine = nlPos === -1 ? text : text.slice(0, nlPos);
    const counts = { ',': 0, ';': 0, '\t': 0 };
    let sq = false;
    for (const ch of firstLine) {
      if (ch === '"') sq = !sq;
      else if (!sq && ch in counts) counts[ch]++;
    }
    const ranked = Object.entries(counts).sort((a, b) => b[1] - a[1]);
    const delim  = ranked[0][1] > 0 ? ranked[0][0] : ',';

    const rows = [];
    let row = [], cell = '', inQ = false;
    for (let i = 0; i < text.length; i++) {
      const c = text[i];
      if (inQ) {
        if (c === '"') {
          if (text[i + 1] === '"') { cell += '"'; i++; }
          else inQ = false;
        } else cell += c;
      } else if (c === '"') {
        inQ = true;
      } else if (c === delim) {
        row.push(cell); cell = '';
      } else if (c === '\n' || c === '\r') {
        if (c === '\r' && text[i + 1] === '\n') i++;           // CRLF
        row.push(cell); rows.push(row); row = []; cell = '';
      } else {
        cell += c;
      }
    }
    if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
    return rows;
  }

  // ── Header matching ─────────────────────────────────────────────────
  // Normalised variants per field. Specific fields are listed before
  // generic ones so "Author Name" can never be stolen by the title matcher.
  const FIELD_VARIANTS = {
    isbn:           ['isbn13','isbn10','isbnno','isbnnumber','isbn'],
    quantity:       ['quantity','qty','noofcopies','copies','stock','onhand','count'],
    grade_level:    ['gradelevel','yearlevel','grade','level'],
    location_label: ['locationlabel','shelflocation','shelflabel','callnumber','callno','accessionnumber','accessionno','accession','location','shelf','section'],
    condition:      ['bookcondition','condition','state'],
    author:         ['authorname','authors','author','writtenby','writer'],
    subject:        ['subjectarea','subject','topic','discipline'],
    category:       ['booktype','category','genre','classification','format'],
    title:          ['booktitle','titleofbook','bookname','title'],
  };

  function normHeader(s) {
    return String(s ?? '').toLowerCase().replace(/[^a-z0-9]/g, '');
  }

  function matchField(header, used) {
    const n = normHeader(header);
    if (!n) return null;
    for (const [field, variants] of Object.entries(FIELD_VARIANTS)) {       // pass 1: exact
      if (!used.has(field) && variants.includes(n)) return field;
    }
    for (const [field, variants] of Object.entries(FIELD_VARIANTS)) {       // pass 2: contains
      if (!used.has(field) && variants.some(v => n.includes(v))) return field;
    }
    return null;
  }

  // Real-world inventory sheets often have banner/title rows above the
  // header. Score the first rows by distinct field matches; pick the best.
  function detectHeaderRow(matrix) {
    let best = { idx: 0, score: 0 };
    const limit = Math.min(matrix.length, 10);
    for (let i = 0; i < limit; i++) {
      const used = new Set();
      for (const cell of (matrix[i] || [])) {
        const f = matchField(cell, used);
        if (f) used.add(f);
      }
      if (used.size > best.score) best = { idx: i, score: used.size };
    }
    return best;
  }

  // ── Process parsed matrix ───────────────────────────────────────────
  function processRawMatrix(matrix) {
    matrix = (matrix || []).map(r => (r || []).map(c => String(c ?? '').trim()));
    const nonEmpty = matrix.filter(r => r.some(c => c !== ''));
    if (nonEmpty.length < 2) { showParseError('File appears empty or has no data rows.'); return; }

    // Detect the real header row (skips banner/title rows above it)
    const detected = detectHeaderRow(nonEmpty);
    _headerRowIdx  = detected.idx;
    _headers       = nonEmpty[_headerRowIdx].map(h => h || '(blank)');
    _rawRows       = nonEmpty.slice(_headerRowIdx + 1).filter(r => r.some(c => c !== ''));

    if (!_rawRows.length) { showParseError('A header row was found, but no data rows follow it.'); return; }

    // Auto-map columns (exact match first, then fuzzy contains-match)
    _colMap = {};
    const used = new Set();
    _headers.forEach((h, i) => {
      const f = matchField(h, used);
      if (f) { _colMap[i] = f; used.add(f); }
    });

    const mapInfo = byId('bimport-map-info');
    if (mapInfo) {
      mapInfo.textContent =
        `Header detected at file row ${_headerRowIdx + 1} · auto-matched ${Object.keys(_colMap).length} of ${_headers.length} columns`;
    }

    renderMappingUI();
    renderPreview();

    byId('bimport-step-upload').style.display  = 'none';
    byId('bimport-step-preview').style.display = '';
    byId('bimport-confirm-btn').style.display  = '';

    const info = byId('bimport-file-info');
    info.innerHTML = `<i class="fas fa-check-circle me-1 text-success"></i><strong>${esc(_sourceFile)}</strong> — ${_rawRows.length} data rows detected.`;
  }

  // ── Mapping UI ──────────────────────────────────────────────────────
  function renderMappingUI() {
    const grid = byId('bimport-mapping-grid');
    grid.innerHTML = _headers.map((h, i) => {
      const currentVal = _colMap[i] || 'ignore';
      const mapped     = currentVal !== 'ignore';
      const opts = BOOK_FIELDS.map(f =>
        `<option value="${f.key}" ${currentVal === f.key ? 'selected' : ''}>${f.label}</option>`
      ).join('');
      return `<div>
        <div style="font-size:.7rem;font-weight:600;color:#374151;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(h)}">${esc(h)}</div>
        <select class="form-select form-select-sm" style="font-size:.74rem;border-left:3px solid ${mapped ? '#22c55e' : '#d1d5db'};"
                onchange="bimportUpdateMap(${i},this.value)">${opts}</select>
      </div>`;
    }).join('');
  }

  window.bimportUpdateMap = function (colIdx, fieldKey) {
    if (fieldKey === 'ignore') {
      delete _colMap[colIdx];
    } else {
      // A field can only be sourced from one column — unassign any other
      for (const k in _colMap) { if (_colMap[k] === fieldKey) delete _colMap[k]; }
      _colMap[colIdx] = fieldKey;
    }
    renderMappingUI();   // refresh highlights and de-duplicated selections
    renderPreview();
  };

  function hasTitleMapping() {
    return Object.values(_colMap).includes('title');
  }

  // Required-field banner + import button gating
  function updateImportState(emptyTitleCount) {
    const btn  = byId('bimport-confirm-btn');
    const warn = byId('bimport-required-warning');
    const titleOk = hasTitleMapping();

    if (btn) btn.disabled = !titleOk;
    if (!warn) return;

    if (!titleOk) {
      warn.style.display = '';
      warn.className = 'alert alert-danger py-2 mb-3';
      warn.style.fontSize = '.78rem';
      warn.innerHTML = '<i class="fas fa-circle-exclamation me-1"></i><strong>Title is required.</strong> ' +
        'No column is mapped to <strong>Title</strong> yet — select it in the mapping grid above. Import stays disabled until then.';
    } else if (emptyTitleCount > 0) {
      warn.style.display = '';
      warn.className = 'alert alert-warning py-2 mb-3';
      warn.style.fontSize = '.78rem';
      warn.innerHTML = `<i class="fas fa-triangle-exclamation me-1"></i>${emptyTitleCount} row(s) have an empty Title cell and will be skipped during import.`;
    } else {
      warn.style.display = 'none';
      warn.innerHTML = '';
    }
  }

  // ── Preview table ───────────────────────────────────────────────────
  function buildMappedRows() {
    return _rawRows.map(row => {
      const obj = {};
      for (const [colIdx, fieldKey] of Object.entries(_colMap)) {
        obj[fieldKey] = String(row[parseInt(colIdx)] ?? '').trim();
      }
      return obj;
    });
  }

  function renderPreview() {
    _mappedRows = buildMappedRows();
    const tbody  = byId('bimport-preview-body');
    const count  = byId('bimport-row-count');
    const warns  = byId('bimport-validation-warnings');
    const titleMapped = hasTitleMapping();
    const emptyTitle  = titleMapped ? _mappedRows.filter(r => !r.title).length : 0;

    count.textContent = `(${_mappedRows.length} rows)`;
    tbody.innerHTML = _mappedRows.slice(0, 50).map((r, i) => {
      const rowEmptyTitle = titleMapped && !r.title;
      const titleCell = !titleMapped
        ? '<span style="color:#9ca3af;">—</span>'
        : (r.title ? esc(r.title) : '<span class="text-danger" style="font-size:.72rem;">(empty — row will be skipped)</span>');
      return `<tr style="${rowEmptyTitle ? 'background:#fef2f2;' : ''}">
        <td style="color:#9ca3af;">${i + 1}</td>
        <td>${titleCell}</td>
        <td>${esc(r.author||'')}</td>
        <td>${esc(r.isbn||'')}</td>
        <td>${esc(r.subject||'')}</td>
        <td>${esc(r.category||'')}</td>
        <td>${esc(r.grade_level||'')}</td>
        <td>${esc(r.location_label||'')}</td>
        <td>${esc(r.quantity||'0')}</td>
        <td>${esc(r.condition||'good')}</td>
      </tr>`;
    }).join('');

    if (_mappedRows.length > 50) {
      tbody.innerHTML += `<tr><td colspan="10" class="text-center text-muted py-2" style="font-size:.75rem;">… and ${_mappedRows.length - 50} more rows</td></tr>`;
    }

    warns.style.display = 'none';
    warns.innerHTML = '';
    updateImportState(emptyTitle);
  }

  // ── Confirm import ──────────────────────────────────────────────────
  window.bimportConfirm = async function () {
    if (!hasTitleMapping()) {
      updateImportState(0);
      showToast('Map the Title column first — import requires it.', 'error');
      return;
    }
    const allRows   = buildMappedRows();
    const validRows = allRows.filter(r => r.title);
    _lastExcluded   = allRows.length - validRows.length;
    if (!validRows.length) { showToast('No rows with a Title to import.', 'error'); return; }

    const dupMode = document.querySelector('input[name="bimport_dup"]:checked')?.value || 'skip';
    const btn     = byId('bimport-confirm-btn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Importing…';

    try {
      const fd = new FormData();
      fd.append('action', 'books_bulk_import');
      fd.append('rows', JSON.stringify(validRows));
      fd.append('duplicate_mode', dupMode);
      fd.append('source_file', _sourceFile);

      const res = await fetch('api/library_handler.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': csrfToken() },
      });
      const body = await res.json();

      byId('bimport-step-preview').style.display = 'none';
      byId('bimport-step-result').style.display  = '';
      btn.style.display = 'none';

      if (body.success) {
        const d = body.data;
        byId('bimport-result-content').innerHTML = `
          <div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i><strong>Import Complete!</strong></div>
          ${_lastExcluded ? `<div class="alert alert-secondary py-2" style="font-size:.78rem;">${_lastExcluded} row(s) without a Title were excluded before import.</div>` : ''}
          <div class="row g-2 mb-3">
            <div class="col-6 col-md-3"><div class="card text-center py-3"><div style="font-size:1.4rem;font-weight:700;color:#16a34a;">${d.imported}</div><div style="font-size:.74rem;color:#6b7280;">Imported</div></div></div>
            <div class="col-6 col-md-3"><div class="card text-center py-3"><div style="font-size:1.4rem;font-weight:700;color:#d97706;">${d.duplicates}</div><div style="font-size:.74rem;color:#6b7280;">Duplicates</div></div></div>
            <div class="col-6 col-md-3"><div class="card text-center py-3"><div style="font-size:1.4rem;font-weight:700;color:#9ca3af;">${d.skipped}</div><div style="font-size:.74rem;color:#6b7280;">Skipped</div></div></div>
            <div class="col-6 col-md-3"><div class="card text-center py-3"><div style="font-size:1.4rem;font-weight:700;color:#dc2626;">${d.errors}</div><div style="font-size:.74rem;color:#6b7280;">Errors</div></div></div>
          </div>`;
        if (d.errors > 0 && d.details) {
          const errRows = d.details.filter(r => r.status === 'error').slice(0, 20);
          byId('bimport-result-content').innerHTML += `
            <div class="alert alert-warning py-2" style="font-size:.78rem;">
              <strong>Rows with errors:</strong><br>
              ${errRows.map(r => `Row ${r.row}: ${esc(r.reason)}`).join('<br>')}
            </div>`;
        }
        if (typeof loadBooks === 'function') loadBooks();
      } else {
        byId('bimport-result-content').innerHTML =
          `<div class="alert alert-danger"><i class="fas fa-xmark-circle me-2"></i>${esc(body.message || 'Import failed.')}</div>`;
      }
    } catch (err) {
      showParseError('Network error: ' + err.message);
    } finally {
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-file-import me-1"></i>Import Records';
    }
  };

  function showParseError(msg) {
    const el = byId('bimport-parse-error');
    el.textContent = msg;
    el.style.display = '';
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  function byId(id) { return document.getElementById(id); }

})();

// ════════════════════════════════════════════════════════════════════════
//  DELIVERY DOCUMENT MODULE
// ════════════════════════════════════════════════════════════════════════
let _ddocDeliveryId = null;

window.openDeliveryDocs = function (deliveryId, label) {
  _ddocDeliveryId = deliveryId;
  const el = document.getElementById('ddoc-delivery-label');
  if (el) el.textContent = label ? ' — ' + label : '';
  document.getElementById('ddoc-list').innerHTML =
    '<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Loading…</div>';
  document.getElementById('ddoc-label').value = '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('deliveryDocModal')).show();
  ddocLoadList();
};

function ddocLoadList() {
  fetch(`api/library_handler.php?action=delivery_get_docs&delivery_id=${_ddocDeliveryId}`, { credentials:'same-origin' })
    .then(r => r.json())
    .then(body => {
      const list = document.getElementById('ddoc-list');
      if (!body.success || !body.data.length) {
        list.innerHTML = '<p class="text-muted small mb-0">No documents attached yet.</p>';
        return;
      }
      const iconMap = { pdf:'fa-file-pdf text-danger', xlsx:'fa-file-excel text-success', xls:'fa-file-excel text-success',
                        csv:'fa-file-csv text-success', docx:'fa-file-word text-primary', doc:'fa-file-word text-primary',
                        jpg:'fa-file-image text-warning', jpeg:'fa-file-image text-warning', png:'fa-file-image text-warning' };
      list.innerHTML = body.data.map(d => {
        const icon = iconMap[d.file_type] || 'fa-file text-secondary';
        const size = d.file_size ? (d.file_size / 1024).toFixed(1) + ' KB' : '';
        return `<div class="d-flex align-items-center gap-2 p-2 rounded mb-1" style="background:#f8fafc;">
          <i class="fas ${icon}" style="font-size:1.1rem;flex-shrink:0;"></i>
          <div style="flex:1;min-width:0;">
            <div style="font-size:.82rem;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(d.label || d.file_name)}</div>
            <div style="font-size:.72rem;color:#9ca3af;">${escapeHtml(d.file_name)} · ${size} · ${escapeHtml(d.uploaded_by_name||'')}</div>
          </div>
          <a href="api/library_handler.php?action=file_serve&download=1&ref=${encodeURIComponent(d.file_path)}" target="_blank" class="btn btn-xs btn-outline-primary btn-sm py-0 px-2" style="font-size:.7rem;white-space:nowrap;">
            <i class="fas fa-download me-1"></i>Download
          </a>
          <button class="btn btn-xs btn-outline-danger btn-sm py-0 px-2" style="font-size:.7rem;" onclick="ddocDelete(${d.id})">
            <i class="fas fa-trash"></i>
          </button>
        </div>`;
      }).join('');
    })
    .catch(() => {
      document.getElementById('ddoc-list').innerHTML = '<p class="text-danger small mb-0">Failed to load documents.</p>';
    });
}

window.ddocDelete = function (id) {
  if (!confirm('Remove this document?')) return;
  const fd = new FormData();
  fd.append('action', 'delivery_delete_doc');
  fd.append('id', id);
  fetch('api/library_handler.php', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-CSRF-Token': csrfToken()} })
    .then(r => r.json()).then(b => { if (b.success) ddocLoadList(); else showToast(b.message||'Error','error'); });
};

window.ddocUpload = function () {
  const fileInput = document.getElementById('ddoc-file');
  const label     = document.getElementById('ddoc-label').value.trim();
  if (!fileInput.files[0]) { showToast('Please select a file.', 'error'); return; }

  const fd = new FormData();
  fd.append('action', 'delivery_attach_doc');
  fd.append('delivery_id', _ddocDeliveryId);
  fd.append('label', label);
  fd.append('files[]', fileInput.files[0]);

  fetch('api/library_handler.php', { method:'POST', body:fd, credentials:'same-origin', headers:{'X-CSRF-Token': csrfToken()} })
    .then(r => r.json()).then(b => {
      if (b.success) {
        showToast('Document attached!');
        fileInput.value = '';
        document.getElementById('ddoc-label').value = '';
        ddocLoadList();
      } else {
        showToast(b.message || 'Upload failed.', 'error');
      }
    }).catch(() => showToast('Network error.', 'error'));
};
</script>