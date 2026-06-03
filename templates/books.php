<!-- Page header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Inventory</h1>
        <p class="text-muted mb-0" style="font-size:.8rem;">Manage the library's book collection</p>
    </div>
    <div class="page-actions">
        <div class="input-group input-group-sm" style="width:220px;">
            <span class="input-group-text"><i class="fas fa-search" style="font-size:.7rem;"></i></span>
            <input type="text" class="form-control" id="book-search-main"
                   placeholder="Search books..." oninput="filterBooksMainTable()">
        </div>
        <select id="book-subject-filter-main" class="form-select form-select-sm" style="width:auto;" onchange="filterBooksMainTable()">
            <option value="">All Subjects</option>
        </select>
        <?php if (!empty($isStaff)): ?>
        <button class="btn btn-primary btn-sm" onclick="openAddBookModal()">
            <i class="fas fa-plus me-1"></i> Add Book
        </button>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Author</th>
                        <th>Subject</th>
                        <th>Grade</th>
                        <th>Location</th>
                        <th>Available</th>
                        <th>Total</th>
                        <th>Condition</th>
                        <?php if (!empty($isStaff)): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="books-table-main">
                    <tr><td colspan="<?= !empty($isStaff) ? 9 : 8 ?>" class="text-center text-muted py-5" style="font-size:.82rem;">
                        <i class="fas fa-spinner fa-spin me-2"></i> Loading books...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>