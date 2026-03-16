<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['unread_messages' => 0]);
    exit();
}

$user_id = $_SESSION['user_id'];

// Unread messages count
$stmt = $conn->prepare("
    SELECT COUNT(*) as cnt FROM messages
    WHERE receiver_id = ? AND is_read = 0
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$unread_msgs = $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

// Pending bookings count (for providers/staff)
$pending_bookings = 0;
$role = get_role();

if ($role === 'hotel_staff') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM bookings b
        JOIN booking_items bi ON bi.booking_id = b.id
        JOIN rooms r ON bi.item_id = r.id
        JOIN hotels h ON r.hotel_id = h.id
        WHERE h.owner_id = ? AND b.booking_status = 'pending'
          AND bi.item_type = 'room'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $pending_bookings = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

} elseif ($role === 'guide') {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as cnt
        FROM bookings b
        JOIN booking_items bi ON bi.booking_id = b.id
        JOIN guides g ON bi.item_id = g.id
        WHERE g.user_id = ? AND b.booking_status = 'pending'
          AND bi.item_type = 'guide_session'
    ");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $pending_bookings = $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
}

echo json_encode([
    'unread_messages'  => (int)$unread_msgs,
    'pending_bookings' => (int)$pending_bookings,
]);
?>