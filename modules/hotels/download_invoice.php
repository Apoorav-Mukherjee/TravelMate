<?php
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');
require_once __DIR__ . '/../../includes/tcpdf/tcpdf.php';

$booking_id = (int)($_GET['booking_id'] ?? 0);
$user_id    = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT b.*, bi.unit_price, bi.subtotal, bi.meta,
           r.room_type, h.name as hotel_name, h.address, h.city,
           u.full_name, u.email, u.phone
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN rooms r ON bi.item_id = r.id
    JOIN hotels h ON r.hotel_id = h.id
    JOIN users u  ON b.user_id = u.id
    WHERE b.id = ? AND b.user_id = ?
    LIMIT 1
");
$stmt->bind_param('ii', $booking_id, $user_id);
$stmt->execute();
$inv = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$inv) die('Invoice not found.');

$meta    = json_decode($inv['meta'], true) ?? [];
$nights  = $meta['nights'] ?? 1;
$tax     = $meta['tax']    ?? 0;

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('TravelMate');
$pdf->SetAuthor('TravelMate');
$pdf->SetTitle('Invoice - ' . $inv['booking_ref']);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(15, 15, 15);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetTextColor(13, 110, 253);
$pdf->Cell(0, 10, 'TravelMate', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->SetTextColor(100, 100, 100);
$pdf->Cell(0, 6, 'Your Trusted Travel Partner', 0, 1, 'L');
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetTextColor(0, 0, 0);
$pdf->Cell(0, 10, 'INVOICE', 0, 1, 'R');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, 'Ref: ' . $inv['booking_ref'], 0, 1, 'R');
$pdf->Cell(0, 6, 'Date: ' . date('d M Y', strtotime($inv['created_at'])), 0, 1, 'R');
$pdf->Ln(5);

// Bill To
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(90, 6, 'Bill To', 0, 0, 'L');
$pdf->Cell(90, 6, 'Property', 0, 1, 'L');

$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(90, 6, $inv['full_name'], 0, 0, 'L');
$pdf->Cell(90, 6, $inv['hotel_name'], 0, 1, 'L');
$pdf->Cell(90, 6, $inv['email'], 0, 0, 'L');
$pdf->Cell(90, 6, $inv['city'], 0, 1, 'L');
$pdf->Ln(8);

// Table header
$pdf->SetFillColor(30, 30, 30);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(70, 8, 'Description',  1, 0, 'C', true);
$pdf->Cell(25, 8, 'Check-in',     1, 0, 'C', true);
$pdf->Cell(25, 8, 'Check-out',    1, 0, 'C', true);
$pdf->Cell(15, 8, 'Nights',       1, 0, 'C', true);
$pdf->Cell(25, 8, 'Rate',         1, 0, 'C', true);
$pdf->Cell(20, 8, 'Amount',       1, 1, 'C', true);

// Table row
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(70, 7, $inv['room_type'],                                         1, 0, 'L');
$pdf->Cell(25, 7, date('d M Y', strtotime($inv['check_in'])),                1, 0, 'C');
$pdf->Cell(25, 7, date('d M Y', strtotime($inv['check_out'])),               1, 0, 'C');
$pdf->Cell(15, 7, $nights,                                                   1, 0, 'C');
$pdf->Cell(25, 7, 'Rs.' . number_format($inv['unit_price'], 2),              1, 0, 'R');
$pdf->Cell(20, 7, 'Rs.' . number_format($inv['subtotal'] - $tax, 2),        1, 1, 'R');

// Totals
$pdf->Cell(160, 7, 'GST (12%)',    1, 0, 'R');
$pdf->Cell(20,  7, 'Rs.' . number_format($tax, 2), 1, 1, 'R');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(13, 110, 253);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(160, 8, 'TOTAL', 1, 0, 'R', true);
$pdf->Cell(20,  8, 'Rs.' . number_format($inv['final_amount'], 2), 1, 1, 'R', true);

$pdf->Ln(10);
$pdf->SetTextColor(100, 100, 100);
$pdf->SetFont('helvetica', 'I', 9);
$pdf->Cell(0, 6, 'Thank you for choosing TravelMate! For support: support@travelmate.com', 0, 1, 'C');

$pdf->Output('Invoice_' . $inv['booking_ref'] . '.pdf', 'D');
?>