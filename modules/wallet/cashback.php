<?php
// Called internally to apply cashback to a user's wallet
// Usage: apply_cashback($conn, $user_id, $booking_id, $booking_amount)

function apply_cashback($conn, $user_id, $booking_id, $booking_amount) {
    // 2% cashback on bookings over ₹500
    if ($booking_amount < 500) return 0;

    $cashback = round($booking_amount * 0.02, 2);

    // Get current balance
    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc()['wallet_balance'];
    $stmt->close();

    $new_balance = $current + $cashback;

    // Credit wallet
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->bind_param('di', $new_balance, $user_id);
    $stmt->execute();
    $stmt->close();

    // Log
    $desc = "2% cashback on booking #$booking_id";
    $stmt = $conn->prepare("
        INSERT INTO wallet_transactions
            (user_id, type, amount, balance_after, description, ref_id, ref_type)
        VALUES (?, 'credit', ?, ?, ?, ?, 'cashback')
    ");
    $stmt->bind_param('iddsi', $user_id, $cashback, $new_balance, $desc, $booking_id);
    $stmt->execute();
    $stmt->close();

    return $cashback;
}
?>