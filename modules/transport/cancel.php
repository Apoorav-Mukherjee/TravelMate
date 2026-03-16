<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

if (!$booking_id) redirect('dashboards/traveler/index.php');

// Fetch booking
$stmt = $conn->prepare("
    SELECT b.* FROM bookings b
    WHERE b.id = ? AND b.user_id = ?
      AND b.booking_type = 'transport'
      AND b.booking_status = 'confirmed'
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'Booking not found or cannot be cancelled.');
    redirect('dashboards/traveler/index.php');
}

// Fetch seat meta to get departure time
$stmt = $conn->prepare("
    SELECT meta FROM booking_items WHERE booking_id = ? AND item_type = 'seat' LIMIT 1
");
$stmt->bind_param('i', $booking_id);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();

$meta           = json_decode($item['meta'] ?? '{}', true) ?? [];
$departure_dt   = $meta['journey_date'] . ' ' . $meta['departure'];
$hours_to_dep   = (strtotime($departure_dt) - time()) / 3600;

// Cancellation window: 2 hours before departure
if ($hours_to_dep < 2) {
    set_flash('error', 'Cancellation window has closed (2 hours before departure).');
    redirect("modules/transport/ticket.php?booking_id=$booking_id");
}

// Calculate refund (full refund if > 24hrs, 50% if 2-24hrs)
$refund_pct    = $hours_to_dep >= 24 ? 100 : 50;
$refund_amount = round($booking['final_amount'] * $refund_pct / 100, 2);

$conn->begin_transaction();
try {
    // Cancel booking
    $now = date('Y-m-d H:i:s');
    $reason = "Cancelled by user. Refund: {$refund_pct}%";
    $stmt = $conn->prepare("
        UPDATE bookings
        SET booking_status = 'cancelled',
            payment_status = 'refunded',
            cancellation_reason = ?,
            cancelled_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param('ssi', $reason, $now, $booking_id);
    $stmt->execute();
    $stmt->close();

    // Free up seats
    $stmt = $conn->prepare("
        SELECT item_id FROM booking_items WHERE booking_id = ? AND item_type = 'seat'
    ");
    $stmt->bind_param('i', $booking_id);
    $stmt->execute();
    $seat_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($seat_rows as $sr) {
        $conn->query("UPDATE transport_seats SET is_booked = 0 WHERE id = " . (int)$sr['item_id']);
    }

    // Update route available seats
    $seat_count = count($seat_rows);
    $conn->query("
        UPDATE transport_routes
        SET available_seats = available_seats + $seat_count
        WHERE id = " . (int)($meta['route_id'] ?? 0)
    );

    // Refund to wallet
    if ($refund_amount > 0) {
        // Get current balance
        $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $current_wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
        $stmt->close();

        $new_balance = $current_wallet + $refund_amount;

        $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
        $stmt->bind_param('di', $new_balance, $user_id);
        $stmt->execute();
        $stmt->close();

        $desc = "Refund for cancelled booking {$booking['booking_ref']} ({$refund_pct}%)";
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions
                (user_id, type, amount, balance_after, description, ref_id, ref_type)
            VALUES (?, 'credit', ?, ?, ?, ?, 'booking')
        ");
        $stmt->bind_param('iddsi', $user_id, $refund_amount, $new_balance, $desc, $booking_id);
        $stmt->execute();
        $stmt->close();

        // Update payment record
        $stmt = $conn->prepare("
            UPDATE payments
            SET status = 'refunded', refund_amount = ?, refunded_at = NOW()
            WHERE booking_id = ?
        ");
        $stmt->bind_param('di', $refund_amount, $booking_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();

    // Send cancellation email
    $body = "
        <p>Hi {$_SESSION['full_name']},</p>
        <p>Your booking <strong>{$booking['booking_ref']}</strong> has been cancelled.</p>
        <p>Refund: <strong>₹" . number_format($refund_amount, 2) . "</strong>
           ({$refund_pct}%) has been credited to your TravelMate wallet.</p>
        <p>Thank you for using TravelMate.</p>
    ";
    send_email($_SESSION['email'], $_SESSION['full_name'],
               'Booking Cancelled - ' . $booking['booking_ref'], $body);

    set_flash('success',
        "Booking cancelled. ₹" . number_format($refund_amount, 2) .
        " ({$refund_pct}% refund) has been added to your wallet."
    );
    redirect('dashboards/traveler/index.php');

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Cancellation failed: ' . $e->getMessage());
    redirect("modules/transport/ticket.php?booking_id=$booking_id");
}
?>