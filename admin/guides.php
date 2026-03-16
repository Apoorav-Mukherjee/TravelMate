<?php
$page_title = 'Guide Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $guide_id = (int)($_POST['guide_id'] ?? 0);
    $action   = sanitize($_POST['action'] ?? '');

    if ($guide_id && in_array($action, ['verify', 'unverify', 'suspend'])) {
        switch ($action) {
            case 'verify':
                $conn->query("UPDATE guides SET is_verified=1, status='active' WHERE id=$guide_id");
                // Notify guide
                $stmt = $conn->prepare("
                    SELECT u.email, u.full_name FROM guides g
                    JOIN users u ON g.user_id = u.id WHERE g.id = ?
                ");
                $stmt->bind_param('i', $guide_id);
                $stmt->execute();
                $g = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($g) {
                    send_email($g['email'], $g['full_name'],
                        'Your Guide Profile is Verified!',
                        "<p>Hi {$g['full_name']},</p>
                         <p>Congratulations! Your TravelMate guide profile has been verified.
                         You can now receive bookings.</p>"
                    );
                }
                break;
            case 'unverify':
                $conn->query("UPDATE guides SET is_verified=0, status='pending' WHERE id=$guide_id");
                break;
            case 'suspend':
                $conn->query("UPDATE guides SET status='inactive' WHERE id=$guide_id");
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'], "Guide $action", 'guide', $guide_id);
        set_flash('success', "Guide $action successful.");
    }
    redirect('admin/guides.php');
}

// Fetch guides
$stmt = $conn->prepare("
    SELECT g.*, u.full_name, u.email, u.phone,
           COALESCE(AVG(r.rating), 0) as avg_rating,
           COUNT(DISTINCT b.id) as total_bookings
    FROM guides g
    JOIN users u ON g.user_id = u.id
    LEFT JOIN reviews r ON r.entity_type = 'guide' AND r.entity_id = g.id
    LEFT JOIN booking_items bi ON bi.item_type = 'guide_session' AND bi.item_id = g.id
    LEFT JOIN bookings b ON bi.booking_id = b.id
    GROUP BY g.id
    ORDER BY g.created_at DESC
");
$stmt->execute();
$guides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Guide Management</h5>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Guide</th>
                            <th>City</th>
                            <th>Rates</th>
                            <th>Rating</th>
                            <th>Bookings</th>
                            <th>Commission</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($guides as $g): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($g['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($g['email']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($g['city'] ?? '-') ?></td>
                            <td>
                                <?php if ($g['hourly_rate']): ?>
                                <div class="small">₹<?= number_format($g['hourly_rate'], 0) ?>/hr</div>
                                <?php endif; ?>
                                <?php if ($g['daily_rate']): ?>
                                <div class="small">₹<?= number_format($g['daily_rate'], 0) ?>/day</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= number_format($g['avg_rating'], 1) ?>★
                            </td>
                            <td><?= $g['total_bookings'] ?></td>
                            <td><?= $g['commission_rate'] ?>%</td>
                            <td>
                                <span class="badge <?=
                                    $g['is_verified']            ? 'bg-success'          :
                                    ($g['status'] === 'pending'  ? 'bg-warning text-dark' : 'bg-secondary')
                                ?>">
                                    <?= $g['is_verified'] ? 'Verified' : ucfirst($g['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1 flex-wrap">
                                    <?php if (!$g['is_verified']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="guide_id"   value="<?= $g['id'] ?>">
                                        <input type="hidden" name="action"     value="verify">
                                        <button class="btn btn-success btn-sm">Verify</button>
                                    </form>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="guide_id"   value="<?= $g['id'] ?>">
                                        <input type="hidden" name="action"     value="unverify">
                                        <button class="btn btn-warning btn-sm">Unverify</button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                        <input type="hidden" name="guide_id"   value="<?= $g['id'] ?>">
                                        <input type="hidden" name="action"     value="suspend">
                                        <button class="btn btn-danger btn-sm">Suspend</button>
                                    </form>
                                    <!-- Commission edit -->
                                    <button class="btn btn-outline-secondary btn-sm"
                                            onclick="editCommission(<?= $g['id'] ?>, <?= $g['commission_rate'] ?>)">
                                        Commission
                                    </button>
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

<!-- Commission Modal -->
<div class="modal fade" id="commissionModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Edit Commission Rate</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= BASE_URL ?>admin/update_commission.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="guide_id" id="commGuideId">
                    <label class="form-label">Commission %</label>
                    <input type="number" name="commission_rate" id="commRate"
                           class="form-control" min="0" max="50" step="0.5">
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editCommission(guideId, rate) {
    document.getElementById('commGuideId').value = guideId;
    document.getElementById('commRate').value    = rate;
    new bootstrap.Modal(document.getElementById('commissionModal')).show();
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>