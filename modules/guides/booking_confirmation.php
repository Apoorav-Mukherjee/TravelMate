<?php
$page_title = 'Booking Confirmed';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, bi.meta, bi.unit_price, bi.quantity,
           u.full_name as guide_name, u.profile_picture, u.email as guide_email,
           g.city as guide_city
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN guides g         ON bi.item_id = g.id
    JOIN users u          ON g.user_id = u.id
    WHERE b.id = ? AND b.user_id = ? AND bi.item_type = 'guide_session'
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
            <div class="mb-4">
                <i class="bi bi-check-circle-fill text-success" style="font-size:5rem"></i>
            </div>
            <h2 class="fw-bold">Booking Confirmed!</h2>
            <p class="text-muted fs-5">Your guide has been successfully booked.</p>

            <div class="card border-0 shadow-sm mx-auto mt-4" style="max-width:500px">
                <div class="card-body p-4">
                    <div class="d-flex gap-3 align-items-center mb-4 justify-content-center">
                        <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($booking['profile_picture'] ?: 'default.png') ?>"
                             class="rounded-circle"
                             style="width:70px;height:70px;object-fit:cover">
                        <div class="text-start">
                            <div class="fw-bold fs-5"><?= htmlspecialchars($booking['guide_name']) ?></div>
                            <div class="text-muted"><?= htmlspecialchars($booking['guide_city']) ?></div>
                        </div>
                    </div>

                    <table class="table table-bordered">
                        <tr>
                            <td class="text-muted">Booking Ref</td>
                            <td><code class="fs-6"><?= $booking['booking_ref'] ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Date</td>
                            <td><?= date('d M Y', strtotime($booking['check_in'])) ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Duration</td>
                            <td><?= htmlspecialchars($meta['label'] ?? '-') ?></td>
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
                                <span class="badge badge-<?= $booking['booking_status'] ?>">
                                    <?= ucfirst($booking['booking_status']) ?>
                                </span>
                            </td>
                        </tr>
                    </table>

                    <div class="d-flex gap-2 justify-content-center mt-2">
                        <a href="<?= BASE_URL ?>modules/bookings/my_bookings.php"
                           class="btn btn-outline-primary">My Bookings</a>
                        <a href="<?= BASE_URL ?>modules/chat/inbox.php"
                           class="btn btn-primary">Message Guide</a>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>