<?php
$page_title = 'Book Room';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$room_id   = (int)($_GET['room_id']  ?? 0);
$hotel_id  = (int)($_GET['hotel_id'] ?? 0);
$check_in  = sanitize($_GET['check_in']  ?? '');
$check_out = sanitize($_GET['check_out'] ?? '');
$errors    = [];

if (!$room_id || !$hotel_id) redirect('modules/hotels/search.php');

// Validate dates
if (!$check_in || !$check_out || strtotime($check_in) >= strtotime($check_out)) {
    set_flash('error', 'Please select valid check-in and check-out dates.');
    redirect("modules/hotels/detail.php?id=$hotel_id");
}

// Fetch room + hotel
$stmt = $conn->prepare("
    SELECT r.*, h.name as hotel_name, h.city, h.address
    FROM rooms r
    JOIN hotels h ON r.hotel_id = h.id
    WHERE r.id = ? AND r.hotel_id = ? AND r.status = 'available'
");
$stmt->bind_param('ii', $room_id, $hotel_id);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$room) {
    set_flash('error', 'Room not found or unavailable.');
    redirect("modules/hotels/detail.php?id=$hotel_id");
}

// Check availability
$stmt = $conn->prepare("
    SELECT COUNT(*) as cnt
    FROM booking_items bi
    JOIN bookings b ON bi.booking_id = b.id
    WHERE bi.item_type = 'room' AND bi.item_id = ?
      AND b.booking_status NOT IN ('cancelled')
      AND b.check_in  < ?
      AND b.check_out > ?
");
$stmt->bind_param('iss', $room_id, $check_out, $check_in);
$stmt->execute();
$conflict = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($conflict > 0) {
    set_flash('error', 'Sorry, this room is already booked for the selected dates.');
    redirect("modules/hotels/detail.php?id=$hotel_id&check_in=$check_in&check_out=$check_out");
}

// Calculate price
$nights = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));
$price  = $room['base_price'];

// Check seasonal override
$stmt = $conn->prepare("
    SELECT override_price FROM availability_calendars
    WHERE entity_type = 'room' AND entity_id = ? AND date = ? AND override_price IS NOT NULL
");
$stmt->bind_param('is', $room_id, $check_in);
$stmt->execute();
$override = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($override) $price = $override['override_price'];

$subtotal = $price * $nights;
$tax      = round($subtotal * 0.12, 2); // 12% GST
$total    = $subtotal + $tax;

// Fetch user wallet
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $payment_method = sanitize($_POST['payment_method'] ?? 'stripe');
    $notes          = sanitize($_POST['notes'] ?? '');
    $user_id        = $_SESSION['user_id'];

    // Wallet check
    if ($payment_method === 'wallet' && $wallet < $total) {
        $errors[] = 'Insufficient wallet balance. Please top up or use another payment method.';
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $ref = generate_booking_ref();

            // Insert booking
            $stmt = $conn->prepare("
                INSERT INTO bookings
                    (user_id, booking_type, booking_ref, total_amount, discount_amount,
                     final_amount, payment_status, booking_status, check_in, check_out, notes)
                VALUES (?, 'hotel', ?, ?, 0, ?, 'pending', 'pending', ?, ?, ?)
            ");
            $stmt->bind_param('isddss s', $user_id, $ref, $total, $total, $check_in, $check_out, $notes);
            $stmt->bind_param('isddssss', $user_id, $ref, $total, $total, $check_in, $check_out, $notes, $notes);

            // Fix bind
            $stmt->close();
            $stmt = $conn->prepare("
                INSERT INTO bookings
                    (user_id, booking_type, booking_ref, total_amount, final_amount,
                     payment_status, booking_status, check_in, check_out, notes)
                VALUES (?, 'hotel', ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $pay_status = 'pending';
            $bk_status  = 'pending';
            $stmt->bind_param('isddssss', $user_id, $ref, $total, $total, $pay_status, $bk_status, $check_in, $check_out);
            $stmt->execute();
            $booking_id = $conn->insert_id;
            $stmt->close();

            // Insert booking item
            $stmt = $conn->prepare("
                INSERT INTO booking_items
                    (booking_id, item_type, item_id, quantity, unit_price, subtotal, meta)
                VALUES (?, 'room', ?, 1, ?, ?, ?)
            ");
            $meta = json_encode([
                'hotel_id'   => $hotel_id,
                'hotel_name' => $room['hotel_name'],
                'room_type'  => $room['room_type'],
                'nights'     => $nights,
                'tax'        => $tax
            ]);
            $stmt->bind_param('iidds', $booking_id, $room_id, $price, $total, $meta);
            $stmt->execute();
            $stmt->close();

            // Process payment
            if ($payment_method === 'wallet') {
                // Deduct from wallet
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->bind_param('di', $total, $user_id);
                $stmt->execute();
                $stmt->close();

                // Log wallet transaction
                $new_balance = $wallet - $total;
                $desc = "Hotel booking payment - $ref";
                $stmt = $conn->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_after, description, ref_id, ref_type)
                    VALUES (?, 'debit', ?, ?, ?, ?, 'booking')
                ");
                $stmt->bind_param('iddsi', $user_id, $total, $new_balance, $desc, $booking_id);
                $stmt->execute();
                $stmt->close();

                // Mark as paid
                $stmt = $conn->prepare("
                    UPDATE bookings SET payment_status = 'paid', booking_status = 'confirmed' WHERE id = ?
                ");
                $stmt->bind_param('i', $booking_id);
                $stmt->execute();
                $stmt->close();

                // Insert payment record
                $stmt = $conn->prepare("
                    INSERT INTO payments (booking_id, user_id, gateway, amount, status)
                    VALUES (?, ?, 'wallet', ?, 'success')
                ");
                $stmt->bind_param('iid', $booking_id, $user_id, $total);
                $stmt->execute();
                $stmt->close();

                $conn->commit();

                // Send confirmation email
                $details = "
                    <p>Hotel: <strong>{$room['hotel_name']}</strong></p>
                    <p>Room: {$room['room_type']}</p>
                    <p>Check-in: $check_in | Check-out: $check_out</p>
                    <p>Nights: $nights | Total: ₹" . number_format($total, 2) . "</p>
                ";
                $body = email_booking_confirm_template($_SESSION['full_name'], $ref, $details);
                send_email($_SESSION['email'], $_SESSION['full_name'], 'Booking Confirmed - ' . $ref, $body);

                set_flash('success', 'Booking confirmed! Reference: ' . $ref);
                redirect("modules/hotels/invoice.php?booking_id=$booking_id");

            } else {
                // Stripe simulation — store booking_id in session for payment page
                $conn->commit();
                $_SESSION['pending_booking_id'] = $booking_id;
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
            <h5 class="mb-0">Book Room</h5>
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

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Check-in Date</label>
                                    <input type="text" class="form-control"
                                           value="<?= date('d M Y', strtotime($check_in)) ?>" readonly>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Check-out Date</label>
                                    <input type="text" class="form-control"
                                           value="<?= date('d M Y', strtotime($check_out)) ?>" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Special Requests (Optional)</label>
                                <textarea name="notes" class="form-control" rows="3"
                                          placeholder="e.g. Late check-in, extra pillows..."></textarea>
                            </div>

                            <hr>
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
                                            <small class="text-muted">Balance: ₹<?= number_format($wallet, 2) ?></small>
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

            <!-- Booking Summary -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Booking Summary</div>
                    <div class="card-body">
                        <h6 class="fw-bold"><?= htmlspecialchars($room['hotel_name']) ?></h6>
                        <p class="text-muted small mb-1">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars($room['city']) ?>
                        </p>
                        <p class="text-muted small"><?= htmlspecialchars($room['address']) ?></p>
                        <hr>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Room Type</span>
                            <span class="fw-semibold"><?= htmlspecialchars($room['room_type']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Check-in</span>
                            <span><?= date('d M Y', strtotime($check_in)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Check-out</span>
                            <span><?= date('d M Y', strtotime($check_out)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Nights</span>
                            <span><?= $nights ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Room Rate</span>
                            <span>₹<?= number_format($price, 2) ?>/night</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span>₹<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-muted">
                            <span>GST (12%)</span>
                            <span>₹<?= number_format($tax, 2) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total</span>
                            <span class="text-primary">₹<?= number_format($total, 2) ?></span>
                        </div>

                        <div class="alert alert-info mt-3 small">
                            <i class="bi bi-info-circle"></i>
                            Free cancellation up to 24 hours before check-in.
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
.payment-option:hover { border-color: #0d6efd !important; }
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