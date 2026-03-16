<?php
$page_title = 'Guide Dashboard';
require_once __DIR__ . '/../../includes/header.php';
require_role('guide');

$user_id = $_SESSION['user_id'];

// Fetch guide profile
$stmt = $conn->prepare("SELECT * FROM guides WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

$guide_id       = $guide['id'] ?? null;
$total_bookings = 0;
$pending        = 0;
$earnings       = 0;
$avg_rating     = 0;

if ($guide_id) {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN b.booking_status = 'pending'   THEN 1 ELSE 0 END) as pending_cnt,
            SUM(CASE WHEN b.payment_status = 'paid'
                     THEN b.final_amount * (1 - ? / 100) ELSE 0 END)        as net_earnings
        FROM bookings b
        JOIN booking_items bi ON bi.booking_id = b.id
        WHERE bi.item_type = 'guide_session' AND bi.item_id = ?
    ");
    $stmt->bind_param('di', $guide['commission_rate'], $guide_id);
    $stmt->execute();
    $stats          = $stmt->get_result()->fetch_assoc();
    $total_bookings = $stats['total'];
    $pending        = $stats['pending_cnt'];
    $earnings       = $stats['net_earnings'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COALESCE(AVG(rating), 0) as avg
        FROM reviews
        WHERE entity_type = 'guide' AND entity_id = ? AND is_approved = 1
    ");
    $stmt->bind_param('i', $guide_id);
    $stmt->execute();
    $avg_rating = $stmt->get_result()->fetch_assoc()['avg'];
    $stmt->close();
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Guide Dashboard</h5>
            <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>

        <?php if (!$guide): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Complete your guide profile to start receiving bookings.
            <a href="<?= BASE_URL ?>modules/guides/manage/profile.php" class="alert-link">
                Setup Profile →
            </a>
        </div>
        <?php endif; ?>

        <?php if ($guide && !$guide['is_verified']): ?>
        <div class="alert alert-info">
            <i class="bi bi-clock"></i>
            Your profile is pending admin verification. You'll receive bookings once verified.
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-primary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Bookings</div>
                            <div class="fs-2 fw-bold"><?= $total_bookings ?></div>
                        </div>
                        <i class="bi bi-calendar-check fs-1 text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-warning border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Pending</div>
                            <div class="fs-2 fw-bold"><?= $pending ?></div>
                        </div>
                        <i class="bi bi-hourglass fs-1 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Net Earnings</div>
                            <div class="fs-2 fw-bold">₹<?= number_format($earnings, 0) ?></div>
                        </div>
                        <i class="bi bi-currency-rupee fs-1 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-info border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Avg Rating</div>
                            <div class="fs-2 fw-bold"><?= number_format($avg_rating, 1) ?>★</div>
                        </div>
                        <i class="bi bi-star-fill fs-1 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Bookings -->
        <?php if ($guide_id): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Upcoming Bookings</div>
            <div class="card-body p-0">
                <?php
                $stmt = $conn->prepare("
                    SELECT b.*, u.full_name as traveler_name, u.email as traveler_email,
                           bi.meta, bi.subtotal
                    FROM bookings b
                    JOIN booking_items bi ON bi.booking_id = b.id
                    JOIN users u ON b.user_id = u.id
                    WHERE bi.item_type = 'guide_session' AND bi.item_id = ?
                      AND b.check_in >= CURDATE()
                    ORDER BY b.check_in ASC
                    LIMIT 10
                ");
                $stmt->bind_param('i', $guide_id);
                $stmt->execute();
                $bookings = $stmt->get_result();
                $stmt->close();
                ?>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Traveler</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($b = $bookings->fetch_assoc()): ?>
                        <?php $m = json_decode($b['meta'], true) ?? []; ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($b['traveler_name']) ?><br>
                                <small class="text-muted"><?= htmlspecialchars($b['traveler_email']) ?></small>
                            </td>
                            <td><?= date('d M Y', strtotime($b['check_in'])) ?></td>
                            <td><?= htmlspecialchars($m['label'] ?? '-') ?></td>
                            <td>₹<?= number_format($b['final_amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= $b['booking_status'] ?>">
                                    <?= ucfirst($b['booking_status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="<?= BASE_URL ?>modules/chat/inbox.php?user=<?= $b['user_id'] ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-chat"></i> Chat
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>