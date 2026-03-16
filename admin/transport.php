<?php
$page_title = 'Transport Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $provider_id = (int)($_POST['provider_id'] ?? 0);
    $action      = sanitize($_POST['action'] ?? '');

    if ($provider_id && in_array($action, ['approve', 'reject', 'suspend'])) {
        switch ($action) {
            case 'approve':
                $conn->query("UPDATE transport_providers
                              SET is_approved=1, status='active' WHERE id=$provider_id");
                // Notify
                $stmt = $conn->prepare("
                    SELECT u.email, u.full_name FROM transport_providers tp
                    JOIN users u ON tp.user_id = u.id WHERE tp.id = ?
                ");
                $stmt->bind_param('i', $provider_id);
                $stmt->execute();
                $p = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($p) {
                    send_email($p['email'], $p['full_name'],
                        'Transport Provider Approved!',
                        "<p>Your TravelMate transport provider profile has been approved.
                         You can now add routes and receive bookings!</p>"
                    );
                }
                break;
            case 'reject':
                $conn->query("UPDATE transport_providers
                              SET is_approved=0, status='inactive' WHERE id=$provider_id");
                break;
            case 'suspend':
                $conn->query("UPDATE transport_providers
                              SET status='inactive' WHERE id=$provider_id");
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'],
                         "Transport provider $action", 'transport_provider', $provider_id);
        set_flash('success', "Provider $action successful.");
    }
    redirect('admin/transport.php');
}

$stmt = $conn->prepare("
    SELECT tp.*, u.full_name, u.email,
           COUNT(DISTINCT r.id) as route_count
    FROM transport_providers tp
    JOIN users u ON tp.user_id = u.id
    LEFT JOIN transport_routes r ON r.provider_id = tp.id
    GROUP BY tp.id
    ORDER BY tp.created_at DESC
");
$stmt->execute();
$providers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Transport Provider Management</h5>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Company</th>
                            <th>Owner</th>
                            <th>Type</th>
                            <th>License</th>
                            <th>Routes</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($providers as $p): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    <?= htmlspecialchars($p['company_name']) ?>
                                </div>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($p['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <?= htmlspecialchars($p['full_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($p['email']) ?></small>
                            </td>
                            <td><?= ucfirst($p['transport_type']) ?></td>
                            <td>
                                <small><?= htmlspecialchars($p['license_number'] ?? '-') ?></small>
                            </td>
                            <td><?= $p['route_count'] ?></td>
                            <td>
                                <span class="badge <?=
                                    $p['is_approved']           ? 'bg-success'           :
                                    ($p['status'] === 'pending' ? 'bg-warning text-dark' : 'bg-secondary')
                                ?>">
                                    <?= $p['is_approved'] ? 'Approved' : ucfirst($p['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if (!$p['is_approved']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
                                        <input type="hidden" name="provider_id"   value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action"        value="approve">
                                        <button class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
                                        <input type="hidden" name="provider_id"   value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action"        value="reject">
                                        <button class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
                                        <input type="hidden" name="provider_id"   value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action"        value="suspend">
                                        <button class="btn btn-warning btn-sm">Suspend</button>
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

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>