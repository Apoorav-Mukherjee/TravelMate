<?php
$page_title = 'Hotel Staff Dashboard';
require_once __DIR__ . '/../../includes/header.php';
require_role('hotel_staff');

$user_id = $_SESSION['user_id'];

// Get hotels owned by this staff
$stmt = $conn->prepare("SELECT id FROM hotels WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$my_hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

$hotel_id = $my_hotel['id'] ?? null;

$total_bookings = 0;
$pending        = 0;
$revenue        = 0;

if ($hotel_id) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total,
               SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END) as pending_cnt,
               SUM(CASE WHEN b.payment_status = 'paid' THEN b.final_amount ELSE 0 END) as revenue
        FROM bookings b
        JOIN booking_items bi ON bi.booking_id = b.id
        JOIN rooms r ON bi.item_id = r.id
        WHERE r.hotel_id = ? AND bi.item_type = 'room'
    ");
    $stmt->bind_param('i', $hotel_id);
    $stmt->execute();
    $stats          = $stmt->get_result()->fetch_assoc();
    $total_bookings = $stats['total'];
    $pending        = $stats['pending_cnt'];
    $revenue        = $stats['revenue'];
    $stmt->close();
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Hotel Staff Dashboard</h5>
            <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>

        <?php if (!$hotel_id): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i>
            You haven't added your hotel yet.
            <a href="<?= BASE_URL ?>modules/hotels/manage/create.php" class="alert-link">Add Hotel Now</a>
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
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
            <div class="col-md-4">
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
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Revenue</div>
                            <div class="fs-2 fw-bold">₹<?= number_format($revenue, 0) ?></div>
                        </div>
                        <i class="bi bi-currency-rupee fs-1 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="row g-3">
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/hotels/manage/create.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-plus-circle fs-2 text-primary"></i>
                    <div class="mt-2 fw-semibold">
                        <?= $hotel_id ? 'Edit Hotel' : 'Add Hotel' ?>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/hotels/manage/rooms.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-door-open fs-2 text-success"></i>
                    <div class="mt-2 fw-semibold">Manage Rooms</div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/hotels/manage/bookings.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-list-check fs-2 text-warning"></i>
                    <div class="mt-2 fw-semibold">View Bookings</div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="<?= BASE_URL ?>modules/hotels/manage/availability.php"
                   class="card border-0 shadow-sm p-3 text-center text-decoration-none text-dark d-block">
                    <i class="bi bi-calendar3 fs-2 text-info"></i>
                    <div class="mt-2 fw-semibold">Availability</div>
                </a>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>