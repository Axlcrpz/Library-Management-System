<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Account Requests</h2>
</div>
<div class="card">
    <div class="card-body p-0">
        <?php
        $stmt = $pdo->query("SELECT id, username, full_name, role, created_at FROM users WHERE status = 'pending' ORDER BY created_at ASC");
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead><tr><th>Full Name</th><th>Username</th><th>Requested Role</th><th>Date Requested</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!$requests): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No pending account requests.</td></tr>
                <?php else: foreach ($requests as $req): ?>
                    <tr>
                        <td><?= htmlspecialchars($req['full_name']) ?></td>
                        <td><?= htmlspecialchars($req['username']) ?></td>
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
