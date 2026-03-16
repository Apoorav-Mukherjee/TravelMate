<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/packages/search.php');
}
verify_csrf();

$package_id  = (int)($_POST['package_id']  ?? 0);
$rating      = (int)($_POST['rating']      ?? 0);
$review_text = sanitize($_POST['review_text'] ?? '');
$user_id     = $_SESSION['user_id'];

if (!$package_id || $rating < 1 || $rating > 5 || empty($review_text)) {
    set_flash('error', 'Invalid review data.');
    redirect("modules/packages/detail.php?slug=$package_id");
}

// Verify completed booking
$stmt = $conn->prepare("
    SELECT b.id FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    WHERE b.user_id = ? AND bi.item_id = ?
      AND bi.item_type = 'package'
      AND b.booking_status = 'completed'
    LIMIT 1
");
$stmt->bind_param('ii', $user_id, $package_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'You can only review packages you have completed.');
    redirect("modules/packages/detail.php?slug=$package_id");
}

$stmt = $conn->prepare("
    INSERT INTO reviews (user_id, entity_type, entity_id, booking_id, rating, review_text)
    VALUES (?, 'package', ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE rating=VALUES(rating), review_text=VALUES(review_text)
");
$stmt->bind_param('iiiis', $user_id, $package_id, $booking['id'], $rating, $review_text);
$stmt->execute();
$stmt->close();

set_flash('success', 'Review submitted! It will appear after approval.');
redirect("modules/packages/detail.php?slug=$package_id");
?>