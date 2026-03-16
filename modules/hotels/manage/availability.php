<?php
$page_title = 'Availability Manager';
require_once __DIR__ . '/../../../includes/header.php';
require_role('hotel_staff');

$user_id = $_SESSION['user_id'];

// Get hotel
$stmt = $conn->prepare("SELECT id FROM hotels WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$my_hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$my_hotel) {
    set_flash('error', 'Please add your hotel first.');
    redirect('modules/hotels/manage/create.php');
}

$hotel_id = $my_hotel['id'];

// Fetch rooms
$stmt = $conn->prepare("SELECT id, room_type FROM rooms WHERE hotel_id = ? AND status = 'available' ORDER BY room_type");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Handle POST (availability update only) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $room_id        = (int)($_POST['room_id']        ?? 0);
    $date           = sanitize($_POST['date']          ?? '');
    $is_available   = (int)($_POST['is_available']    ?? 1);
    $override_price = !empty($_POST['override_price'])
                      ? (float)$_POST['override_price']
                      : null;
    $notes = sanitize($_POST['notes'] ?? '');

    if (!$room_id || !$date) {
        set_flash('error', 'Room and date are required.');
    } else {
        $stmt = $conn->prepare("
            INSERT INTO availability_calendars
                (entity_type, entity_id, date, is_available, override_price, notes)
            VALUES ('room', ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_available   = VALUES(is_available),
                override_price = VALUES(override_price),
                notes          = VALUES(notes)
        ");
        $stmt->bind_param('isdds', $room_id, $date, $is_available, $override_price, $notes);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Availability updated.');
    }
    // Redirect back preserving the selected room in the URL
    redirect('modules/hotels/manage/availability.php?room_id=' . $room_id);
}

// ── Load calendar (GET) ───────────────────────────────────────────────────────
$selected_room = (int)($_GET['room_id'] ?? ($rooms[0]['id'] ?? 0));
$calendar      = [];

if ($selected_room) {
    $start = date('Y-m-d');
    $end   = date('Y-m-d', strtotime('+30 days'));
    $stmt  = $conn->prepare("
        SELECT * FROM availability_calendars
        WHERE entity_type = 'room' AND entity_id = ? AND date BETWEEN ? AND ?
    ");
    $stmt->bind_param('iss', $selected_room, $start, $end);
    $stmt->execute();
    $avail_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($avail_rows as $row) {
        $calendar[$row['date']] = $row;
    }
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/hotel_staff/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Room Availability Manager</h5>
        </div>

        <?php $flash = get_flash(); ?>
        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mx-3 mt-3 mb-0 alert-dismissible fade show">
            <?= htmlspecialchars($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="row g-4">

                <!-- ── Set Availability Form ──────────────────────────────── -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white fw-bold py-3">
                            <i class="bi bi-calendar-check text-primary me-2"></i>Set Availability
                        </div>
                        <div class="card-body">

                            <!-- Room selector uses GET so it never triggers POST validation -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Select Room</label>
                                <select class="form-select"
                                        onchange="window.location.href='?room_id=' + this.value">
                                    <?php foreach ($rooms as $r): ?>
                                    <option value="<?= $r['id'] ?>"
                                        <?= $r['id'] == $selected_room ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($r['room_type']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if (empty($rooms)): ?>
                            <div class="alert alert-warning small">
                                No available rooms found. Please add rooms first.
                            </div>
                            <?php else: ?>

                            <!-- Availability update form (POST) -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <!-- Pass the currently selected room -->
                                <input type="hidden" name="room_id" value="<?= $selected_room ?>">

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Date <span class="text-danger">*</span></label>
                                    <input type="date" name="date" class="form-control"
                                           min="<?= date('Y-m-d') ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">Status</label>
                                    <select name="is_available" class="form-select">
                                        <option value="1">✅ Available</option>
                                        <option value="0">🚫 Blocked / Unavailable</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">
                                        Override Price (₹)
                                        <span class="text-muted fw-normal">(optional)</span>
                                    </label>
                                    <input type="number" name="override_price" class="form-control"
                                           min="0" step="0.01" placeholder="Leave empty for base price">
                                    <div class="form-text">Use for seasonal / holiday pricing</div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label fw-semibold small">Notes</label>
                                    <input type="text" name="notes" class="form-control"
                                           placeholder="e.g. Diwali pricing">
                                </div>

                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-calendar-check me-2"></i>Update Availability
                                </button>
                            </form>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <!-- ── 30-Day Calendar View ───────────────────────────────── -->
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                            <span class="fw-bold">
                                <i class="bi bi-calendar3 text-primary me-2"></i>Next 30 Days
                            </span>
                            <?php
                            $room_name = '';
                            foreach ($rooms as $r) {
                                if ($r['id'] == $selected_room) { $room_name = $r['room_type']; break; }
                            }
                            ?>
                            <?php if ($room_name): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <?= htmlspecialchars($room_name) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">

                            <!-- Legend -->
                            <div class="d-flex gap-3 mb-3 small">
                                <span class="d-flex align-items-center gap-1">
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Open</span>
                                    Available
                                </span>
                                <span class="d-flex align-items-center gap-1">
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked</span>
                                    Unavailable
                                </span>
                                <span class="d-flex align-items-center gap-1">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">₹</span>
                                    Custom price set
                                </span>
                            </div>

                            <div class="row g-2">
                                <?php for ($i = 0; $i < 30; $i++):
                                    $d       = date('Y-m-d', strtotime("+$i days"));
                                    $avail   = $calendar[$d] ?? null;
                                    $blocked = $avail && !$avail['is_available'];
                                    $price   = $avail['override_price'] ?? null;
                                    $is_today = ($i === 0);
                                    $bg  = $blocked
                                         ? 'bg-danger bg-opacity-10 border-danger'
                                         : 'bg-success bg-opacity-10 border-success';
                                    $ring = $is_today ? ' ring-today' : '';
                                ?>
                                <div class="col-4 col-sm-3 col-md-2">
                                    <div class="card border <?= $bg ?><?= $ring ?> text-center p-2"
                                         style="min-height:72px;<?= $is_today ? 'box-shadow:0 0 0 2px #0d6efd;' : '' ?>">
                                        <div class="fw-bold" style="font-size:15px">
                                            <?= date('d', strtotime($d)) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:10px">
                                            <?= date('D', strtotime($d)) ?>,
                                            <?= date('M', strtotime($d)) ?>
                                        </div>
                                        <?php if ($price): ?>
                                        <div class="text-primary fw-semibold" style="font-size:10px">
                                            ₹<?= number_format($price, 0) ?>
                                        </div>
                                        <?php endif; ?>
                                        <div style="font-size:10px; margin-top:2px">
                                            <?= $blocked
                                                ? '<span class="text-danger">Blocked</span>'
                                                : '<span class="text-success">Open</span>' ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endfor; ?>
                            </div>

                        </div>
                    </div>
                </div>

            </div><!-- /row -->
        </div><!-- /p-4 -->

    </div><!-- /main-content -->
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>