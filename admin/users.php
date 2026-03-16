<?php
$page_title = 'User Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid    = (int)($_POST['user_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');

    if ($uid && $uid !== $_SESSION['user_id']) {
        switch ($action) {
            case 'activate':
                $conn->query("UPDATE users SET status='active' WHERE id=$uid");
                break;
            case 'suspend':
                $conn->query("UPDATE users SET status='suspended' WHERE id=$uid");
                break;
            case 'delete':
                $conn->query("UPDATE users SET deleted_at=NOW() WHERE id=$uid");
                break;
            case 'reset_wallet':
                $conn->query("UPDATE users SET wallet_balance=0 WHERE id=$uid");
                break;
            case 'add_wallet':
                $amount = (float)($_POST['amount'] ?? 0);
                if ($amount > 0) {
                    $conn->query("UPDATE users SET wallet_balance = wallet_balance + $amount WHERE id=$uid");
                    // Log
                    $stmt = $conn->prepare("
                        SELECT wallet_balance FROM users WHERE id = ?
                    ");
                    $stmt->bind_param('i', $uid);
                    $stmt->execute();
                    $bal = $stmt->get_result()->fetch_assoc()['wallet_balance'];
                    $stmt->close();

                    $desc = "Admin credit by {$_SESSION['full_name']}";
                    $conn->query("
                        INSERT INTO wallet_transactions
                            (user_id, type, amount, balance_after, description, ref_type)
                        VALUES ($uid, 'credit', $amount, $bal, '$desc', 'admin')
                    ");
                }
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'], "User $action", 'user', $uid);
        set_flash('success', "User $action successful.");
    }
    redirect('admin/users.php?' . http_build_query(array_diff_key($_GET, ['_' => ''])));
}

// Export CSV
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $users_all = $conn->query("
        SELECT u.id, u.full_name, u.email, u.phone, r.name AS role,
               u.status, u.wallet_balance, u.created_at
        FROM users u JOIN roles r ON u.role_id = r.id
        WHERE u.deleted_at IS NULL
        ORDER BY u.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Name','Email','Phone','Role','Status','Wallet','Joined']);
    foreach ($users_all as $u) {
        fputcsv($out, [
            $u['id'], $u['full_name'], $u['email'], $u['phone'],
            $u['role'], $u['status'],
            $u['wallet_balance'], $u['created_at']
        ]);
    }
    fclose($out);
    exit();
}

// Search / filter
$search   = sanitize($_GET['search']   ?? '');
$role_f   = sanitize($_GET['role']     ?? '');
$status_f = sanitize($_GET['status']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where  = ["u.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($search) {
    $where[]  = "(u.full_name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}
if ($role_f) {
    $where[]  = "r.slug = ?";
    $params[] = $role_f;
    $types   .= 's';
}
if ($status_f) {
    $where[]  = "u.status = ?";
    $params[] = $status_f;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

// Count
$stmt = $conn->prepare("
    SELECT COUNT(*) as c FROM users u JOIN roles r ON u.role_id=r.id WHERE $where_sql
");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// Fetch
$lp = array_merge($params, [$per_page, $offset]);
$lt = $types . 'ii';
$stmt = $conn->prepare("
    SELECT u.*, r.name AS role_name, r.slug AS role_slug,
           (SELECT COUNT(*) FROM bookings WHERE user_id=u.id AND payment_status='paid')
           AS booking_count
    FROM users u JOIN roles r ON u.role_id=r.id
    WHERE $where_sql
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($lt, ...$lp);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// All roles for filter
$roles = $conn->query("SELECT * FROM roles ORDER BY id")->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">User Management</h5>
            <a href="?action=export" class="btn btn-success btn-sm">
                <i class="bi bi-download"></i> Export CSV
            </a>
        </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" name="search" class="form-control"
                               placeholder="Search name or email..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="role" class="form-select">
                            <option value="">All Roles</option>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['slug'] ?>"
                                    <?= $role_f === $r['slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="active"    <?= $status_f==='active'    ? 'selected':'' ?>>Active</option>
                            <option value="suspended" <?= $status_f==='suspended' ? 'selected':'' ?>>Suspended</option>
                            <option value="pending"   <?= $status_f==='pending'   ? 'selected':'' ?>>Pending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Users (<?= $total ?>)</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Wallet</th>
                            <th>Bookings</th>
                            <th>Joined</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($u['profile_picture'] ?? 'default.png') ?>"
                                         class="rounded-circle"
                                         style="width:36px;height:36px;object-fit:cover">
                                    <div>
                                        <div class="fw-semibold small">
                                            <?= htmlspecialchars($u['full_name']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:11px">
                                            <?= htmlspecialchars($u['email']) ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= htmlspecialchars($u['role_name']) ?>
                                </span>
                            </td>
                            <td class="fw-semibold">
                                ₹<?= number_format($u['wallet_balance'], 2) ?>
                            </td>
                            <td><?= $u['booking_count'] ?></td>
                            <td>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($u['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?=
                                    $u['status']==='active'    ? 'bg-success'   :
                                    ($u['status']==='suspended' ? 'bg-danger'   : 'bg-warning text-dark')
                                ?>">
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if ($u['id'] !== $_SESSION['user_id']): ?>

                                    <?php if ($u['status'] === 'suspended'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id"   value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action"    value="activate">
                                        <button class="btn btn-sm btn-success">Activate</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id"   value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action"    value="suspend">
                                        <button class="btn btn-sm btn-warning">Suspend</button>
                                    </form>
                                    <?php endif; ?>

                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="addWallet(<?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')">
                                        + Wallet
                                    </button>

                                    <form method="POST"
                                          onsubmit="return confirm('Delete this user? This cannot be undone.')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="user_id"   value="<?= $u['id'] ?>">
                                        <input type="hidden" name="action"    value="delete">
                                        <button class="btn btn-sm btn-danger">Delete</button>
                                    </form>

                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="?<?= http_build_query(array_merge($_GET, ['page'=>$i])) ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>

<!-- Add Wallet Modal -->
<div class="modal fade" id="walletModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Add Wallet Credit</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action"    value="add_wallet">
                <input type="hidden" name="user_id"   id="walletUserId">
                <div class="modal-body">
                    <p class="small text-muted" id="walletUserName"></p>
                    <label class="form-label">Amount (₹)</label>
                    <input type="number" name="amount" class="form-control"
                           min="1" max="50000" step="1" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        Add to Wallet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function addWallet(uid, name) {
    document.getElementById('walletUserId').value = uid;
    document.getElementById('walletUserName').textContent = 'Adding to: ' + name;
    new bootstrap.Modal(document.getElementById('walletModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>