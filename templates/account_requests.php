<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Account Requests</h2>
</div>
<div class="card">
    <div class="card-body p-0">
        <?php
        $stmt = $pdo->query("SELECT id, username, full_name, role, classification, institutional_id, created_at
                             FROM users WHERE status = 'pending' ORDER BY created_at ASC");
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // The ID label mirrors the registration form so the approver knows what to verify
        $idLabels = [
            'school' => 'DepEd School ID', 'child' => 'Student ID', 'teen' => 'Student ID',
            'individual' => 'Employee ID', 'professional' => 'Employee ID',
            'private_institution' => 'Institutional ID',
        ];
        $clsLabels = [
            'child' => 'Child', 'teen' => 'Teen', 'individual' => 'Adult',
            'school' => 'School', 'professional' => 'Professional',
            'private_institution' => 'Private Org', 'deped' => 'DepEd',
        ];
        ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Full Name</th><th>Username</th><th>Account Type</th><th>Institutional ID</th><th>Requested Role</th><th>Date Requested</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No pending account requests.</td></tr>
                <?php else: foreach ($requests as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['full_name']) ?></td>
                        <td><?= htmlspecialchars($req['username']) ?></td>
                        <td><span class="badge bg-info"><?= htmlspecialchars($clsLabels[$req['classification'] ?? ''] ?? ucfirst((string)($req['classification'] ?? '—'))) ?></span></td>
                        <td>
                            <?php if (!empty($req['institutional_id'])): ?>
                                <div style="font-weight:700;font-family:monospace;font-size:.88rem;"><?= htmlspecialchars($req['institutional_id']) ?></div>
                                <div class="text-muted" style="font-size:.68rem;"><?= htmlspecialchars($idLabels[$req['classification'] ?? ''] ?? 'Institutional ID') ?> — verify before approving</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars(ucfirst($req['role'])) ?></span></td>
                        <td><?= htmlspecialchars($req['created_at']) ?></td>
                        <td>
                            <form method="post" action="account_action.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                <input type="hidden" name="action" value="approve">
                                <select name="role" class="form-select form-select-sm d-inline-block me-2" style="width:auto;">
                                    <?php
                                    $role = $req['role'] ?? 'viewer';
                                    $allowed = ['viewer' => 'Viewer', 'staff' => 'Staff', 'admin' => 'Admin'];
                                    foreach ($allowed as $value => $label):
                                    ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= $role === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i> Approve</button>
                            </form>
                            <form method="post" action="account_action.php" class="d-inline" onsubmit="return confirm('Reject this account request?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button class="btn btn-danger btn-sm"><i class="fas fa-times me-1"></i> Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
