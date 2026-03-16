<?php
$page_title = 'Review Moderation';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rev_id = (int)($_POST['rev_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');

    if ($rev_id && in_array($action, ['approve','reject','delete'])) {
        switch ($action) {
            case 'approve':
                $conn->query("UPDATE reviews SET is_approved=1 WHERE id=$rev_id");
                break;
            case 'reject':
                $conn->query("UPDATE reviews SET is_approved=0 WHERE id=$rev_id");
                break;
            case 'delete':
                $conn->query("DELETE FROM reviews WHERE id=$rev_id");
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'], "Review $action", 'review', $rev_id);
        set_flash('success', "Review $action.");
    }
    redirect('admin/reviews.php');
}

$filter   = sanitize($_GET['filter'] ?? 'pending');
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset   = ($page - 1) * $per_page;

$where = match($filter) {
    'pending'  => 'r.is_approved = 0',
    'approved' => 'r.is_approved = 1',
    default    => '1=1'
};

$total       = $conn->query("SELECT COUNT(*) FROM reviews r WHERE $where")->fetch_row()[0];
$total_pages = ceil($total / $per_page);

$stmt = $conn->prepare("
    SELECT r.*, u.full_name AS reviewer_name
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE $where
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('ii', $per_page, $offset);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Review Moderation</h5>
        </div>

        <div class="d-flex gap-2 mb-4">
            <?php foreach (['pending'=>['Pending','warning'],'approved'=>['Approved','success'],'all'=>['All','secondary']] as $k=>[$lbl,$clr]): ?>
            <a href="?filter=<?= $k ?>"
               class="btn btn-sm <?= $filter===$k ? "btn-$clr" : "btn-outline-$clr" ?>">
                <?= $lbl ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reviewer</th>
                            <th>Entity</th>
                            <th>Rating</th>
                            <th>Review</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviews as $r): ?>
                        <tr>
                            <td class="fw-semibold small">
                                <?= htmlspecialchars($r['reviewer_name']) ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= ucfirst($r['entity_type']) ?>
                                    #<?= $r['entity_id'] ?>
                                </span>
                            </td>
                            <td>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi <?= $i <= $r['rating']
                                    ? 'bi-star-fill text-warning'
                                    : 'bi-star text-muted' ?>"
                                   style="font-size:12px"></i>
                                <?php endfor; ?>
                            </td>
                            <td style="max-width:280px">
                                <div class="text-truncate small">
                                    <?= htmlspecialchars($r['review_text']) ?>
                                </div>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($r['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge <?= $r['is_approved']
                                    ? 'bg-success' : 'bg-warning text-dark' ?>">
                                    <?= $r['is_approved'] ? 'Approved' : 'Pending' ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if (!$r['is_approved']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="rev_id"    value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action"    value="approve">
                                        <button class="btn btn-sm btn-success">Approve</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="rev_id"    value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action"    value="reject">
                                        <button class="btn btn-sm btn-warning">Reject</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Delete this review?')">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="rev_id"    value="<?= $r['id'] ?>">
                                        <input type="hidden" name="action"    value="delete">
                                        <button class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($reviews)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No reviews found.
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
                <li class="page-item <?= $i===$page ? 'active' : '' ?>">
                    <a class="page-link" href="?filter=<?= $filter ?>&page=<?= $i ?>">
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