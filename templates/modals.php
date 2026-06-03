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
                        <label class="form-label fw-semibold">Upload PDF</label>
                        <input type="file" class="form-control form-control-sm" name="files[]" multiple accept="application/pdf">
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
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Borrower Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" name="borrower_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact</label>
                        <input type="text" class="form-control form-control-sm" name="borrower_contact">
                    </div>
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
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bookModalTitle">Add New Book</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="book-form">
                    <input type="hidden" id="bookId" name="id">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="bookTitle" name="title" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Author</label>
                            <input type="text" class="form-control form-control-sm" id="bookAuthor" name="author">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Subject</label>
                            <input type="text" class="form-control form-control-sm" id="bookSubject" name="subject" placeholder="e.g. Math, Science">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Category</label>
                            <input type="text" class="form-control form-control-sm" id="bookCategory" name="category" placeholder="e.g. Textbook, Reference">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Grade Level</label>
                            <input type="text" class="form-control form-control-sm" id="bookGradeLevel" name="grade_level" placeholder="e.g. Grade 1, All">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">ISBN</label>
                            <input type="text" class="form-control form-control-sm" id="bookIsbn" name="isbn">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Location Label</label>
                            <input type="text" class="form-control form-control-sm" id="bookLocation" name="location_label" placeholder="e.g. Math Corner, Grade 1 Section">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Total Qty</label>
                            <input type="number" class="form-control form-control-sm" id="bookQtyTotal" name="quantity_total" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Available Qty</label>
                            <input type="number" class="form-control form-control-sm" id="bookQtyAvailable" name="quantity_available" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Damaged Qty</label>
                            <input type="number" class="form-control form-control-sm" id="bookQtyDamaged" name="quantity_damaged" min="0" value="0">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Missing Qty</label>
                            <input type="number" class="form-control form-control-sm" id="bookQtyMissing" name="quantity_missing" min="0" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Condition</label>
                        <select class="form-select form-select-sm" id="bookCondition" name="condition_status">
                            <option value="good">Good</option>
                            <option value="fair">Fair</option>
                            <option value="poor">Poor</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-book-btn">Save Book</button>
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
                <div class="mb-3">
                    <label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control form-control-sm" id="announcementTitle" placeholder="Announcement title">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
                    <textarea class="form-control form-control-sm" id="announcementBody" rows="4" placeholder="Write your announcement..."></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label fw-semibold">Status</label>
                    <select class="form-select form-select-sm" id="announcementStatus">
                        <option value="1">Publish</option>
                        <option value="0">Save as Draft</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-announcement-btn">Save Announcement</button>
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

                    <!-- BORROWER SEARCH -->
                    <div class="card mb-3 border-primary">
                        <div class="card-header bg-primary text-white py-2">
                            <i class="fas fa-search me-2"></i> Search Borrower
                        </div>
                        <div class="card-body">
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Search by Name or LRN</label>
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
                                        <span class="text-muted small ms-2" id="selectedBorrowerLrn"></span>
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
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Return Notes</label>
                            <input type="text" class="form-control form-control-sm" name="return_notes">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Fine Amount</label>
                            <input type="number" class="form-control form-control-sm" name="fine_amount" min="0" step="0.01">
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
