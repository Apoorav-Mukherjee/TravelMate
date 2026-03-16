<?php
$page_title = 'Hotel Approvals';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

// Handle approve/reject/feature
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $hotel_id = (int)($_POST['hotel_id'] ?? 0);
    $action   = sanitize($_POST['action'] ?? '');

    if ($hotel_id && in_array($action, ['approve', 'reject', 'feature', 'unfeature'])) {
        switch ($action) {
            case 'approve':
                $stmt = $conn->prepare("UPDATE hotels SET is_approved=1, status='active' WHERE id=?");
                break;
            case 'reject':
                $stmt = $conn->prepare("UPDATE hotels SET is_approved=0, status='inactive' WHERE id=?");
                break;
            case 'feature':
                $stmt = $conn->prepare("UPDATE hotels SET is_featured=1 WHERE id=?");
                break;
            case 'unfeature':
                $stmt = $conn->prepare("UPDATE hotels SET is_featured=0 WHERE id=?");
                break;
        }
        $stmt->bind_param('i', $hotel_id);
        $stmt->execute();
        $stmt->close();

        log_admin_action($conn, $_SESSION['user_id'], "Hotel $action", 'hotel', $hotel_id);
        set_flash('success', "Hotel $action successful.");
    }
    redirect('admin/hotels.php');
}

// Fetch all hotels
$stmt = $conn->prepare("
    SELECT h.*, u.full_name as owner_name, u.email as owner_email,
           COUNT(r.id) as room_count
    FROM hotels h
    JOIN users u ON h.owner_id = u.id
    LEFT JOIN rooms r ON r.hotel_id = h.id
    WHERE h.deleted_at IS NULL
    GROUP BY h.id
    ORDER BY h.created_at DESC
");
$stmt->execute();
$hotels = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Hotel Management</h5>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Hotel</th>
                            <th>Owner</th>
                            <th>City</th>
                            <th>Rooms</th>
                            <th>Stars</th>
                            <th>Featured</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hotels as $h): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($h['name']) ?></div>
                                <small class="text-muted"><?= date('d M Y', strtotime($h['created_at'])) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($h['owner_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($h['owner_email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($h['city']) ?></td>
                            <td><?= $h['room_count'] ?></td>
                            <td><?= $h['star_rating'] ?>★</td>
                            <td>
                                <?php if ($h['is_featured']): ?>
                                <span class="badge bg-warning text-dark">Featured</span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?=
                                    $h['status'] === 'active'   ? 'bg-success' :
                                    ($h['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-secondary')
                                ?>">
                                    <?= ucfirst($h['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if (!$h['is_approved']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="hotel_id"   value="<?= $h['id'] ?>">
                                        <input type="hidden" name="action"     value="approve">
                                        <button class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="hotel_id"   value="<?= $h['id'] ?>">
                                        <input type="hidden" name="action"     value="reject">
                                        <button class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="hotel_id"   value="<?= $h['id'] ?>">
                                        <input type="hidden" name="action"
                                               value="<?= $h['is_featured'] ? 'unfeature' : 'feature' ?>">
                                        <button class="btn btn-warning btn-sm">
                                            <?= $h['is_featured'] ? 'Unfeature' : 'Feature' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>