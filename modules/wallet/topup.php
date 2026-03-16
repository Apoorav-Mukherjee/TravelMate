<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/wallet/index.php');
}

verify_csrf();

$user_id = $_SESSION['user_id'];
$amount  = (float)($_POST['amount'] ?? 0);
$gateway = sanitize($_POST['gateway'] ?? 'stripe');

// Validate
if ($amount < 10 || $amount > 50000) {
    set_flash('error', 'Amount must be between ₹10 and ₹50,000.');
    redirect('modules/wallet/index.php');
}

if (!in_array($gateway, ['stripe', 'razorpay'])) {
    set_flash('error', 'Invalid payment method.');
    redirect('modules/wallet/index.php');
}

// Store pending topup in session
$_SESSION['pending_topup'] = [
    'amount'  => $amount,
    'gateway' => $gateway,
];

// Redirect to payment page
redirect("modules/wallet/topup_payment.php");
?>