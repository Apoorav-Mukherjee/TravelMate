<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');
require_once __DIR__ . '/../../includes/tcpdf/tcpdf.php';

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, u.full_name, u.email, u.phone
    FROM bookings b JOIN users u ON b.user_id = u.id
    WHERE b.id = ? AND b.user_id = ?
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) die('Ticket not found.');

$stmt = $conn->prepare("
    SELECT * FROM booking_items WHERE booking_id = ? AND item_type = 'seat'
");
$stmt->bind_param('i', $booking_id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$first_meta = json_decode($items[0]['meta'], true) ?? [];

// Generate QR data
$qr_data = 'TRAVELMATE:' . $booking['booking_ref'] . ':' . $booking_id;
$qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode($qr_data);

// Create PDF
$pdf = new TCPDF('L', 'mm', [210, 100], true, 'UTF-8', false);
$pdf->SetCreator('TravelMate');
$pdf->SetTitle('Ticket - ' . $booking['booking_ref']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->AddPage();

// Background
$pdf->SetFillColor(26, 31, 58);
$pdf->Rect(0, 0, 100, 100, 'F');

// Left side — Branding
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetXY(8, 12);
$pdf->Cell(85, 8, 'TravelMate', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(8, 22);
$pdf->Cell(85, 5, 'e-Ticket / Boarding Pass', 0, 1, 'L');

// Route
$pdf->SetFont('helvetica', 'B', 22);
$pdf->SetXY(8, 35);
$pdf->Cell(40, 10,
    date('H:i', strtotime($first_meta['departure'] ?? '')), 0, 0, 'L');
$pdf->SetFont('helvetica', '', 10);
$pdf->SetXY(48, 38);
$pdf->Cell(10, 5, '---->', 0, 0, 'C');
$pdf->SetFont('helvetica', 'B', 22);
$pdf->SetXY(58, 35);
$pdf->Cell(35, 10,
    date('H:i', strtotime($first_meta['arrival'] ?? '')), 0, 0, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(8, 48);
$pdf->Cell(40, 5, $first_meta['source'] ?? '', 0, 0, 'L');
$pdf->SetXY(58, 48);
$pdf->Cell(35, 5, $first_meta['destination'] ?? '', 0, 0, 'L');

// Date & type
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(8, 58);
$pdf->Cell(85, 5,
    date('d M Y', strtotime($first_meta['journey_date'] ?? '')) .
    ' | ' . ucfirst($first_meta['transport_type'] ?? ''), 0, 1, 'L');

// Passenger
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(8, 68);
$pdf->Cell(85, 5, 'PASSENGER', 0, 1, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(8, 74);
$pdf->Cell(85, 5, $booking['full_name'], 0, 1, 'L');

// Dashed separator
$pdf->SetDrawColor(255, 255, 255);
$pdf->SetDash(2, 2);
$pdf->Line(100, 0, 100, 100);
$pdf->SetDash();

// Right side — Ticket Info
$pdf->SetFillColor(255, 255, 255);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetXY(105, 10);
$pdf->Cell(95, 7, 'BOOKING REFERENCE', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetXY(105, 18);
$pdf->Cell(95, 8, $booking['booking_ref'], 0, 1, 'C');

// Seats
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(105, 30);
$pdf->Cell(95, 5, 'SEATS', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 9);
$y = 36;
foreach ($items as $item) {
    $m = json_decode($item['meta'], true) ?? [];
    $pdf->SetXY(105, $y);
    $pdf->Cell(45, 5,
        'Seat ' . ($m['seat_number'] ?? '-') .
        ' (' . ucfirst(str_replace('_', ' ', $m['seat_class'] ?? '')) . ')',
        0, 0, 'C');
    $pdf->Cell(45, 5,
        'Rs.' . number_format($item['unit_price'], 2),
        0, 0, 'C');
    $y += 6;
}

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(105, $y + 2);
$pdf->Cell(95, 6,
    'TOTAL: Rs.' . number_format($booking['final_amount'], 2),
    0, 1, 'C');

// QR Code (fetch from API and embed)
$qr_img = @file_get_contents($qr_url);
if ($qr_img) {
    $tmp_qr = sys_get_temp_dir() . '/qr_' . $booking_id . '.png';
    file_put_contents($tmp_qr, $qr_img);
    $pdf->Image($tmp_qr, 148, 65, 25, 25);
    @unlink($tmp_qr);
}

$pdf->SetFont('helvetica', '', 7);
$pdf->SetXY(105, 92);
$pdf->Cell(95, 4, 'Scan QR to verify | Valid ID required', 0, 1, 'C');

$pdf->Output('Ticket_' . $booking['booking_ref'] . '.pdf', 'D');
?>