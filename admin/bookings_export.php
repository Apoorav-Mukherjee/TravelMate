<?php
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$stmt = $conn->prepare("
    SELECT b.booking_ref, b.booking_type, b.total_amount, b.final_amount,
           b.payment_status, b.booking_status, b.check_in, b.check_out,
           b.created_at, u.full_name, u.email,
           p.gateway, p.gateway_txn_id
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    LEFT JOIN payments p ON p.booking_id = b.id
    ORDER BY b.created_at DESC
");
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="bookings_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Booking Ref','Type','Total','Final Amount','Payment Status',
    'Booking Status','Check-in','Check-out','User','Email',
    'Gateway','Transaction ID','Created At'
]);

foreach ($bookings as $b) {
    fputcsv($out, [
        $b['booking_ref'], $b['booking_type'],
        $b['total_amount'], $b['final_amount'],
        $b['payment_status'], $b['booking_status'],
        $b['check_in'], $b['check_out'],
        $b['full_name'], $b['email'],
        $b['gateway'] ?? '', $b['gateway_txn_id'] ?? '',
        $b['created_at']
    ]);
}
    
fclose($out);
exit();
?>