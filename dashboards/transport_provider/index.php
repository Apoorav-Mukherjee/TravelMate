<?php
$page_title = 'Transport Provider Dashboard';
require_once __DIR__ . '/../../includes/header.php';
require_role('transport_provider');

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM transport_providers WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

$provider_id    = $provider['id']  ?? null;
$total_routes   = 0;
$total_bookings = 0;
$revenue        = 0;

if ($provider_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM transport_routes WHERE provider_id = ?");
    $stmt->bind_param('i', $provider_id);
    $stmt->execute();
    $total_routes = $stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT b.id) as total,
               SUM(CASE WHEN b.payment_status = 'paid' THEN b.final_amount ELSE 0 END) as revenue
        FROM bookings b
        JOIN booking_items bi ON bi.booking_id = b.id
        JOIN transport_seats ts ON bi.item_id = ts.id
        JOIN transport_routes tr ON ts.route_id = tr.id
        WHERE tr.provider_id = ? AND bi.item_type = 'seat'
    ");
    $stmt->bind_param('i', $provider_id);
    $stmt->execute();
    $stats          = $stmt->get_result()->fetch_assoc();
    $total_bookings = $stats['total'];
    $revenue        = $stats['revenue'];
    $stmt->close();
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Transport Provider Dashboard</h5>
            <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>

        <?php if (!$provider): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            Please complete your provider profile first.
            <a href="<?= BASE_URL ?>modules/transport/manage/profile.php" class="alert-link">
                Setup Profile →
            </a>
        </div>
        <?php elseif (!$provider['is_approved']): ?>
        <div class="alert alert-info">
            <i class="bi bi-clock"></i>
            Your provider profile is pending admin approval.
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-primary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Routes</div>
                            <div class="fs-2 fw-bold"><?= $total_routes ?></div>
                        </div>
                        <i class="bi bi-map fs-1 text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Bookings</div>
                            <div class="fs-2 fw-bold"><?= $total_bookings ?></div>
                        </div>
                        <i class="bi bi-ticket-perforated fs-1 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-warning border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Revenue</div>
                            <div class="fs-2 fw-bold">₹<?= number_format($revenue, 0) ?></div>
                        </div>
                        <i class="bi bi-currency-rupee fs-1 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/transport/manage/routes.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-map fs-2 text-primary"></i>
                    <div class="mt-2 fw-semibold">Manage Routes</div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/transport/manage/seats.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-grid-3x3 fs-2 text-success"></i>
                    <div class="mt-2 fw-semibold">Seat Matrix</div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/transport/manage/bookings.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-ticket-perforated fs-2 text-warning"></i>
                    <div class="mt-2 fw-semibold">View Bookings</div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/transport/manage/profile.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-building fs-2 text-info"></i>
                    <div class="mt-2 fw-semibold">Company Profile</div>
                </a>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>