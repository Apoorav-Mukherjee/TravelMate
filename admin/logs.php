<?php
$page_title = 'Admin Logs';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$search   = sanitize($_GET['search']    ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 30;
$offset   = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search) {
    $where[]  = "(al.action LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}

$where_sql = implode(' AND ', $where);
$total     = $conn->query("
    SELECT COUNT(*) FROM admin_logs al
    JOIN users u ON al.admin_id=u.id WHERE $where_sql
")->fetch_row()[0];
$total_pages = ceil($total / $per_page);

$lp = array_merge($params, [$per_page, $offset]);
$lt = $types . 'ii';
$stmt = $conn->prepare("
    SELECT al.*, u.full_name AS admin_name
    FROM admin_logs al
    JOIN users u ON al.admin_id = u.id
    WHERE $where_sql
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($lt, ...$lp);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Admin Action Logs</h5>
        </div>

        <!-- Search -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control"
                            placeholder="Search action or admin name..."
                            value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>IP Address</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="fw-semibold small">
                                    <?= htmlspecialchars($log['admin_name']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?= htmlspecialchars($log['action']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($log['target_type'])): ?>
                                        <small class="text-muted">
                                            <?= ucfirst($log['target_type']) ?>
                                            <?= !empty($log['target_id']) ? '#' . $log['target_id'] : '' ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted font-monospace">
                                        <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                    </small>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= date('d M Y H:i:s', strtotime($log['created_at'])) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    No logs found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="mt-3">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link"
                                href="?search=<?= urlencode($search) ?>&page=<?= $i ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>