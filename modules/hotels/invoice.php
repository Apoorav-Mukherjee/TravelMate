<?php
$page_title = 'Booking Invoice';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, bi.unit_price, bi.subtotal, bi.meta,
           r.room_type, h.name as hotel_name, h.address, h.city,
           u.full_name, u.email, u.phone,
           p.gateway, p.status as pay_status, p.gateway_txn_id
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN rooms r          ON bi.item_id = r.id
    JOIN hotels h         ON r.hotel_id = h.id
    JOIN users u          ON b.user_id = u.id
    LEFT JOIN payments p  ON p.booking_id = b.id
    WHERE b.id = ? AND b.user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inv) {
    set_flash('error', 'Invoice not found.');
    redirect('dashboards/traveler/index.php');
}

$meta = json_decode($inv['meta'], true) ?? [];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Booking Invoice</h5>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>modules/hotels/download_invoice.php?booking_id=<?= $booking_id ?>"
                   class="btn btn-success btn-sm">
                    <i class="bi bi-download"></i> Download PDF
                </a>
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <div class="card border-0 shadow-sm" id="invoice">
            <div class="card-body p-5">
                <!-- Header -->
                <div class="row mb-5">
                    <div class="col-6">
                        <h2 class="fw-bold text-primary">✈️ TravelMate</h2>
                        <p class="text-muted mb-0">Your Trusted Travel Partner</p>
                    </div>
                    <div class="col-6 text-end">
                        <h4 class="fw-bold">INVOICE</h4>
                        <p class="mb-0"><strong>Ref:</strong> <?= $inv['booking_ref'] ?></p>
                        <p class="mb-0 text-muted">
                            <?= date('d M Y', strtotime($inv['created_at'])) ?>
                        </p>
                        <span class="badge badge-<?= $inv['booking_status'] ?> fs-6">
                            <?= ucfirst($inv['booking_status']) ?>
                        </span>
                    </div>
                </div>

                <!-- Bill To & Property -->
                <div class="row mb-4">
                    <div class="col-6">
                        <h6 class="fw-bold text-muted text-uppercase small">Bill To</h6>
                        <p class="mb-0 fw-semibold"><?= htmlspecialchars($inv['full_name']) ?></p>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($inv['email']) ?></p>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($inv['phone'] ?? '') ?></p>
                    </div>
                    <div class="col-6">
                        <h6 class="fw-bold text-muted text-uppercase small">Property</h6>
                        <p class="mb-0 fw-semibold"><?= htmlspecialchars($inv['hotel_name']) ?></p>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($inv['address']) ?></p>
                        <p class="mb-0 text-muted"><?= htmlspecialchars($inv['city']) ?></p>
                    </div>
                </div>

                <!-- Booking Details Table -->
                <table class="table table-bordered mb-4">
                    <thead class="table-dark">
                        <tr>
                            <th>Description</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Nights</th>
                            <th>Rate/Night</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($inv['room_type']) ?></td>
                            <td><?= date('d M Y', strtotime($inv['check_in'])) ?></td>
                            <td><?= date('d M Y', strtotime($inv['check_out'])) ?></td>
                            <td><?= $meta['nights'] ?? '-' ?></td>
                            <td>₹<?= number_format($inv['unit_price'], 2) ?></td>
                            <td class="text-end">₹<?= number_format($inv['subtotal'], 2) ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end">Subtotal</td>
                            <td class="text-end">₹<?= number_format($inv['subtotal'] - ($meta['tax'] ?? 0), 2) ?></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="text-end text-muted">GST (12%)</td>
                            <td class="text-end text-muted">₹<?= number_format($meta['tax'] ?? 0, 2) ?></td>
                        </tr>
                        <tr class="table-primary fw-bold">
                            <td colspan="5" class="text-end">Total</td>
                            <td class="text-end">₹<?= number_format($inv['final_amount'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Payment Info -->
                <div class="row">
                    <div class="col-6">
                        <h6 class="fw-bold">Payment Information</h6>
                        <p class="mb-1">
                            Method: <strong><?= ucfirst($inv['gateway'] ?? 'Pending') ?></strong>
                        </p>
                        <p class="mb-1">
                            Status:
                            <span class="badge bg-<?= $inv['pay_status'] === 'success' ? 'success' : 'warning' ?>">
                                <?= ucfirst($inv['pay_status'] ?? 'Pending') ?>
                            </span>
                        </p>
                        <?php if ($inv['gateway_txn_id']): ?>
                        <p class="mb-0 text-muted small">
                            Transaction ID: <?= htmlspecialchars($inv['gateway_txn_id']) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="col-6 text-end">
                        <p class="text-muted small">
                            Thank you for choosing TravelMate!<br>
                            For support: support@travelmate.com
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>