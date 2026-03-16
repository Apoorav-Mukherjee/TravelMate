<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/wallet/index.php');
}

verify_csrf();

$user_id = $_SESSION['user_id'];
$email   = sanitize($_POST['email']  ?? '');
$amount  = (float)($_POST['amount']  ?? 0);
$note    = sanitize($_POST['note']   ?? '');

// Validate
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Invalid recipient email.');
    redirect('modules/wallet/index.php');
}

if ($amount < 1) {
    set_flash('error', 'Minimum transfer amount is ₹1.');
    redirect('modules/wallet/index.php');
}

// Cannot transfer to self
if (strtolower($email) === strtolower($_SESSION['email'])) {
    set_flash('error', 'You cannot transfer to yourself.');
    redirect('modules/wallet/index.php');
}

// Find recipient
$stmt = $conn->prepare("
    SELECT id, full_name, email FROM users
    WHERE email = ? AND deleted_at IS NULL AND status = 'active'
");
$stmt->bind_param('s', $email);
$stmt->execute();
$recipient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$recipient) {
    set_flash('error', 'Recipient not found or inactive.');
    redirect('modules/wallet/index.php');
}

// Check sender balance
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$sender_balance = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();

if ($sender_balance < $amount) {
    set_flash('error', 'Insufficient wallet balance.');
    redirect('modules/wallet/index.php');
}

$conn->begin_transaction();
try {
    // Deduct from sender
    $sender_new = $sender_balance - $amount;
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->bind_param('di', $sender_new, $user_id);
    $stmt->execute();
    $stmt->close();

    // Add to recipient
    $stmt = $conn->prepare("
        SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE
    ");
    $stmt->bind_param('i', $recipient['id']);
    $stmt->execute();
    $rec_balance = $stmt->get_result()->fetch_assoc()['wallet_balance'];
    $stmt->close();

    $rec_new = $rec_balance + $amount;
    $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
    $stmt->bind_param('di', $rec_new, $recipient['id']);
    $stmt->execute();
    $stmt->close();

    // Log sender debit
    $sender_desc = "Transfer to {$recipient['full_name']}"
                   . ($note ? " — $note" : '');
    $stmt = $conn->prepare("
        INSERT INTO wallet_transactions
            (user_id, type, amount, balance_after, description, ref_type)
        VALUES (?, 'debit', ?, ?, ?, 'transfer')
    ");
    $stmt->bind_param('idds', $user_id, $amount, $sender_new, $sender_desc);
    $stmt->execute();
    $stmt->close();

    // Log recipient credit
    $rec_desc = "Transfer from {$_SESSION['full_name']}"
                . ($note ? " — $note" : '');
    $stmt = $conn->prepare("
        INSERT INTO wallet_transactions
            (user_id, type, amount, balance_after, description, ref_type)
        VALUES (?, 'credit', ?, ?, ?, 'transfer')
    ");
    $stmt->bind_param('idds', $recipient['id'], $amount, $rec_new, $rec_desc);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // Email both parties
    // Sender
    send_email(
        $_SESSION['email'],
        $_SESSION['full_name'],
        'Wallet Transfer Sent',
        "<p>You sent ₹" . number_format($amount, 2) . " to {$recipient['full_name']}.</p>
         <p>Remaining balance: ₹" . number_format($sender_new, 2) . "</p>"
    );

    // Recipient
    send_email(
        $recipient['email'],
        $recipient['full_name'],
        'Wallet Amount Received',
        "<p>You received ₹" . number_format($amount, 2) . " from {$_SESSION['full_name']}.</p>
         " . ($note ? "<p>Note: $note</p>" : '') . "
         <p>New balance: ₹" . number_format($rec_new, 2) . "</p>"
    );

    set_flash('success',
        "₹" . number_format($amount, 2) . " transferred to {$recipient['full_name']} successfully!"
    );

} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Transfer failed: ' . $e->getMessage());
}

redirect('modules/wallet/index.php');
?>