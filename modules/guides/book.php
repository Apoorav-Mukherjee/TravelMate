<?php
$page_title = 'Book Guide';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$guide_id = (int)($_GET['guide_id'] ?? 0);
$date     = sanitize($_GET['date']  ?? '');
$type     = sanitize($_GET['type']  ?? 'daily');
$hours    = max(1, min(12, (int)($_GET['hours'] ?? 2)));
$errors   = [];

if (!$guide_id || !$date) redirect('modules/guides/search.php');

if (strtotime($date) < strtotime(date('Y-m-d'))) {
    set_flash('error', 'Please select a future date.');
    redirect("modules/guides/profile.php?id=$guide_id");
}

// Fetch guide
$stmt = $conn->prepare("
    SELECT g.*, u.full_name, u.profile_picture, u.email
    FROM guides g
    JOIN users u ON g.user_id = u.id
    WHERE g.id = ? AND g.status = 'active'
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guide) {
    set_flash('error', 'Guide not found.');
    redirect('modules/guides/search.php');
}

// Check availability conflict
$stmt = $conn->prepare("
    SELECT COUNT(*) as cnt
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    WHERE bi.item_type = 'guide_session' AND bi.item_id = ?
      AND b.booking_status NOT IN ('cancelled')
      AND b.check_in = ?
");
$stmt->bind_param('is', $guide_id, $date);
$stmt->execute();
$conflict = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($conflict > 0) {
    set_flash('error', 'This guide is already booked on the selected date.');
    redirect("modules/guides/profile.php?id=$guide_id");
}

// Check availability calendar block
$stmt = $conn->prepare("
    SELECT is_available FROM availability_calendars
    WHERE entity_type = 'guide' AND entity_id = ? AND date = ?
");
$stmt->bind_param('is', $guide_id, $date);
$stmt->execute();
$avail = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($avail && !$avail['is_available']) {
    set_flash('error', 'Guide is unavailable on this date.');
    redirect("modules/guides/profile.php?id=$guide_id");
}

// Calculate price
if ($type === 'hourly') {
    $rate    = $guide['hourly_rate'];
    $qty     = $hours;
    $label   = "$hours hour(s)";
    $unit    = 'hour';
} else {
    $rate    = $guide['daily_rate'];
    $qty     = 1;
    $label   = '1 full day';
    $unit    = 'day';
}

$subtotal    = $rate * $qty;
$commission  = round($subtotal * ($guide['commission_rate'] / 100), 2);
$tax         = round($subtotal * 0.05, 2); // 5% service tax
$total       = $subtotal + $tax;

// Fetch wallet balance
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();

// Handle booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $payment_method = sanitize($_POST['payment_method'] ?? 'stripe');
    $notes          = sanitize($_POST['notes'] ?? '');
    $user_id        = $_SESSION['user_id'];

    if ($payment_method === 'wallet' && $wallet < $total) {
        $errors[] = 'Insufficient wallet balance.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $ref = generate_booking_ref();

            // Insert booking
            $stmt = $conn->prepare("
                INSERT INTO bookings
                    (user_id, booking_type, booking_ref, total_amount, final_amount,
                     payment_status, booking_status, check_in, check_out, notes)
                VALUES (?, 'guide', ?, ?, ?, 'pending', 'pending', ?, ?, ?)
            ");
            $stmt->bind_param('isddssss',
                $user_id, $ref, $total, $total, $date, $date, $notes);
            $stmt->execute();
            $booking_id = $conn->insert_id;
            $stmt->close();

            // Insert booking item
            $meta = json_encode([
                'guide_name'  => $guide['full_name'],
                'type'        => $type,
                'hours'       => $hours,
                'label'       => $label,
                'commission'  => $commission,
                'tax'         => $tax
            ]);
            $stmt = $conn->prepare("
                INSERT INTO booking_items
                    (booking_id, item_type, item_id, quantity, unit_price, subtotal, meta)
                VALUES (?, 'guide_session', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('iiidds', $booking_id, $guide_id, $qty, $rate, $total, $meta);
            $stmt->execute();
            $stmt->close();

            // Process payment
            if ($payment_method === 'wallet') {
                // Deduct wallet
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->bind_param('di', $total, $user_id);
                $stmt->execute();
                $stmt->close();

                $new_balance = $wallet - $total;
                $desc = "Guide booking - {$guide['full_name']} - $ref";
                $stmt = $conn->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_after, description, ref_id, ref_type)
                    VALUES (?, 'debit', ?, ?, ?, ?, 'booking')
                ");
                $stmt->bind_param('iddsi', $user_id, $total, $new_balance, $desc, $booking_id);
                $stmt->execute();
                $stmt->close();

                // Confirm
                $stmt = $conn->prepare("
                    UPDATE bookings
                    SET payment_status = 'paid', booking_status = 'confirmed'
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $booking_id);
                $stmt->execute();
                $stmt->close();

                // Payment record
                $stmt = $conn->prepare("
                    INSERT INTO payments (booking_id, user_id, gateway, amount, status)
                    VALUES (?, ?, 'wallet', ?, 'success')
                ");
                $stmt->bind_param('iid', $booking_id, $user_id, $total);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                // Email traveler
                $details = "
                    <p>Guide: <strong>{$guide['full_name']}</strong></p>
                    <p>Date: $date | Duration: $label</p>
                    <p>Total: ₹" . number_format($total, 2) . "</p>
                ";
                $body = email_booking_confirm_template($_SESSION['full_name'], $ref, $details);
                send_email($_SESSION['email'], $_SESSION['full_name'],
                           'Guide Booking Confirmed - ' . $ref, $body);

                // Email guide
                $guide_body = "
                    <p>Hi {$guide['full_name']},</p>
                    <p>You have a new booking!</p>
                    <p>Date: $date | Duration: $label</p>
                    <p>Traveler: {$_SESSION['full_name']}</p>
                    <p>Booking Ref: $ref</p>
                ";
                send_email($guide['email'], $guide['full_name'],
                           'New Booking - ' . $ref, $guide_body);

                set_flash('success', "Guide booked successfully! Reference: $ref");
                redirect("modules/guides/booking_confirmation.php?booking_id=$booking_id");

            } else {
                $conn->commit();
                redirect("payments/checkout.php?booking_id=$booking_id&gateway=$payment_method");
            }

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Booking failed: ' . $e->getMessage();
        }
    }
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Book Guide</h5>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Booking Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Booking Details</div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="mb-3">
                                <label class="form-label">Special Instructions (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3"
                                          placeholder="e.g. Pickup location, specific areas to visit..."></textarea>
                            </div>

                            <h6 class="fw-bold mb-3">Payment Method</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="d-block">
                                        <input type="radio" name="payment_method" value="stripe"
                                               class="d-none payment-radio" checked>
                                        <div class="card payment-option border-2 border-primary p-3 text-center">
                                            <i class="bi bi-credit-card fs-2 text-primary"></i>
                                            <div class="fw-semibold">Credit Card</div>
                                            <small class="text-muted">Stripe</small>
                                        </div>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="d-block">
                                        <input type="radio" name="payment_method" value="razorpay"
                                               class="d-none payment-radio">
                                        <div class="card payment-option border p-3 text-center">
                                            <i class="bi bi-phone fs-2 text-success"></i>
                                            <div class="fw-semibold">UPI / Razorpay</div>
                                            <small class="text-muted">Razorpay</small>
                                        </div>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="d-block">
                                        <input type="radio" name="payment_method" value="wallet"
                                               class="d-none payment-radio">
                                        <div class="card payment-option border p-3 text-center">
                                            <i class="bi bi-wallet2 fs-2 text-warning"></i>
                                            <div class="fw-semibold">Wallet</div>
                                            <small class="text-muted">
                                                Balance: ₹<?= number_format($wallet, 2) ?>
                                            </small>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="bi bi-lock-fill"></i>
                                Confirm Booking — ₹<?= number_format($total, 2) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Booking Summary</div>
                    <div class="card-body">
                        <div class="d-flex gap-3 align-items-center mb-3">
                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($guide['profile_picture'] ?: 'default.png') ?>"
                                 class="rounded-circle"
                                 style="width:55px;height:55px;object-fit:cover">
                            <div>
                                <div class="fw-bold"><?= htmlspecialchars($guide['full_name']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($guide['city'] ?? '') ?></small>
                            </div>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Date</span>
                            <span><?= date('d M Y', strtotime($date)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Duration</span>
                            <span><?= $label ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Rate</span>
                            <span>₹<?= number_format($rate, 2) ?>/<?= $unit ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span>₹<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-muted">
                            <span>Service Tax (5%)</span>
                            <span>₹<?= number_format($tax, 2) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total</span>
                            <span class="text-primary">₹<?= number_format($total, 2) ?></span>
                        </div>
                        <div class="alert alert-info mt-3 small">
                            <i class="bi bi-info-circle"></i>
                            Free cancellation up to 24 hours before your booking date.
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
.payment-radio:checked + .payment-option {
    border-color: #0d6efd !important;
    background: #f0f4ff;
}
.payment-option { cursor: pointer; border-radius: 10px; transition: all 0.2s; }
</style>
<script>
document.querySelectorAll('.payment-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.payment-option').forEach(opt => {
            opt.classList.remove('border-primary');
            opt.classList.add('border');
        });
        if (radio.checked) {
            radio.nextElementSibling.classList.add('border-primary');
            radio.nextElementSibling.classList.remove('border');
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>