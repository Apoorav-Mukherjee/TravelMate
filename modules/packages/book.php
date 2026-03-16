<?php
$page_title = 'Book Package';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$pkg_id      = (int)($_GET['pkg_id']      ?? 0);
$travel_date = sanitize($_GET['travel_date'] ?? '');
$persons     = max(1, (int)($_GET['persons']  ?? 1));
$errors      = [];

if (!$pkg_id || !$travel_date) {
    set_flash('error', 'Missing booking details.');
    redirect('modules/packages/search.php');
}

if (strtotime($travel_date) < strtotime(date('Y-m-d'))) {
    set_flash('error', 'Travel date must be in the future.');
    redirect("modules/packages/detail.php?slug=$pkg_id");
}

// Fetch package
$stmt = $conn->prepare("
    SELECT * FROM tour_packages
    WHERE id = ? AND is_approved = 1 AND status = 'active'
");
$stmt->bind_param('i', $pkg_id);
$stmt->execute();
$pkg = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pkg) {
    set_flash('error', 'Package not available.');
    redirect('modules/packages/search.php');
}

// Validate persons
$persons = max($pkg['min_persons'], min($pkg['max_persons'], $persons));

// Validate date range
if ($pkg['valid_from'] && $travel_date < $pkg['valid_from']) {
    set_flash('error', 'Travel date is before package validity.');
    redirect("modules/packages/detail.php?slug=$pkg_id");
}
if ($pkg['valid_until'] && $travel_date > $pkg['valid_until']) {
    set_flash('error', 'Travel date is after package validity.');
    redirect("modules/packages/detail.php?slug=$pkg_id");
}

// Price calculation
$price_per_person = $pkg['discount_percent'] > 0
    ? round($pkg['fixed_price'] * (1 - $pkg['discount_percent'] / 100), 2)
    : $pkg['fixed_price'];

$subtotal = $price_per_person * $persons;
$tax      = round($subtotal * 0.05, 2);
$total    = $subtotal + $tax;

// Fetch components
$stmt = $conn->prepare("
    SELECT pc.*,
        CASE pc.component_type
            WHEN 'hotel'     THEN (SELECT name FROM hotels WHERE id = pc.component_id)
            WHEN 'guide'     THEN (SELECT u2.full_name FROM guides g JOIN users u2 ON g.user_id=u2.id WHERE g.id=pc.component_id)
            WHEN 'transport' THEN (SELECT CONCAT(source,' → ',destination) FROM transport_routes WHERE id=pc.component_id)
        END AS component_name
    FROM package_components pc
    WHERE pc.package_id = ?
    ORDER BY pc.day_number ASC
");
$stmt->bind_param('i', $pkg_id);
$stmt->execute();
$components = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Wallet balance
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

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $ref       = generate_booking_ref();
            $check_out = date(
                'Y-m-d',
                strtotime($travel_date . ' +' . $pkg['duration_days'] . ' days')
            );

            // Insert booking
            $stmt = $conn->prepare("
    INSERT INTO bookings
        (user_id, booking_type, booking_ref,
         total_amount, discount_amount, final_amount,
         payment_status, booking_status,
         check_in, check_out, notes)
    VALUES (?, 'package', ?, ?, 0, ?, 'pending', 'pending', ?, ?, ?)
");
            // Type string: i=user_id, s=ref, d=total, d=total, s=travel_date, s=check_out, s=notes
            // 7 variables = 7 chars ✓
            $stmt->bind_param(
                'isddsss',
                $user_id,
                $ref,
                $total,
                $total,
                $travel_date,
                $check_out,
                $notes
            );
            if (!$stmt->execute()) {
                throw new Exception('Booking insert failed: ' . $stmt->error);
            }
            $booking_id = $conn->insert_id;
            $stmt->close();

            // Insert booking item
            $meta = json_encode([
                'package_name'    => $pkg['title'],
                'destination'     => $pkg['city'],
                'persons'         => $persons,
                'price_per_person' => $price_per_person,
                'duration_days'   => $pkg['duration_days'],
                'travel_date'     => $travel_date,
                'tax'             => $tax,
                'components'      => count($components),
            ]);
            $stmt = $conn->prepare("
                INSERT INTO booking_items
                    (booking_id, item_type, item_id, quantity, unit_price, subtotal, meta)
                VALUES (?, 'package', ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'iiidds',
                $booking_id,
                $pkg_id,
                $persons,
                $price_per_person,
                $total,
                $meta
            );
            $stmt->execute();
            $stmt->close();

            // Insert package booking record
            $stmt = $conn->prepare("
                INSERT INTO package_bookings
                    (booking_id, package_id, persons, travel_date)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->bind_param('iiis', $booking_id, $pkg_id, $persons, $travel_date);
            $stmt->execute();
            $stmt->close();

            // Wallet payment
            if ($payment_method === 'wallet') {
                // Deduct wallet
                $stmt = $conn->prepare("
                    UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?
                ");
                $stmt->bind_param('di', $total, $user_id);
                $stmt->execute();
                $stmt->close();

                $new_bal  = $wallet - $total;
                $desc     = "Package booking - {$pkg['title']} - $ref";
                $stmt = $conn->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_after, description, ref_id, ref_type)
                    VALUES (?, 'debit', ?, ?, ?, ?, 'booking')
                ");
                $stmt->bind_param('iddsi', $user_id, $total, $new_bal, $desc, $booking_id);
                $stmt->execute();
                $stmt->close();

                // Confirm
                $stmt = $conn->prepare("
                    UPDATE bookings
                    SET payment_status='paid', booking_status='confirmed'
                    WHERE id=?
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

                // Apply cashback
                require_once __DIR__ . '/../wallet/cashback.php';
                apply_cashback($conn, $user_id, $booking_id, $total);

                // Email
                $details = "
                    <p>Package: <strong>{$pkg['title']}</strong></p>
                    <p>Destination: {$pkg['city']}</p>
                    <p>Travel Date: $travel_date</p>
                    <p>Persons: $persons</p>
                    <p>Duration: {$pkg['duration_days']} days</p>
                    <p>Total Paid: ₹" . number_format($total, 2) . "</p>
                ";
                $body = email_booking_confirm_template(
                    $_SESSION['full_name'],
                    $ref,
                    $details
                );
                // send_email(
                //     $_SESSION['email'],
                //     $_SESSION['full_name'],
                //     'Package Booking Confirmed - ' . $ref,
                //     $body
                // );

                set_flash('success', "Package booked! Reference: $ref");
                redirect("modules/packages/booking_confirmation.php?booking_id=$booking_id");
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
            <h5 class="mb-0">Book Package</h5>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Booking Details</div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="mb-3">
                                <label class="form-label">Special Requests</label>
                                <textarea name="notes" class="form-control" rows="3"
                                    placeholder="Dietary requirements, accessibility needs, etc."></textarea>
                            </div>

                            <h6 class="fw-bold mb-3">Payment Method</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="d-block">
                                        <input type="radio" name="payment_method"
                                            value="stripe"
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
                                        <input type="radio" name="payment_method"
                                            value="razorpay"
                                            class="d-none payment-radio">
                                        <div class="card payment-option border p-3 text-center">
                                            <i class="bi bi-phone fs-2 text-success"></i>
                                            <div class="fw-semibold">UPI</div>
                                            <small class="text-muted">Razorpay</small>
                                        </div>
                                    </label>
                                </div>
                                <div class="col-md-4">
                                    <label class="d-block">
                                        <input type="radio" name="payment_method"
                                            value="wallet"
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
                    <div class="card-header bg-white fw-bold">Order Summary</div>
                    <div class="card-body">
                        <h6 class="fw-bold">
                            <?= htmlspecialchars($pkg['title']) ?>
                        </h6>
                        <p class="text-muted small">
                            <i class="bi bi-geo-alt text-danger"></i>
                            <?= htmlspecialchars($pkg['city'] ?? '') ?>
                        </p>
                        <hr>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Travel Date</span>
                            <span><?= date('d M Y', strtotime($travel_date)) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Duration</span>
                            <span><?= $pkg['duration_days'] ?> Days</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Persons</span>
                            <span><?= $persons ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Price/Person</span>
                            <span>₹<?= number_format($price_per_person, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>Subtotal</span>
                            <span>₹<?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2 text-muted">
                            <span>GST (5%)</span>
                            <span>₹<?= number_format($tax, 2) ?></span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold fs-5">
                            <span>Total</span>
                            <span class="text-primary">
                                ₹<?= number_format($total, 2) ?>
                            </span>
                        </div>

                        <!-- What's Included -->
                        <?php if ($components): ?>
                            <hr>
                            <div class="small text-muted fw-bold mb-2">INCLUDES</div>
                            <?php foreach ($components as $c): ?>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="bi bi-check-circle-fill text-success" style="font-size:12px"></i>
                                    <span style="font-size:12px">
                                        <?= ucfirst($c['component_type']) ?>:
                                        <?= htmlspecialchars($c['component_name'] ?? '') ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
    .payment-radio:checked+.payment-option {
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