<?php
require_once __DIR__ . '/../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('dashboards/traveler/index.php');
verify_csrf();

$booking_id  = (int)($_POST['booking_id']       ?? 0);
$txn_id      = sanitize($_POST['simulated_txn_id'] ?? '');
$user_id     = $_SESSION['user_id'];

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

// Simulate successful payment
$conn->begin_transaction();
try {
    // Insert payment
    $stmt = $conn->prepare("
        INSERT INTO payments (booking_id, user_id, gateway, gateway_txn_id, amount, status)
        VALUES (?, ?, 'stripe', ?, ?, 'success')
    ");
    $stmt->bind_param('iisd', $booking_id, $user_id, $txn_id, $booking['final_amount']);
    $stmt->execute();
    $stmt->close();

    // Update booking
    $stmt = $conn->prepare("
        UPDATE bookings SET payment_status = 'paid', booking_status = 'confirmed' WHERE id = ?
    ");
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // Send confirmation email
    $details = "<p>Amount: ₹" . number_format($booking['final_amount'], 2) . "</p>
                <p>Payment via: Stripe</p>
                <p>Transaction ID: $txn_id</p>";
    $body = email_booking_confirm_template(
        $_SESSION['full_name'],
        $booking['booking_ref'],
        $details
    );
    send_email(
        $_SESSION['email'],
        $_SESSION['full_name'],
        'Booking Confirmed - ' . $booking['booking_ref'],
        $body
    );

    set_flash('success', 'Payment successful! Booking confirmed.');
    // Apply 2% cashback on bookings > ₹500
    require_once __DIR__ . '/../modules/wallet/cashback.php';
    $cashback = apply_cashback($conn, $user_id, $booking_id, $booking['final_amount']);

    $msg = 'Payment successful! Booking confirmed.';
    if ($cashback > 0) {
        $msg .= " ₹" . number_format($cashback, 2) . " cashback added to your wallet!";
    }
    set_flash('success', $msg);
    redirect("modules/hotels/invoice.php?booking_id=$booking_id");
} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Payment failed. Please try again.');
    redirect("payments/checkout.php?booking_id=$booking_id&gateway=stripe");
}
