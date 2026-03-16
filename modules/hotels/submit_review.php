<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('modules/hotels/search.php');

verify_csrf();

$hotel_id    = (int)($_POST['hotel_id'] ?? 0);
$rating      = (int)($_POST['rating'] ?? 0);
$review_text = sanitize($_POST['review_text'] ?? '');
$user_id     = $_SESSION['user_id'];

if (!$hotel_id || $rating < 1 || $rating > 5 || empty($review_text)) {
    set_flash('error', 'Invalid review data.');
    redirect("modules/hotels/detail.php?id=$hotel_id");
}

// Check if user has a completed booking for this hotel
$stmt = $conn->prepare("
    SELECT b.id FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN rooms r ON bi.item_id = r.id
    WHERE b.user_id = ? AND r.hotel_id = ?
      AND b.booking_status = 'completed'
      AND bi.item_type = 'room'
    LIMIT 1
");
$stmt->bind_param('ii', $user_id, $hotel_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    set_flash('error', 'You can only review hotels you have stayed in.');
    redirect("modules/hotels/detail.php?id=$hotel_id");
}

// Insert or update review
$stmt = $conn->prepare("
    INSERT INTO reviews (user_id, entity_type, entity_id, booking_id, rating, review_text, is_approved)
    VALUES (?, 'hotel', ?, ?, ?, ?, 0)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text)
");
$stmt->bind_param('iiiii s', $user_id, $hotel_id, $booking['id'], $rating, $review_text);

// Fix
$stmt->close();
$stmt = $conn->prepare("
    INSERT INTO reviews (user_id, entity_type, entity_id, booking_id, rating, review_text)
    VALUES (?, 'hotel', ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text)
");
$stmt->bind_param('iiiis', $user_id, $hotel_id, $booking['id'], $rating, $review_text);
$stmt->execute();
$stmt->close();

set_flash('success', 'Review submitted! It will appear after admin approval.');
redirect("modules/hotels/detail.php?id=$hotel_id");
?>