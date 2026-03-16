<?php
require_once __DIR__ . '/../includes/header.php';

$token = sanitize($_GET['token'] ?? '');

if (empty($token)) {
    set_flash('error', 'Invalid verification link.');
    redirect('auth/login.php');
}

$stmt = $conn->prepare(
    "SELECT id, email_verified FROM users WHERE verify_token = ? AND deleted_at IS NULL"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    set_flash('error', 'Invalid or expired verification link.');
    redirect('auth/login.php');
}

if ($user['email_verified']) {
    set_flash('info', 'Your email is already verified. Please login.');
    redirect('auth/login.php');
}

$stmt = $conn->prepare(
    "UPDATE users SET email_verified = 1, verify_token = NULL, status = 'active' WHERE id = ?"
);
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$stmt->close();

set_flash('success', 'Email verified successfully! You can now login.');
redirect('auth/login.php');
?>