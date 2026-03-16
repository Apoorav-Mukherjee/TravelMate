<?php
$page_title = 'Add Money — Payment';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if (empty($_SESSION['pending_topup'])) {
    set_flash('error', 'No top-up pending.');
    redirect('modules/wallet/index.php');
}

$topup   = $_SESSION['pending_topup'];
$amount  = $topup['amount'];
$gateway = $topup['gateway'];
$user_id = $_SESSION['user_id'];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Add Money to Wallet</h5>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        Payment via <?= ucfirst($gateway) ?>
                    </div>
                    <div class="card-body">

                        <!-- Amount display -->
                        <div class="text-center mb-4">
                            <div class="text-muted small">Adding to wallet</div>
                            <div class="display-5 fw-bold text-primary">
                                ₹<?= number_format($amount, 2) ?>
                            </div>
                        </div>

                        <?php if ($gateway === 'stripe'): ?>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i>
                            <strong>Test Mode</strong> — Use card: 4242 4242 4242 4242
                        </div>
                        <form method="POST"
                              action="<?= BASE_URL ?>modules/wallet/topup_process.php">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="gateway" value="stripe">
                            <div class="mb-3">
                                <label class="form-label">Card Number</label>
                                <input type="text" class="form-control"
                                       placeholder="4242 4242 4242 4242"
                                       maxlength="19">
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <label class="form-label">Expiry</label>
                                    <input type="text" class="form-control"
                                           placeholder="MM/YY" maxlength="5">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">CVV</label>
                                    <input type="text" class="form-control"
                                           placeholder="123" maxlength="3">
                                </div>
                            </div>
                            <input type="hidden" name="simulated_txn_id"
                                   value="txn_topup_<?= uniqid() ?>">
                            <button type="submit" class="btn btn-primary w-100 btn-lg">
                                <i class="bi bi-lock-fill"></i>
                                Pay ₹<?= number_format($amount, 2) ?>
                            </button>
                        </form>

                        <?php else: ?>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i>
                            <strong>Test Mode</strong> — Simulated UPI payment
                        </div>
                        <form method="POST"
                              action="<?= BASE_URL ?>modules/wallet/topup_process.php">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="gateway" value="razorpay">
                            <div class="mb-4">
                                <label class="form-label">UPI ID</label>
                                <input type="text" class="form-control"
                                       placeholder="yourname@upi">
                            </div>
                            <input type="hidden" name="simulated_txn_id"
                                   value="rzp_topup_<?= uniqid() ?>">
                            <button type="submit" class="btn btn-success w-100 btn-lg">
                                Pay via UPI ₹<?= number_format($amount, 2) ?>
                            </button>
                        </form>
                        <?php endif; ?>

                        <div class="text-center mt-3">
                            <a href="<?= BASE_URL ?>modules/wallet/index.php"
                               class="text-muted small">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>