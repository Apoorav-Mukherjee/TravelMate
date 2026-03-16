<?php
$page_title = 'Confirm Transport Booking';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

// Validate session data
if (empty($_SESSION['transport_booking'])) {
    set_flash('error', 'No booking data found. Please select seats again.');
    redirect('modules/transport/search.php');
}

$tb       = $_SESSION['transport_booking'];
$route_id = $tb['route_id'];
$seat_ids = $tb['seat_ids'];
$seats    = $tb['seat_details'];
$errors   = [];

// Fetch route info
$stmt = $conn->prepare("
    SELECT r.*, tp.company_name
    FROM transport_routes r
    JOIN transport_providers tp ON r.provider_id = tp.id
    WHERE r.id = ? AND r.status != 'cancelled'
");
$stmt->bind_param('i', $route_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
    set_flash('error', 'Route no longer available.');
    redirect('modules/transport/search.php');
}

// Calculate totals
$subtotal = array_sum(array_column($seats, 'price'));
$tax      = round($subtotal * 0.05, 2);
$total    = $subtotal + $tax;

// Fetch wallet
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();

// Process booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $payment_method = sanitize($_POST['payment_method'] ?? 'stripe');
    $notes          = sanitize($_POST['notes'] ?? '');
    $user_id        = $_SESSION['user_id'];

    if ($payment_method === 'wallet' && $wallet < $total) {
        $errors[] = 'Insufficient wallet balance.';
    }

    // Re-check seat availability
    if (empty($errors)) {
        $placeholders = implode(',', array_fill(0, count($seat_ids), '?'));
        $types_str    = str_repeat('i', count($seat_ids));
        $stmt = $conn->prepare("
            SELECT COUNT(*) as cnt FROM transport_seats
            WHERE id IN ($placeholders) AND is_booked = 0
        ");
        $stmt->bind_param($types_str, ...$seat_ids);
        $stmt->execute();
        $available_count = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($available_count < count($seat_ids)) {
            set_flash('error', 'Some seats were just booked by another user. Please reselect.');
            unset($_SESSION['transport_booking']);
            redirect("modules/transport/select_seats.php?route_id=$route_id");
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $ref          = generate_booking_ref();
            $journey_date = $route['journey_date'];

            // ── FIX 1: type string was 'isddssss' (8) but only 7 values ──
            // Correct: 'isddsss' = i(user_id) s(ref) d(total) d(total)
            //                      s(journey_date) s(journey_date) s(notes)
            $stmt = $conn->prepare("
                INSERT INTO bookings
                    (user_id, booking_type, booking_ref, total_amount, final_amount,
                     payment_status, booking_status, check_in, check_out, notes)
                VALUES (?, 'transport', ?, ?, ?, 'pending', 'pending', ?, ?, ?)
            ");
            $stmt->bind_param('isddsss',
                $user_id, $ref, $total, $total,
                $journey_date, $journey_date, $notes);
            $stmt->execute();
            $booking_id = $conn->insert_id;
            $stmt->close();

            // ── FIX 2: removed duplicate booking_items INSERT block ──
            // The original code had two identical prepare/bind_param blocks,
            // and the first one was missing execute(). Now there is only one.
            foreach ($seats as $seat) {
                $meta = json_encode([
                    'seat_number'    => $seat['seat_number'],
                    'seat_class'     => $seat['seat_class'],
                    'route_id'       => $route_id,
                    'source'         => $route['source'],
                    'destination'    => $route['destination'],
                    'departure'      => $route['departure_time'],
                    'arrival'        => $route['arrival_time'],
                    'journey_date'   => $route['journey_date'],
                    'company'        => $route['company_name'],
                    'transport_type' => $route['transport_type'],
                    'tax'            => round($seat['price'] * 0.05, 2),
                ]);

                $seat_subtotal = $seat['price'] + round($seat['price'] * 0.05, 2);

                $stmt = $conn->prepare("
                    INSERT INTO booking_items
                        (booking_id, item_type, item_id, quantity, unit_price, subtotal, meta)
                    VALUES (?, 'seat', ?, 1, ?, ?, ?)
                ");
                $stmt->bind_param('iidds',
                    $booking_id, $seat['id'],
                    $seat['price'], $seat_subtotal, $meta);
                $stmt->execute();
                $stmt->close();

                // Mark seat as booked
                $conn->query("UPDATE transport_seats SET is_booked = 1 WHERE id = " . (int)$seat['id']);
            }

            // Update available seats on route
            $conn->query("
                UPDATE transport_routes
                SET available_seats = available_seats - " . count($seat_ids) . "
                WHERE id = " . (int)$route_id . "
            ");

            // ── Wallet payment ────────────────────────────────────────────
            if ($payment_method === 'wallet') {
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
                $stmt->bind_param('di', $total, $user_id);
                $stmt->execute();
                $stmt->close();

                $new_balance = $wallet - $total;
                $desc        = "Transport booking $ref";
                $stmt = $conn->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_after, description, ref_id, ref_type)
                    VALUES (?, 'debit', ?, ?, ?, ?, 'booking')
                ");
                $stmt->bind_param('iddsi', $user_id, $total, $new_balance, $desc, $booking_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    UPDATE bookings
                    SET payment_status = 'paid', booking_status = 'confirmed'
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $booking_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("
                    INSERT INTO payments (booking_id, user_id, gateway, amount, status)
                    VALUES (?, ?, 'wallet', ?, 'success')
                ");
                $stmt->bind_param('iid', $booking_id, $user_id, $total);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                unset($_SESSION['transport_booking']);

                // Send confirmation email
                $seat_list = implode(', ', array_column($seats, 'seat_number'));
                $details   = "
                    <p>Route: <strong>{$route['source']} → {$route['destination']}</strong></p>
                    <p>Date: {$route['journey_date']}</p>
                    <p>Departure: " . date('H:i', strtotime($route['departure_time'])) . "</p>
                    <p>Seats: $seat_list</p>
                    <p>Total: ₹" . number_format($total, 2) . "</p>
                ";
                $body = email_booking_confirm_template(
                    $_SESSION['full_name'], $ref, $details
                );
                send_email($_SESSION['email'], $_SESSION['full_name'],
                           'Transport Booking Confirmed - ' . $ref, $body);

                set_flash('success', "Booking confirmed! Reference: $ref");
                redirect("modules/transport/ticket.php?booking_id=$booking_id");

            } else {
                // Stripe / Razorpay
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
            <h5 class="mb-0">Confirm Booking</h5>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mx-3 mt-3">
            <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="row g-4">

                <!-- ── Booking Form ─────────────────────────────────────── -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-3">
                            Booking Information
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                                <div class="mb-4">
                                    <label class="form-label fw-semibold">
                                        Special Requests <span class="text-muted fw-normal">(Optional)</span>
                                    </label>
                                    <textarea name="notes" class="form-control" rows="2"
                                              placeholder="e.g. Wheelchair access, extra luggage..."></textarea>
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
                                                    ₹<?= number_format($wallet, 2) ?>
                                                </small>
                                            </div>
                                        </label>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-100">
                                    <i class="bi bi-lock-fill me-2"></i>
                                    Confirm & Pay — ₹<?= number_format($total, 2) ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- ── Trip Summary ─────────────────────────────────────── -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-3">Trip Summary</div>
                        <div class="card-body">
                            <h6 class="fw-bold">
                                <?= htmlspecialchars($route['source']) ?> →
                                <?= htmlspecialchars($route['destination']) ?>
                            </h6>
                            <p class="text-muted small mb-3">
                                <?= htmlspecialchars($route['company_name']) ?> &bull;
                                <?= ucfirst($route['transport_type']) ?><br>
                                <?= date('d M Y', strtotime($route['journey_date'])) ?><br>
                                <?= date('H:i', strtotime($route['departure_time'])) ?> →
                                <?= date('H:i', strtotime($route['arrival_time'])) ?>
                            </p>
                            <hr>
                            <h6 class="fw-semibold mb-2">Selected Seats</h6>
                            <?php foreach ($seats as $seat): ?>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span>
                                    Seat <strong><?= htmlspecialchars($seat['seat_number']) ?></strong>
                                    <span class="text-muted">(<?= $seat['seat_class'] ?>)</span>
                                </span>
                                <span>₹<?= number_format($seat['price'], 0) ?></span>
                            </div>
                            <?php endforeach; ?>
                            <hr>
                            <div class="d-flex justify-content-between mb-1 small">
                                <span>Subtotal</span>
                                <span>₹<?= number_format($subtotal, 2) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1 small text-muted">
                                <span>Tax (5%)</span>
                                <span>₹<?= number_format($tax, 2) ?></span>
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between fw-bold fs-5">
                                <span>Total</span>
                                <span class="text-primary">₹<?= number_format($total, 2) ?></span>
                            </div>

                            <div class="alert alert-warning mt-3 small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Cancellation allowed up to 2 hours before departure.
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /row -->
        </div><!-- /p-4 -->

    </div><!-- /main-content -->
</div>

<style>
.payment-radio:checked + .payment-option {
    border-color: #0d6efd !important;
    background: #f0f4ff;
}
.payment-option {
    cursor: pointer;
    border-radius: 10px;
    transition: all 0.2s;
}
</style>
<script>
document.querySelectorAll('.payment-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.payment-option').forEach(opt => {
            opt.classList.remove('border-primary', 'border-2');
            opt.classList.add('border');
        });
        if (radio.checked) {
            radio.nextElementSibling.classList.add('border-primary', 'border-2');
            radio.nextElementSibling.classList.remove('border');
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>