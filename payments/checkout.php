<?php
$page_title = 'Checkout';
require_once __DIR__ . '/../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$gateway    = sanitize($_GET['gateway'] ?? 'stripe');
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT * FROM bookings WHERE id = ? AND user_id = ? AND payment_status = 'pending'
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'Invalid booking.');
    redirect('dashboards/traveler/index.php');
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Complete Payment</h5>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Payment Details</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Booking Reference</span>
                            <code><?= $booking['booking_ref'] ?></code>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Type</span>
                            <span><?= ucfirst($booking['booking_type']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold fs-5 mb-4">
                            <span>Total Amount</span>
                            <span class="text-primary">₹<?= number_format($booking['final_amount'], 2) ?></span>
                        </div>

                        <?php if ($gateway === 'stripe'): ?>
                        <!-- Stripe Simulation -->
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Stripe Test Mode</strong> — Use card: 4242 4242 4242 4242
                        </div>
                        <form id="stripeForm" method="POST" action="<?= BASE_URL ?>payments/stripe_process.php">
                            <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
                            <input type="hidden" name="booking_id"  value="<?= $booking_id ?>">
                            <div class="mb-3">
                                <label class="form-label">Card Number</label>
                                <input type="text" class="form-control" id="card_number"
                                       placeholder="4242 4242 4242 4242" maxlength="19">
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label">Expiry</label>
                                    <input type="text" class="form-control" placeholder="MM/YY" maxlength="5">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">CVV</label>
                                    <input type="text" class="form-control" placeholder="123" maxlength="3">
                                </div>
                            </div>
                            <input type="hidden" name="simulated_txn_id"
                                   value="txn_<?= uniqid() ?>">
                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="bi bi-lock-fill"></i>
                                Pay ₹<?= number_format($booking['final_amount'], 2) ?>
                            </button>
                        </form>

                        <?php elseif ($gateway === 'razorpay'): ?>
                        <!-- Razorpay Simulation -->
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Razorpay Test Mode</strong> — Simulated UPI payment
                        </div>
                        <form method="POST" action="<?= BASE_URL ?>payments/razorpay_process.php">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
                            <div class="mb-3">
                                <label class="form-label">UPI ID</label>
                                <input type="text" class="form-control" placeholder="yourname@upi">
                            </div>
                            <input type="hidden" name="simulated_txn_id"
                                   value="rzp_<?= uniqid() ?>">
                            <button type="submit" class="btn btn-success w-100 btn-lg">
                                Pay via UPI ₹<?= number_format($booking['final_amount'], 2) ?>
                            </button>
                        </form>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>