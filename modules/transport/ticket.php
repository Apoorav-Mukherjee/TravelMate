<?php
$page_title = 'Your Ticket';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, u.full_name, u.email, u.phone
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'Ticket not found.');
    redirect('dashboards/traveler/index.php');
}

// Fetch all seats in this booking
$stmt = $conn->prepare("
    SELECT bi.*, bi.meta FROM booking_items bi
    WHERE bi.booking_id = ? AND bi.item_type = 'seat'
");
$stmt->bind_param('i', $booking_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($items)) {
    set_flash('error', 'No ticket data found.');
    redirect('dashboards/traveler/index.php');
}

$first_meta = json_decode($items[0]['meta'], true) ?? [];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Your Ticket</h5>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>modules/transport/download_ticket.php?booking_id=<?= $booking_id ?>"
                   class="btn btn-success btn-sm">
                    <i class="bi bi-download"></i> Download PDF
                </a>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <!-- Ticket Card -->
        <div class="card border-0 shadow" id="ticket" style="max-width:750px;margin:auto">

            <!-- Ticket Header -->
            <div style="background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                        border-radius:12px 12px 0 0;padding:25px;color:#fff">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h3 class="fw-bold mb-0">✈️ TravelMate</h3>
                        <small class="opacity-75">e-Ticket / Boarding Pass</small>
                    </div>
                    <div class="text-end">
                        <div class="small opacity-75">Booking Ref</div>
                        <div class="fw-bold fs-5"><?= $booking['booking_ref'] ?></div>
                        <span class="badge bg-success">
                            <?= ucfirst($booking['booking_status']) ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Route Info -->
            <div class="px-4 py-3 border-bottom" style="background:#f8f9fa">
                <div class="row align-items-center text-center">
                    <div class="col-4">
                        <div class="fs-2 fw-bold text-primary">
                            <?= date('H:i', strtotime($first_meta['departure'] ?? '')) ?>
                        </div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($first_meta['source'] ?? '') ?>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="text-muted small">
                            <?= ucfirst($first_meta['transport_type'] ?? '') ?>
                        </div>
                        <div>✈ ——————— ✈</div>
                        <div class="text-muted small">
                            <?= date('d M Y', strtotime($first_meta['journey_date'] ?? '')) ?>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="fs-2 fw-bold text-primary">
                            <?= date('H:i', strtotime($first_meta['arrival'] ?? '')) ?>
                        </div>
                        <div class="fw-semibold">
                            <?= htmlspecialchars($first_meta['destination'] ?? '') ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Passenger & Seat Details -->
            <div class="p-4">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="text-muted small text-uppercase">Passenger</div>
                        <div class="fw-bold"><?= htmlspecialchars($booking['full_name']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($booking['email']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small text-uppercase">Operator</div>
                        <div class="fw-bold"><?= htmlspecialchars($first_meta['company'] ?? '') ?></div>
                    </div>
                </div>

                <!-- Seats Table -->
                <h6 class="fw-bold mb-2">Seat Details</h6>
                <table class="table table-bordered mb-4">
                    <thead class="table-dark">
                        <tr>
                            <th>Seat No</th>
                            <th>Class</th>
                            <th class="text-end">Fare</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item):
                            $m = json_decode($item['meta'], true) ?? [];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($m['seat_number'] ?? '-') ?></strong></td>
                            <td><?= ucfirst(str_replace('_', ' ', $m['seat_class'] ?? '-')) ?></td>
                            <td class="text-end">₹<?= number_format($item['unit_price'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-primary fw-bold">
                            <td colspan="2" class="text-end">Total Paid</td>
                            <td class="text-end">₹<?= number_format($booking['final_amount'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- QR Code -->
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="alert alert-info small mb-0">
                            <i class="bi bi-info-circle"></i>
                            Please show this ticket (digital or printed) to the staff before boarding.<br>
                            Valid ID proof required.
                        </div>
                    </div>
                    <div class="col-md-6 text-center">
                        <!-- QR Code using Google Charts API (no external library needed) -->
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode('TRAVELMATE:' . $booking['booking_ref'] . ':' . $booking_id) ?>"
                             alt="QR Code"
                             style="border:5px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.1);border-radius:8px">
                        <div class="text-muted small mt-1">Scan to verify</div>
                    </div>
                </div>
            </div>

            <!-- Ticket Footer -->
            <div style="background:#f8f9fa;border-radius:0 0 12px 12px;
                        padding:15px 25px;border-top:2px dashed #dee2e6">
                <div class="row text-center">
                    <div class="col">
                        <div class="small text-muted">Issued</div>
                        <div class="small fw-semibold">
                            <?= date('d M Y H:i', strtotime($booking['created_at'])) ?>
                        </div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Payment</div>
                        <div class="small fw-semibold">
                            <?= ucfirst($booking['payment_status']) ?>
                        </div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Cancellation</div>
                        <div class="small fw-semibold">2hrs before departure</div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Support</div>
                        <div class="small fw-semibold">support@travelmate.com</div>
                    </div>
                </div>
            </div>

        </div><!-- /ticket card -->

        <!-- Cancellation Button -->
        <?php if ($booking['booking_status'] === 'confirmed'): ?>
        <div class="text-center mt-4">
            <a href="<?= BASE_URL ?>modules/transport/cancel.php?booking_id=<?= $booking_id ?>"
               class="btn btn-outline-danger"
               onclick="return confirm('Are you sure you want to cancel this booking?')">
                <i class="bi bi-x-circle"></i> Cancel Booking
            </a>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>