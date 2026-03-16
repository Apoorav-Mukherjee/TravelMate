<?php
$page_title = 'Booking Details';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$user_id    = $_SESSION['user_id'];
$booking_id = (int)($_GET['id'] ?? 0);

if (!$booking_id) {
    set_flash('error', 'Invalid booking.');
    redirect('modules/bookings/my_bookings.php');
}

// ── Fetch booking (must belong to this user) ──────────────────────────────
$esc_id      = $conn->real_escape_string($booking_id);
$esc_user    = $conn->real_escape_string($user_id);

$r = $conn->query("
    SELECT b.*, u.full_name, u.email, u.phone
    FROM   bookings b
    JOIN   users    u ON u.id = b.user_id
    WHERE  b.id = $esc_id AND b.user_id = $esc_user
    LIMIT  1
");
if (!$r || $r->num_rows === 0) {
    set_flash('error', 'Booking not found.');
    redirect('modules/bookings/my_bookings.php');
}
$booking = $r->fetch_assoc();

// ── Fetch booking items ───────────────────────────────────────────────────
$ri = $conn->query("
    SELECT * FROM booking_items WHERE booking_id = $esc_id ORDER BY id ASC
");
$items = ($ri) ? $ri->fetch_all(MYSQLI_ASSOC) : [];

// ── Fetch payment info ────────────────────────────────────────────────────
$rp = $conn->query("
    SELECT * FROM payments WHERE booking_id = $esc_id ORDER BY id DESC LIMIT 1
");
$payment = ($rp && $rp->num_rows > 0) ? $rp->fetch_assoc() : null;

// ── Fetch package booking info (if package type) ──────────────────────────
$package_booking = null;
$package         = null;
if ($booking['booking_type'] === 'package') {
    $rpb = $conn->query("
        SELECT pb.*, tp.title, tp.city, tp.duration_days,
               tp.cover_image, tp.includes_hotel,
               tp.includes_guide, tp.includes_transport
        FROM   package_bookings pb
        JOIN   tour_packages    tp ON tp.id = pb.package_id
        WHERE  pb.booking_id = $esc_id
        LIMIT  1
    ");
    if ($rpb && $rpb->num_rows > 0) {
        $package_booking = $rpb->fetch_assoc();
        $package         = $package_booking; // same row has all we need
    }
}

// ── Fetch hotel item detail ───────────────────────────────────────────────
$hotel_detail = null;
$room_detail  = null;
if ($booking['booking_type'] === 'hotel') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'room') {
            $rid = (int)$item['item_id'];
            $rh  = $conn->query("
                SELECT r.*, h.name AS hotel_name, h.city, h.state,
                       h.address, h.star_rating, h.cover_image
                FROM   rooms  r
                JOIN   hotels h ON h.id = r.hotel_id
                WHERE  r.id = $rid LIMIT 1
            ");
            if ($rh && $rh->num_rows > 0) {
                $room_detail  = $rh->fetch_assoc();
                $hotel_detail = $room_detail; // alias
            }
        }
    }
}

// ── Fetch guide item detail ───────────────────────────────────────────────
$guide_detail = null;
if ($booking['booking_type'] === 'guide') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'guide') {
            $gid = (int)$item['item_id'];
            $rg  = $conn->query("
                SELECT g.*, u.full_name AS guide_name,
                       u.email AS guide_email, u.phone AS guide_phone,
                       u.profile_picture
                FROM   guides g
                JOIN   users  u ON u.id = g.user_id
                WHERE  g.id = $gid LIMIT 1
            ");
            if ($rg && $rg->num_rows > 0) {
                $guide_detail = $rg->fetch_assoc();
            }
        }
    }
}

// ── Fetch transport item detail ───────────────────────────────────────────
$route_detail    = null;
$transport_seats = [];
if ($booking['booking_type'] === 'transport') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'transport_route') {
            $trid = (int)$item['item_id'];
            $rt   = $conn->query("
                SELECT tr.*, tp.company_name, tp.transport_type AS provider_type
                FROM   transport_routes    tr
                JOIN   transport_providers tp ON tp.id = tr.provider_id
                WHERE  tr.id = $trid LIMIT 1
            ");
            if ($rt && $rt->num_rows > 0) {
                $route_detail = $rt->fetch_assoc();
            }
        }
        if ($item['item_type'] === 'transport_seat') {
            $sid = (int)$item['item_id'];
            $rs  = $conn->query("
                SELECT * FROM transport_seats WHERE id = $sid LIMIT 1
            ");
            if ($rs && $rs->num_rows > 0) {
                $transport_seats[] = $rs->fetch_assoc();
            }
        }
    }
}

// ── Badge helpers ─────────────────────────────────────────────────────────
function status_badge(string $status): string {
    $map = [
        'pending'   => 'bg-warning text-dark',
        'confirmed' => 'bg-success',
        'cancelled' => 'bg-danger',
        'completed' => 'bg-info',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return "<span class=\"badge $cls\">" . ucfirst(htmlspecialchars($status)) . "</span>";
}
function payment_badge(string $status): string {
    $map = [
        'paid'     => 'bg-success',
        'pending'  => 'bg-warning text-dark',
        'failed'   => 'bg-danger',
        'refunded' => 'bg-info',
    ];
    $cls = $map[$status] ?? 'bg-secondary';
    return "<span class=\"badge $cls\">" . ucfirst(htmlspecialchars($status)) . "</span>";
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>

    <div class="main-content w-100">

        <!-- ── Top bar ───────────────────────────────────────────────── -->
        <div class="topbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/bookings/my_bookings.php"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <h5 class="mb-0">Booking Details</h5>
            </div>
            <code class="text-muted"><?= htmlspecialchars($booking['booking_ref']) ?></code>
        </div>

        <?php if ($flash = get_flash()): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mx-3 mt-3" role="alert">
            <?= $flash['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="row g-4">

                <!-- ══ LEFT COLUMN ════════════════════════════════════════ -->
                <div class="col-lg-8">

                    <!-- ── Booking Summary Card ─────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-receipt-cutoff text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Booking Summary</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="text-muted small">Booking Reference</div>
                                    <code class="fs-6"><?= htmlspecialchars($booking['booking_ref']) ?></code>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Booking Type</div>
                                    <span class="badge bg-light text-dark border fs-6">
                                        <i class="bi bi-<?=
                                            $booking['booking_type'] === 'hotel'     ? 'building'        :
                                            ($booking['booking_type'] === 'guide'    ? 'person-badge'    :
                                            ($booking['booking_type'] === 'transport'? 'bus-front'       :
                                            ($booking['booking_type'] === 'package'  ? 'suitcase'        : 'tag')))
                                        ?>"></i>
                                        <?= ucfirst(htmlspecialchars($booking['booking_type'])) ?>
                                    </span>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Booking Status</div>
                                    <?= status_badge($booking['booking_status']) ?>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Payment Status</div>
                                    <?= payment_badge($booking['payment_status']) ?>
                                </div>
                                <?php if ($booking['check_in']): ?>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Check-in / Travel Date</div>
                                    <div class="fw-semibold">
                                        <?= date('d M Y', strtotime($booking['check_in'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($booking['check_out'] && $booking['check_out'] !== $booking['check_in']): ?>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Check-out Date</div>
                                    <div class="fw-semibold">
                                        <?= date('d M Y', strtotime($booking['check_out'])) ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Booked On</div>
                                    <div><?= date('d M Y, h:i A', strtotime($booking['created_at'])) ?></div>
                                </div>
                                <?php if ($booking['notes']): ?>
                                <div class="col-12">
                                    <div class="text-muted small">Notes</div>
                                    <div><?= nl2br(htmlspecialchars($booking['notes'])) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if ($booking['booking_status'] === 'cancelled'): ?>
                                <div class="col-12">
                                    <div class="alert alert-danger mb-0 py-2 small">
                                        <i class="bi bi-x-circle me-1"></i>
                                        <strong>Cancelled</strong>
                                        <?php if ($booking['cancellation_reason']): ?>
                                        — <?= htmlspecialchars($booking['cancellation_reason']) ?>
                                        <?php endif; ?>
                                        <?php if ($booking['cancelled_at']): ?>
                                        <span class="text-muted ms-2">
                                            (<?= date('d M Y', strtotime($booking['cancelled_at'])) ?>)
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════════════════════════════════════════ -->
                    <!-- ── Type-specific detail cards ───────────────────── -->
                    <!-- ══════════════════════════════════════════════════ -->

                    <?php if ($booking['booking_type'] === 'hotel' && $hotel_detail): ?>
                    <!-- HOTEL ──────────────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-building text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Hotel & Room Details</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($hotel_detail['cover_image']): ?>
                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($hotel_detail['cover_image']) ?>"
                                 class="img-fluid rounded mb-3 w-100"
                                 style="max-height:220px;object-fit:cover;"
                                 alt="Hotel">
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-sm-8">
                                    <div class="text-muted small">Hotel Name</div>
                                    <div class="fw-semibold fs-6">
                                        <?= htmlspecialchars($hotel_detail['hotel_name']) ?>
                                    </div>
                                    <div class="text-warning small">
                                        <?= str_repeat('★', (int)$hotel_detail['star_rating']) ?>
                                        <?= str_repeat('☆', 5 - (int)$hotel_detail['star_rating']) ?>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="text-muted small">Room Type</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($hotel_detail['room_type']) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Location</div>
                                    <div><?= htmlspecialchars($hotel_detail['city']) ?>, <?= htmlspecialchars($hotel_detail['state']) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Max Occupancy</div>
                                    <div><?= $hotel_detail['max_occupancy'] ?> person(s)</div>
                                </div>
                                <?php if ($hotel_detail['address']): ?>
                                <div class="col-12">
                                    <div class="text-muted small">Address</div>
                                    <div><?= htmlspecialchars($hotel_detail['address']) ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($booking['booking_type'] === 'guide' && $guide_detail): ?>
                    <!-- GUIDE ──────────────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-person-badge text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Guide Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <?php if ($guide_detail['profile_picture']): ?>
                                <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($guide_detail['profile_picture']) ?>"
                                     class="rounded-circle" width="72" height="72"
                                     style="object-fit:cover;" alt="Guide">
                                <?php else: ?>
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center"
                                     style="width:72px;height:72px;">
                                    <i class="bi bi-person-fill text-white fs-3"></i>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div class="fw-semibold fs-6">
                                        <?= htmlspecialchars($guide_detail['guide_name']) ?>
                                    </div>
                                    <?php if ($guide_detail['is_verified']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-patch-check-fill me-1"></i>Verified Guide
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="text-muted small">City</div>
                                    <div><?= htmlspecialchars($guide_detail['city'] ?? '—') ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Experience</div>
                                    <div><?= $guide_detail['experience_years'] ?> year(s)</div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Languages</div>
                                    <div><?= htmlspecialchars($guide_detail['languages'] ?? '—') ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Hourly / Daily Rate</div>
                                    <div>
                                        ₹<?= number_format($guide_detail['hourly_rate'], 2) ?> /hr &nbsp;|&nbsp;
                                        ₹<?= number_format($guide_detail['daily_rate'],  2) ?> /day
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Email</div>
                                    <div><?= htmlspecialchars($guide_detail['guide_email']) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Phone</div>
                                    <div><?= htmlspecialchars($guide_detail['guide_phone'] ?? '—') ?></div>
                                </div>
                                <?php if ($guide_detail['bio']): ?>
                                <div class="col-12">
                                    <div class="text-muted small">Bio</div>
                                    <div class="small"><?= nl2br(htmlspecialchars($guide_detail['bio'])) ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($booking['booking_type'] === 'transport' && $route_detail): ?>
                    <!-- TRANSPORT ──────────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-bus-front text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Transport Details</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="text-muted small">Operator</div>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($route_detail['company_name']) ?>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Transport Type</div>
                                    <div><?= ucfirst(htmlspecialchars($route_detail['transport_type'])) ?></div>
                                </div>
                                <div class="col-12">
                                    <!-- Route visual -->
                                    <div class="d-flex align-items-center gap-2 bg-light rounded p-3">
                                        <div class="text-center">
                                            <div class="fw-bold fs-6">
                                                <?= htmlspecialchars($route_detail['source']) ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?= date('h:i A', strtotime($route_detail['departure_time'])) ?>
                                            </div>
                                        </div>
                                        <div class="flex-grow-1 text-center text-muted">
                                            <div style="border-top:2px dashed #adb5bd;position:relative;margin:8px 0;">
                                                <i class="bi bi-arrow-right position-absolute top-50 start-50 translate-middle bg-light px-1" style="margin-top:-1px;"></i>
                                            </div>
                                            <div class="small">
                                                <?= date('d M Y', strtotime($route_detail['journey_date'])) ?>
                                            </div>
                                        </div>
                                        <div class="text-center">
                                            <div class="fw-bold fs-6">
                                                <?= htmlspecialchars($route_detail['destination']) ?>
                                            </div>
                                            <div class="text-muted small">
                                                <?= date('h:i A', strtotime($route_detail['arrival_time'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($transport_seats)): ?>
                                <div class="col-12">
                                    <div class="text-muted small mb-1">Seat(s)</div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($transport_seats as $seat): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
                                            <i class="bi bi-ticket-perforated me-1"></i>
                                            Seat <?= htmlspecialchars($seat['seat_number']) ?>
                                            <span class="ms-1 text-muted">(<?= ucfirst($seat['seat_class']) ?>)</span>
                                        </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($booking['booking_type'] === 'package' && $package): ?>
                    <!-- PACKAGE ────────────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-suitcase text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Package Details</h6>
                        </div>
                        <div class="card-body">
                            <?php if ($package['cover_image']): ?>
                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($package['cover_image']) ?>"
                                 class="img-fluid rounded mb-3 w-100"
                                 style="max-height:220px;object-fit:cover;"
                                 alt="Package">
                            <?php endif; ?>
                            <div class="row g-3">
                                <div class="col-sm-8">
                                    <div class="text-muted small">Package Name</div>
                                    <div class="fw-semibold fs-6">
                                        <?= htmlspecialchars($package['title']) ?>
                                    </div>
                                </div>
                                <div class="col-sm-4">
                                    <div class="text-muted small">Duration</div>
                                    <div><?= $package['duration_days'] ?> Day(s)</div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Destination</div>
                                    <div><?= htmlspecialchars($package['city']) ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Travel Date</div>
                                    <div><?= $package['travel_date'] ? date('d M Y', strtotime($package['travel_date'])) : '—' ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">No. of Persons</div>
                                    <div><?= (int)$package['persons'] ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="text-muted small">Inclusions</div>
                                    <div class="d-flex flex-wrap gap-1 mt-1">
                                        <?php if ($package['includes_hotel']): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                                            <i class="bi bi-building me-1"></i>Hotel
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($package['includes_guide']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                                            <i class="bi bi-person-badge me-1"></i>Guide
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($package['includes_transport']): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">
                                            <i class="bi bi-bus-front me-1"></i>Transport
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ── Booking Items Table ───────────────────────────── -->
                    <?php if (!empty($items)): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-list-ul text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Booking Items</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Item Type</th>
                                        <th>Qty</th>
                                        <th>Unit Price</th>
                                        <th class="text-end">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?= ucfirst(str_replace('_', ' ', htmlspecialchars($item['item_type']))) ?>
                                            </span>
                                        </td>
                                        <td><?= (int)$item['quantity'] ?></td>
                                        <td>₹<?= number_format($item['unit_price'], 2) ?></td>
                                        <td class="text-end fw-semibold">
                                            ₹<?= number_format($item['subtotal'], 2) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                </div><!-- /col-lg-8 -->

                <!-- ══ RIGHT COLUMN ═══════════════════════════════════════ -->
                <div class="col-lg-4">

                    <!-- ── Price Breakdown ──────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-currency-rupee text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Price Breakdown</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Subtotal</span>
                                <span>₹<?= number_format($booking['total_amount'], 2) ?></span>
                            </div>
                            <?php if ($booking['discount_amount'] > 0): ?>
                            <div class="d-flex justify-content-between mb-2 text-success">
                                <span><i class="bi bi-tag me-1"></i>Discount</span>
                                <span>− ₹<?= number_format($booking['discount_amount'], 2) ?></span>
                            </div>
                            <?php endif; ?>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between fw-bold fs-6">
                                <span>Total Paid</span>
                                <span class="text-primary">₹<?= number_format($booking['final_amount'], 2) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- ── Payment Info ─────────────────────────────────── -->
                    <?php if ($payment): ?>
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-credit-card text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Payment Info</h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-2 small">
                                <div class="col-6 text-muted">Gateway</div>
                                <div class="col-6 fw-semibold">
                                    <?= ucfirst(htmlspecialchars($payment['gateway'])) ?>
                                </div>
                                <div class="col-6 text-muted">Status</div>
                                <div class="col-6"><?= payment_badge($payment['status']) ?></div>
                                <?php if ($payment['gateway_txn_id']): ?>
                                <div class="col-6 text-muted">Txn ID</div>
                                <div class="col-6">
                                    <code class="small"><?= htmlspecialchars($payment['gateway_txn_id']) ?></code>
                                </div>
                                <?php endif; ?>
                                <?php if ($payment['refund_amount'] > 0): ?>
                                <div class="col-6 text-muted">Refunded</div>
                                <div class="col-6 text-info fw-semibold">
                                    ₹<?= number_format($payment['refund_amount'], 2) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- ── Traveler Info ────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-person-circle text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Traveler Info</h6>
                        </div>
                        <div class="card-body small">
                            <div class="row g-2">
                                <div class="col-5 text-muted">Name</div>
                                <div class="col-7 fw-semibold">
                                    <?= htmlspecialchars($booking['full_name']) ?>
                                </div>
                                <div class="col-5 text-muted">Email</div>
                                <div class="col-7"><?= htmlspecialchars($booking['email']) ?></div>
                                <?php if ($booking['phone']): ?>
                                <div class="col-5 text-muted">Phone</div>
                                <div class="col-7"><?= htmlspecialchars($booking['phone']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ── Actions ──────────────────────────────────────── -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-3">
                            <i class="bi bi-lightning text-primary fs-5"></i>
                            <h6 class="mb-0 fw-semibold">Actions</h6>
                        </div>
                        <div class="card-body d-grid gap-2">

                            <?php if ($booking['booking_type'] === 'hotel'): ?>
                            <a href="<?= BASE_URL ?>modules/hotels/invoice.php?booking_id=<?= $booking['id'] ?>"
                               class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-file-earmark-text me-1"></i>Download Invoice
                            </a>
                            <?php elseif ($booking['booking_type'] === 'transport'): ?>
                            <a href="<?= BASE_URL ?>modules/transport/ticket.php?booking_id=<?= $booking['id'] ?>"
                               class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-ticket-perforated me-1"></i>Download Ticket
                            </a>
                            <?php endif; ?>

                            <?php if ($booking['booking_status'] === 'confirmed'
                                   || $booking['booking_status'] === 'pending'): ?>
                            <a href="<?= BASE_URL ?>modules/bookings/cancel.php?id=<?= $booking['id'] ?>"
                               class="btn btn-outline-danger btn-sm"
                               onclick="return confirm('Are you sure you want to cancel this booking?')">
                                <i class="bi bi-x-circle me-1"></i>Cancel Booking
                            </a>
                            <?php endif; ?>

                            <?php if ($booking['booking_status'] === 'completed'): ?>
                            <a href="<?= BASE_URL ?>modules/reviews/submit_review.php?booking_id=<?= $booking['id'] ?>&type=<?= $booking['booking_type'] ?>"
                               class="btn btn-outline-success btn-sm">
                                <i class="bi bi-star me-1"></i>Write a Review
                            </a>
                            <?php endif; ?>

                            <a href="<?= BASE_URL ?>modules/chat/inbox.php?booking_id=<?= $booking['id'] ?>"
                               class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-chat-dots me-1"></i>Message Support
                            </a>

                        </div>
                    </div>

                </div><!-- /col-lg-4 -->

            </div><!-- /row -->
        </div><!-- /p-4 -->

    </div><!-- /main-content -->
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>