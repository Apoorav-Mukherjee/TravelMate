<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/wallet/index.php');
}

verify_csrf();

if (empty($_SESSION['pending_topup'])) {
    set_flash('error', 'Session expired. Please try again.');
    redirect('modules/wallet/index.php');
}

$user_id   = $_SESSION['user_id'];
$topup     = $_SESSION['pending_topup'];
$amount    = (float)$topup['amount'];
$gateway   = sanitize($_POST['gateway']          ?? 'stripe');
$txn_id    = sanitize($_POST['simulated_txn_id'] ?? '');

// Validate amount again
if ($amount < 10 || $amount > 50000) {
    set_flash('error', 'Invalid amount.');
    redirect('modules/wallet/index.php');
}

$conn->begin_transaction();
try {
    // Get current balance
    $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc()['wallet_balance'];
    $stmt->close();

    $new_balance = $current + $amount;

    // Check for cashback (5% on first top-up)
    $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt FROM wallet_transactions
        WHERE user_id = ? AND type = 'credit'
          AND description LIKE '%Top-up%'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $prev_topups = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    $cashback = 0;
    if ($prev_topups === 0) {
        // First top-up — 5% cashback
        $cashback    = round($amount * 0.05, 2);
        $new_balance += $cashback;
    }

    // Update wallet balance
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->bind_param('di', $new_balance, $user_id);
    $stmt->execute();
    $stmt->close();

    // Log top-up transaction
    $desc = "Wallet Top-up via " . ucfirst($gateway);
    $stmt = $conn->prepare("
        INSERT INTO wallet_transactions
            (user_id, type, amount, balance_after, description, ref_type)
        VALUES (?, 'credit', ?, ?, ?, 'topup')
    ");
    $balance_after_topup = $current + $amount;
    $stmt->bind_param('idds', $user_id, $amount, $balance_after_topup, $desc);
    $stmt->execute();
    $stmt->close();

    // Log cashback if applicable
    if ($cashback > 0) {
        $cb_desc = "Welcome cashback (5% on first top-up)";
        $stmt = $conn->prepare("
            INSERT INTO wallet_transactions
                (user_id, type, amount, balance_after, description, ref_type)
            VALUES (?, 'credit', ?, ?, ?, 'cashback')
        ");
        $stmt->bind_param('idds', $user_id, $cashback, $new_balance, $cb_desc);
        $stmt->execute();
        $stmt->close();
    }

    // Create a payment record for the top-up
    $stmt = $conn->prepare("
        INSERT INTO payments
            (booking_id, user_id, gateway, gateway_txn_id, amount, status, payment_data)
        VALUES (0, ?, ?, ?, ?, 'success', ?)
    ");
    $pay_data = json_encode([
        'type'       => 'wallet_topup',
        'amount'     => $amount,
        'cashback'   => $cashback,
        'new_balance'=> $new_balance
    ]);
    $stmt->bind_param('issds', $user_id, $gateway, $txn_id, $amount, $pay_data);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    unset($_SESSION['pending_topup']);

    // Send email confirmation
    $body = "
        <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto'>
            <h2 style='background:#198754;color:#fff;padding:15px;border-radius:8px 8px 0 0;margin:0'>
                Wallet Topped Up!
            </h2>
            <div style='padding:25px;border:1px solid #eee'>
                <p>Hi <strong>{$_SESSION['full_name']}</strong>,</p>
                <p>₹" . number_format($amount, 2) . " has been added to your TravelMate wallet.</p>
                " . ($cashback > 0 ? "<p style='color:#198754'><strong>🎉 Bonus!</strong> You received ₹" . number_format($cashback, 2) . " cashback on your first top-up!</p>" : "") . "
                <p><strong>New Balance:</strong> ₹" . number_format($new_balance, 2) . "</p>
                <p><strong>Transaction ID:</strong> $txn_id</p>
            </div>
        </div>
    ";
    // send_email(
    //     $_SESSION['email'],
    //     $_SESSION['full_name'],
    //     'Wallet Top-up Successful',
    //     $body
    // );

    $msg = "₹" . number_format($amount, 2) . " added to your wallet!";
    if ($cashback > 0) {
        $msg .= " Plus ₹" . number_format($cashback, 2) . " cashback!";
    }
    set_flash('success', $msg);
    redirect('modules/wallet/index.php');

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Top-up failed: ' . $e->getMessage());
    redirect('modules/wallet/index.php');
}
?>