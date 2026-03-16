<?php
/**
 * modules/bookings/delete.php
 * Soft-hides a cancelled/completed booking from the traveler's list.
 * Uses the bookings.notes field to flag deletion (no extra column needed).
 * Only the booking owner can delete, and only cancelled/completed bookings.
 */
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('modules/bookings/my_bookings.php');
}

verify_csrf();

$user_id    = $_SESSION['user_id'];
$booking_id = (int)($_POST['booking_id'] ?? 0);

if (!$booking_id) {
    set_flash('error', 'Invalid booking.');
    redirect('modules/bookings/my_bookings.php');
}

$esc_id   = $conn->real_escape_string($booking_id);
$esc_user = $conn->real_escape_string($user_id);

// Fetch and validate ownership + status
$r = $conn->query("
    SELECT id, booking_status FROM bookings
    WHERE id = $esc_id AND user_id = $esc_user
    LIMIT 1
");

if (!$r || $r->num_rows === 0) {
    set_flash('error', 'Booking not found.');
    redirect('modules/bookings/my_bookings.php');
}

$booking = $r->fetch_assoc();

if (!in_array($booking['booking_status'], ['cancelled', 'completed'])) {
    set_flash('error', 'Only cancelled or completed bookings can be removed.');
    redirect('modules/bookings/my_bookings.php');
}

// Soft-delete: mark with a special prefix in notes so we can filter it out.
// We add __deleted__ flag; my_bookings.php WHERE clause excludes these.
$upd = $conn->query("
    UPDATE bookings
    SET notes = CONCAT(IFNULL(notes,''), ' [__deleted__]')
    WHERE id = $esc_id AND user_id = $esc_user
");

if ($upd) {
    set_flash('success', 'Booking removed from your list.');
} else {
    set_flash('error', 'Could not remove booking. Please try again.');
}

redirect('modules/bookings/my_bookings.php');