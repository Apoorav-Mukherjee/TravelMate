<?php
/**
 * modules/bookings/cancel.php
 * Handles booking cancellation for all booking types.
 * Refunds to wallet if payment was made (paid status).
 */
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$user_id    = $_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? $_GET['id'] ?? 0);

if (!$booking_id) {
    set_flash('error', 'Invalid booking.');
    redirect('modules/bookings/my_bookings.php');
}

$esc_id   = $conn->real_escape_string($booking_id);
$esc_user = $conn->real_escape_string($user_id);

// ── Fetch booking ─────────────────────────────────────────────────────────
$r = $conn->query("
    SELECT b.*, u.full_name, u.email, u.wallet_balance
    FROM   bookings b
    JOIN   users    u ON u.id = b.user_id
    WHERE  b.id = $esc_id AND b.user_id = $esc_user
    LIMIT  1
");

if (!$r || $r->num_rows === 0) {
    set_flash('error', 'Booking not found.');
    redirect('modules/bookings/my_bookings.php');
}
$booking = $r->fetch_assoc();

// Only pending/confirmed can be cancelled
if (!in_array($booking['booking_status'], ['pending', 'confirmed'])) {
    set_flash('error', 'This booking cannot be cancelled.');
    redirect('modules/bookings/view.php?id=' . $booking_id);
}

// ── Handle POST (actual cancellation) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $reason = trim(sanitize($_POST['reason'] ?? ''));
    if (empty($reason)) {
        set_flash('error', 'Please provide a cancellation reason.');
        redirect('modules/bookings/cancel.php?id=' . $booking_id);
    }

    $esc_reason    = $conn->real_escape_string($reason);
    $cancelled_at  = date('Y-m-d H:i:s');

    // ── Determine refund eligibility ──────────────────────────────────────
    // Refund if payment_status = 'paid' and booking was confirmed or pending
    $refund_amount  = 0;
    $do_refund      = false;

    if ($booking['payment_status'] === 'paid') {
        $refund_amount = (float)$booking['final_amount'];
        $do_refund     = true;
    }

    // ── Update booking status ─────────────────────────────────────────────
    $upd = $conn->query("
        UPDATE bookings SET
            booking_status      = 'cancelled',
            payment_status      = " . ($do_refund ? "'refunded'" : "payment_status") . ",
            cancellation_reason = '$esc_reason',
            cancelled_at        = '$cancelled_at'
        WHERE id = $esc_id AND user_id = $esc_user
    ");

    if (!$upd) {
        set_flash('error', 'Cancellation failed. Please try again.');
        redirect('modules/bookings/cancel.php?id=' . $booking_id);
    }

    // ── Update payment record if exists ──────────────────────────────────
    if ($do_refund) {
        $conn->query("
            UPDATE payments SET
                status         = 'refunded',
                refund_amount  = $refund_amount,
                refunded_at    = '$cancelled_at'
            WHERE booking_id = $esc_id
            ORDER BY id DESC
            LIMIT 1
        ");

        // ── Credit wallet ─────────────────────────────────────────────────
        $new_balance = (float)$booking['wallet_balance'] + $refund_amount;
        $esc_new_bal = $conn->real_escape_string($new_balance);
        $esc_ref     = $conn->real_escape_string($booking['booking_ref']);
        $esc_refamt  = $conn->real_escape_string($refund_amount);

        $conn->query("
            UPDATE users SET wallet_balance = $esc_new_bal
            WHERE id = $esc_user
        ");

        // ── Wallet transaction log ────────────────────────────────────────
        $desc = $conn->real_escape_string("Refund for cancelled booking #" . $booking['booking_ref']);
        $conn->query("
            INSERT INTO wallet_transactions
                (user_id, type, amount, balance_after, description, ref_id, ref_type, created_at)
            VALUES
                ($esc_user, 'credit', $esc_refamt, $esc_new_bal, '$desc', $esc_id, 'booking', '$cancelled_at')
        ");
    }

    // ── Release transport seats if applicable ─────────────────────────────
    if ($booking['booking_type'] === 'transport') {
        $ri = $conn->query("
            SELECT item_id FROM booking_items
            WHERE booking_id = $esc_id AND item_type = 'transport_seat'
        ");
        if ($ri) {
            while ($seat = $ri->fetch_assoc()) {
                $sid = (int)$seat['item_id'];
                $conn->query("UPDATE transport_seats SET is_booked = 0 WHERE id = $sid");
            }
        }
        // Re-increment available seats on the route
        $ri2 = $conn->query("
            SELECT item_id, quantity FROM booking_items
            WHERE booking_id = $esc_id AND item_type = 'transport_route'
        ");
        if ($ri2 && $ri2->num_rows > 0) {
            $row  = $ri2->fetch_assoc();
            $rtid = (int)$row['item_id'];
            $qty  = (int)$row['quantity'];
            $conn->query("
                UPDATE transport_routes
                SET available_seats = available_seats + $qty
                WHERE id = $rtid
            ");
        }
    }

    $msg = $do_refund
        ? 'Booking cancelled. ₹' . number_format($refund_amount, 2) . ' has been refunded to your wallet.'
        : 'Booking cancelled successfully.';

    set_flash('success', $msg);
    redirect('modules/bookings/my_bookings.php');
}

// ── GET: show confirmation page ───────────────────────────────────────────
$type_icon = match($booking['booking_type']) {
    'hotel'     => 'building',
    'guide'     => 'person-badge',
    'transport' => 'bus-front',
    'package'   => 'suitcase',
    default     => 'tag',
};

$is_paid   = $booking['payment_status'] === 'paid';
$refund_amt = (float)$booking['final_amount'];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>

    <div class="main-content w-100">

        <!-- ── Topbar ─────────────────────────────────────────────────── -->
        <div class="topbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $booking_id ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <h5 class="mb-0">Cancel Booking</h5>
            </div>
            <code class="text-muted"><?= htmlspecialchars($booking['booking_ref']) ?></code>
        </div>

        <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mx-3 mt-3" role="alert">
            <?= $flash['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="row justify-content-center">
                <div class="col-lg-6 col-md-8">

                    <!-- ── Warning banner ────────────────────────────────── -->
                    <div class="alert alert-warning d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-exclamation-triangle-fill fs-4 mt-1 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold mb-1">Are you sure you want to cancel?</div>
                            <div class="small">This action <strong>cannot be undone</strong>.
                                Once cancelled, your booking will be permanently marked as cancelled.</div>
                        </div>
                    </div>

                    <!-- ── Booking summary card ──────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h6 class="mb-0 fw-semibold">
                                <i class="bi bi-receipt-cutoff text-primary me-2"></i>
                                Booking Summary
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 small">
                                <div class="col-5 text-muted">Reference</div>
                                <div class="col-7">
                                    <code><?= htmlspecialchars($booking['booking_ref']) ?></code>
                                </div>

                                <div class="col-5 text-muted">Type</div>
                                <div class="col-7">
                                    <span class="badge bg-light text-dark border">
                                        <i class="bi bi-<?= $type_icon ?> me-1"></i>
                                        <?= ucfirst(htmlspecialchars($booking['booking_type'])) ?>
                                    </span>
                                </div>

                                <div class="col-5 text-muted">Status</div>
                                <div class="col-7">
                                    <span class="badge <?=
                                        $booking['booking_status'] === 'confirmed'
                                            ? 'bg-success' : 'bg-warning text-dark'
                                    ?>">
                                        <?= ucfirst($booking['booking_status']) ?>
                                    </span>
                                </div>

                                <?php if ($booking['check_in']): ?>
                                <div class="col-5 text-muted">Travel Date</div>
                                <div class="col-7">
                                    <?= date('d M Y', strtotime($booking['check_in'])) ?>
                                </div>
                                <?php endif; ?>

                                <div class="col-5 text-muted">Amount Paid</div>
                                <div class="col-7 fw-semibold">
                                    ₹<?= number_format($booking['final_amount'], 2) ?>
                                </div>

                                <div class="col-5 text-muted">Payment</div>
                                <div class="col-7">
                                    <span class="badge <?= $is_paid ? 'bg-success' : 'bg-warning text-dark' ?>">
                                        <?= ucfirst($booking['payment_status']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Refund notice ─────────────────────────────────── -->
                    <?php if ($is_paid): ?>
                    <div class="alert alert-success d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-wallet2 fs-4 mt-1 flex-shrink-0"></i>
                        <div>
                            <div class="fw-semibold mb-1">Refund Eligible</div>
                            <div class="small">
                                ₹<?= number_format($refund_amt, 2) ?> will be refunded to your
                                TravelMate wallet immediately after cancellation.
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary d-flex align-items-start gap-3 mb-4">
                        <i class="bi bi-info-circle fs-4 mt-1 flex-shrink-0"></i>
                        <div class="small">
                            No refund applicable as payment was not completed for this booking.
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ── Cancellation form ──────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom py-3">
                            <h6 class="mb-0 fw-semibold">
                                <i class="bi bi-chat-left-text text-danger me-2"></i>
                                Cancellation Reason
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
                                <input type="hidden" name="booking_id" value="<?= $booking_id ?>">

                                <!-- Quick reason selector -->
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">
                                        Quick Select <span class="text-muted fw-normal">(optional)</span>
                                    </label>
                                    <div class="d-flex flex-wrap gap-2" id="quickReasons">
                                        <?php
                                        $quick = [
                                            'Change of plans',
                                            'Found a better option',
                                            'Emergency / personal reason',
                                            'Incorrect booking details',
                                            'Travel dates changed',
                                        ];
                                        foreach ($quick as $q): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary quick-reason"
                                                data-reason="<?= htmlspecialchars($q, ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($q) ?>
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label for="reason" class="form-label small fw-semibold">
                                        Describe your reason <span class="text-danger">*</span>
                                    </label>
                                    <textarea id="reason" name="reason" class="form-control" rows="4"
                                              placeholder="Please tell us why you want to cancel..."
                                              maxlength="500" required></textarea>
                                    <div class="form-text d-flex justify-content-between">
                                        <span>Be as specific as possible.</span>
                                        <span id="charCount">0 / 500</span>
                                    </div>
                                </div>

                                <div class="d-flex gap-2">
                                    <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $booking_id ?>"
                                       class="btn btn-outline-secondary flex-fill">
                                        <i class="bi bi-arrow-left me-1"></i>Keep My Booking
                                    </a>
                                    <button type="submit" class="btn btn-danger flex-fill"
                                            id="submitBtn">
                                        <i class="bi bi-x-circle me-1"></i>
                                        <?= $is_paid ? 'Cancel & Get Refund' : 'Cancel Booking' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div><!-- /col -->
            </div><!-- /row -->
        </div><!-- /p-4 -->

    </div><!-- /main-content -->
</div>

<script>
// Quick reason chips → fill textarea
document.querySelectorAll('.quick-reason').forEach(btn => {
    btn.addEventListener('click', () => {
        const ta = document.getElementById('reason');
        ta.value = btn.dataset.reason;
        updateCount();
        // highlight selected
        document.querySelectorAll('.quick-reason').forEach(b => b.classList.remove('btn-secondary'));
        document.querySelectorAll('.quick-reason').forEach(b => b.classList.add('btn-outline-secondary'));
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-secondary');
    });
});

// Char counter
const reasonTa = document.getElementById('reason');
const counter  = document.getElementById('charCount');
function updateCount() {
    const len = reasonTa.value.length;
    counter.textContent = len + ' / 500';
    counter.style.color = len > 450 ? '#dc3545' : '';
}
reasonTa.addEventListener('input', updateCount);

// Confirm before submit
document.querySelector('form').addEventListener('submit', function(e) {
    if (!document.getElementById('reason').value.trim()) {
        e.preventDefault();
        document.getElementById('reason').classList.add('is-invalid');
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled    = true;
    btn.innerHTML   = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>