<?php
$page_title = 'Booking Confirmed';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, bi.meta, pb.persons, pb.travel_date
    FROM bookings b
    JOIN booking_items bi  ON bi.booking_id = b.id AND bi.item_type = 'package'
    JOIN package_bookings pb ON pb.booking_id = b.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'Booking not found.');
    redirect('dashboards/traveler/index.php');
}

$meta = json_decode($booking['meta'], true) ?? [];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="text-center py-5">
            <i class="bi bi-check-circle-fill text-success" style="font-size:5rem"></i>
            <h2 class="fw-bold mt-3">Booking Confirmed!</h2>
            <p class="text-muted fs-5">Your tour package has been booked successfully.</p>

            <div class="card border-0 shadow mx-auto mt-4" style="max-width:520px">
                <div class="card-body p-4">
                    <table class="table table-bordered text-start">
                        <tr>
                            <td class="text-muted">Booking Ref</td>
                            <td><code class="fs-6"><?= $booking['booking_ref'] ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Package</td>
                            <td><?= htmlspecialchars($meta['package_name'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Destination</td>
                            <td><?= htmlspecialchars($meta['destination'] ?? '') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Travel Date</td>
                            <td><?= date('d M Y', strtotime($booking['travel_date'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Persons</td>
                            <td><?= $booking['persons'] ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Duration</td>
                            <td><?= $meta['duration_days'] ?? '-' ?> Days</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Amount Paid</td>
                            <td class="fw-bold text-success">
                                ₹<?= number_format($booking['final_amount'], 2) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Status</td>
                            <td>
                                <span class="badge bg-success">
                                    <?= ucfirst($booking['booking_status']) ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <div class="d-flex gap-2 justify-content-center">
                        <a href="<?= BASE_URL ?>modules/bookings/my_bookings.php"
                           class="btn btn-outline-primary">My Bookings</a>
                        <a href="<?= BASE_URL ?>modules/packages/search.php"
                           class="btn btn-primary">Browse More</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>