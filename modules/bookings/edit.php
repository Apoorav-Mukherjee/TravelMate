<?php
$page_title = 'Edit Booking';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$user_id    = $_SESSION['user_id'];
$booking_id = (int)($_GET['id'] ?? 0);

if (!$booking_id) {
    set_flash('error', 'Invalid booking.');
    redirect('modules/bookings/my_bookings.php');
}

$esc_id   = $conn->real_escape_string($booking_id);
$esc_user = $conn->real_escape_string($user_id);

// ── Fetch booking ─────────────────────────────────────────────────────────
$r = $conn->query("
    SELECT * FROM bookings
    WHERE id = $esc_id AND user_id = $esc_user
    LIMIT 1
");
if (!$r || $r->num_rows === 0) {
    set_flash('error', 'Booking not found.');
    redirect('modules/bookings/my_bookings.php');
}
$booking = $r->fetch_assoc();

// Only allow editing pending/confirmed
if (!in_array($booking['booking_status'], ['pending', 'confirmed'])) {
    set_flash('error', 'This booking cannot be edited.');
    redirect('modules/bookings/my_bookings.php');
}

// ── Fetch booking items ───────────────────────────────────────────────────
$ri    = $conn->query("SELECT * FROM booking_items WHERE booking_id = $esc_id ORDER BY id ASC");
$items = ($ri) ? $ri->fetch_all(MYSQLI_ASSOC) : [];

// ── Type-specific detail for pre-filling ─────────────────────────────────
$type          = $booking['booking_type'];
$hotel_detail  = null;
$guide_detail  = null;
$route_detail  = null;
$pkg_booking   = null;

if ($type === 'hotel') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'room') {
            $rid = (int)$item['item_id'];
            $rh  = $conn->query("
                SELECT r.*, h.name AS hotel_name, h.city, h.state
                FROM rooms r JOIN hotels h ON h.id = r.hotel_id
                WHERE r.id = $rid LIMIT 1
            ");
            if ($rh && $rh->num_rows > 0) $hotel_detail = $rh->fetch_assoc();
        }
    }
}
if ($type === 'guide') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'guide') {
            $gid = (int)$item['item_id'];
            $rg  = $conn->query("
                SELECT g.*, u.full_name AS guide_name
                FROM guides g JOIN users u ON u.id = g.user_id
                WHERE g.id = $gid LIMIT 1
            ");
            if ($rg && $rg->num_rows > 0) $guide_detail = $rg->fetch_assoc();
        }
    }
}
if ($type === 'transport') {
    foreach ($items as $item) {
        if ($item['item_type'] === 'transport_route') {
            $trid = (int)$item['item_id'];
            $rt   = $conn->query("
                SELECT tr.*, tp.company_name
                FROM transport_routes tr
                JOIN transport_providers tp ON tp.id = tr.provider_id
                WHERE tr.id = $trid LIMIT 1
            ");
            if ($rt && $rt->num_rows > 0) $route_detail = $rt->fetch_assoc();
        }
    }
}
if ($type === 'package') {
    $rpb = $conn->query("
        SELECT pb.*, tp.title, tp.city, tp.duration_days,
               tp.min_persons, tp.max_persons
        FROM package_bookings pb
        JOIN tour_packages tp ON tp.id = pb.package_id
        WHERE pb.booking_id = $esc_id LIMIT 1
    ");
    if ($rpb && $rpb->num_rows > 0) $pkg_booking = $rpb->fetch_assoc();
}

// ── Handle POST ───────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

        $notes     = sanitize($_POST['notes']     ?? '');
        $check_in  = sanitize($_POST['check_in']  ?? '');
        $check_out = sanitize($_POST['check_out'] ?? '');

        // Validate dates if provided
        if ($check_in && !strtotime($check_in)) {
            $errors[] = 'Invalid check-in / travel date.';
        }
        if ($check_out && !strtotime($check_out)) {
            $errors[] = 'Invalid check-out date.';
        }
        if ($check_in && $check_out && strtotime($check_out) < strtotime($check_in)) {
            $errors[] = 'Check-out date must be after check-in date.';
        }
        if ($check_in && strtotime($check_in) < strtotime('today')) {
            $errors[] = 'Travel date cannot be in the past.';
        }

        // Package-specific: persons
        $persons = null;
        if ($type === 'package' && $pkg_booking) {
            $persons = (int)($_POST['persons'] ?? $pkg_booking['persons']);
            $min     = (int)$pkg_booking['min_persons'];
            $max     = (int)$pkg_booking['max_persons'];
            if ($persons < $min || $persons > $max) {
                $errors[] = "Number of persons must be between $min and $max.";
            }
        }

        if (empty($errors)) {
            // Build update fields
            $esc_notes     = $conn->real_escape_string($notes);
            $set_check_in  = $check_in  ? "check_in  = '$check_in',"  : '';
            $set_check_out = $check_out ? "check_out = '$check_out'," : '';

            $upd = $conn->query("
                UPDATE bookings
                SET $set_check_in $set_check_out notes = '$esc_notes'
                WHERE id = $esc_id AND user_id = $esc_user
            ");

            // Update package persons if applicable
            if ($type === 'package' && $persons !== null) {
                $conn->query("
                    UPDATE package_bookings
                    SET persons = $persons
                    WHERE booking_id = $esc_id
                ");
            }

            if ($upd) {
                set_flash('success', 'Booking updated successfully.');
                redirect('modules/bookings/view.php?id=' . $booking_id);
            } else {
                $errors[] = 'Update failed. Please try again.';
            }
        }
}

// ── Type icon helper ──────────────────────────────────────────────────────
$type_icon = match($type) {
    'hotel'     => 'building',
    'guide'     => 'person-badge',
    'transport' => 'bus-front',
    'package'   => 'suitcase',
    default     => 'tag',
};
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>

    <div class="main-content w-100">

        <!-- ── Topbar ─────────────────────────────────────────────────── -->
        <div class="topbar d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $booking_id ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <h5 class="mb-0">Edit Booking</h5>
            </div>
            <code class="text-muted"><?= htmlspecialchars($booking['booking_ref']) ?></code>
        </div>

        <div class="p-3 p-md-4">
            <div class="row g-4 justify-content-center">
                <div class="col-lg-8">

                    <!-- ── Errors ────────────────────────────────────────── -->
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- ── Booking type banner ───────────────────────────── -->
                    <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-4">
                        <i class="bi bi-<?= $type_icon ?> fs-5"></i>
                        <div>
                            Editing a <strong><?= ucfirst($type) ?></strong> booking —
                            <code><?= htmlspecialchars($booking['booking_ref']) ?></code>
                            <span class="badge bg-<?=
                                $booking['booking_status'] === 'confirmed' ? 'success' : 'warning text-dark'
                            ?> ms-2">
                                <?= ucfirst($booking['booking_status']) ?>
                            </span>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <!-- ── What can be edited (read-only summary) ───── -->
                        <?php if ($type === 'hotel' && $hotel_detail): ?>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="bi bi-building text-primary me-2"></i>Hotel Info
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 small text-muted mb-3">
                                    <div class="col-sm-6">
                                        <span class="fw-semibold text-dark">
                                            <?= htmlspecialchars($hotel_detail['hotel_name']) ?>
                                        </span> —
                                        <?= htmlspecialchars($hotel_detail['room_type']) ?>
                                    </div>
                                    <div class="col-sm-6">
                                        <?= htmlspecialchars($hotel_detail['city']) ?>,
                                        <?= htmlspecialchars($hotel_detail['state']) ?>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Check-in Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="check_in" class="form-control"
                                               value="<?= htmlspecialchars($booking['check_in'] ?? '') ?>"
                                               min="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Check-out Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="check_out" class="form-control"
                                               value="<?= htmlspecialchars($booking['check_out'] ?? '') ?>"
                                               min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($type === 'guide' && $guide_detail): ?>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="bi bi-person-badge text-primary me-2"></i>Guide Info
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-3">
                                    Guide: <strong><?= htmlspecialchars($guide_detail['guide_name']) ?></strong>
                                    &nbsp;|&nbsp; City: <?= htmlspecialchars($guide_detail['city'] ?? '—') ?>
                                </p>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Travel Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="check_in" class="form-control"
                                               value="<?= htmlspecialchars($booking['check_in'] ?? '') ?>"
                                               min="<?= date('Y-m-d') ?>" required>
                                        <input type="hidden" name="check_out"
                                               value="<?= htmlspecialchars($booking['check_out'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($type === 'transport' && $route_detail): ?>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="bi bi-bus-front text-primary me-2"></i>Transport Info
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="d-flex align-items-center gap-3 bg-light rounded p-3 mb-3">
                                    <div class="text-center">
                                        <div class="fw-bold">
                                            <?= htmlspecialchars($route_detail['source']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= date('h:i A', strtotime($route_detail['departure_time'])) ?>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 text-center text-muted small">
                                        <i class="bi bi-arrow-right"></i><br>
                                        <?= htmlspecialchars($route_detail['company_name']) ?>
                                    </div>
                                    <div class="text-center">
                                        <div class="fw-bold">
                                            <?= htmlspecialchars($route_detail['destination']) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <?= date('h:i A', strtotime($route_detail['arrival_time'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Journey Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="check_in" class="form-control"
                                               value="<?= htmlspecialchars($booking['check_in'] ?? '') ?>"
                                               min="<?= date('Y-m-d') ?>" required>
                                        <input type="hidden" name="check_out"
                                               value="<?= htmlspecialchars($booking['check_in'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php elseif ($type === 'package' && $pkg_booking): ?>
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="bi bi-suitcase text-primary me-2"></i>Package Info
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-3">
                                    <strong><?= htmlspecialchars($pkg_booking['title']) ?></strong>
                                    — <?= htmlspecialchars($pkg_booking['city']) ?>
                                    (<?= $pkg_booking['duration_days'] ?> days)
                                </p>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Travel Date <span class="text-danger">*</span>
                                        </label>
                                        <input type="date" name="check_in" class="form-control"
                                               value="<?= htmlspecialchars($booking['check_in'] ?? '') ?>"
                                               min="<?= date('Y-m-d') ?>" required>
                                        <input type="hidden" name="check_out"
                                               value="<?= htmlspecialchars($booking['check_out'] ?? '') ?>">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label fw-semibold small">
                                            Number of Persons <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" name="persons" class="form-control"
                                               value="<?= (int)$pkg_booking['persons'] ?>"
                                               min="<?= (int)$pkg_booking['min_persons'] ?>"
                                               max="<?= (int)$pkg_booking['max_persons'] ?>"
                                               required>
                                        <div class="form-text">
                                            Min <?= (int)$pkg_booking['min_persons'] ?> —
                                            Max <?= (int)$pkg_booking['max_persons'] ?> persons
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- ── Notes (all types) ─────────────────────────── -->
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white border-bottom py-3">
                                <h6 class="mb-0 fw-semibold">
                                    <i class="bi bi-chat-left-text text-primary me-2"></i>
                                    Additional Notes
                                </h6>
                            </div>
                            <div class="card-body">
                                <textarea name="notes" class="form-control" rows="4"
                                          placeholder="Any special requests or notes for this booking..."
                                          maxlength="1000"><?= htmlspecialchars($booking['notes'] ?? '') ?></textarea>
                                <div class="form-text">Max 1000 characters</div>
                            </div>
                        </div>

                        <!-- ── Submit ────────────────────────────────────── -->
                        <div class="d-flex gap-2 justify-content-end">
                            <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $booking_id ?>"
                               class="btn btn-outline-secondary">
                                <i class="bi bi-x me-1"></i>Discard Changes
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i>Save Changes
                            </button>
                        </div>

                    </form>

                </div><!-- /col -->
            </div><!-- /row -->
        </div><!-- /p-4 -->

    </div><!-- /main-content -->
</div>

<!-- date sync: keep check_out min = check_in + 1 day -->
<script>
const checkIn  = document.querySelector('input[name="check_in"]');
const checkOut = document.querySelector('input[name="check_out"]');
if (checkIn && checkOut && checkOut.type === 'date') {
    checkIn.addEventListener('change', () => {
        if (checkIn.value) {
            const next = new Date(checkIn.value);
            next.setDate(next.getDate() + 1);
            checkOut.min   = next.toISOString().split('T')[0];
            if (checkOut.value && checkOut.value <= checkIn.value) {
                checkOut.value = next.toISOString().split('T')[0];
            }
        }
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>