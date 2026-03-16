<?php
$page_title = 'Guide Availability';
require_once __DIR__ . '/../../../includes/header.php';
require_role('guide');

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT id FROM guides WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guide) {
    set_flash('error', 'Please complete your profile first.');
    redirect('modules/guides/manage/profile.php');
}

$guide_id = $guide['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $date         = sanitize($_POST['date'] ?? '');
    $is_available = (int)($_POST['is_available'] ?? 1);
    $notes        = sanitize($_POST['notes'] ?? '');

    if (!$date) {
        set_flash('error', 'Date is required.');
    } else {
        $stmt = $conn->prepare("
            INSERT INTO availability_calendars
                (entity_type, entity_id, date, is_available, notes)
            VALUES ('guide', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                is_available = VALUES(is_available),
                notes        = VALUES(notes)
        ");
        $stmt->bind_param('isis', $guide_id, $date, $is_available, $notes);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Availability updated.');
    }
    redirect('modules/guides/manage/availability.php');
}

// Load 30-day calendar
$start = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT * FROM availability_calendars
    WHERE entity_type = 'guide' AND entity_id = ?
      AND date >= ? ORDER BY date ASC LIMIT 60
");
$stmt->bind_param('is', $guide_id, $start);
$stmt->execute();
$avail_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$calendar = [];
foreach ($avail_rows as $row) {
    $calendar[$row['date']] = $row;
}

// Load booked dates
$stmt = $conn->prepare("
    SELECT b.check_in FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    WHERE bi.item_type = 'guide_session' AND bi.item_id = ?
      AND b.booking_status NOT IN ('cancelled') AND b.check_in >= CURDATE()
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$booked = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'check_in');
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/guide/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Manage Availability</h5>
        </div>

        <div class="row g-4">
            <!-- Set Availability -->
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Block / Open Dates</div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="mb-3">
                                <label class="form-label">Date</label>
                                <input type="date" name="date" class="form-control"
                                       min="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select name="is_available" class="form-select">
                                    <option value="1">Available</option>
                                    <option value="0">Blocked / Off</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Note (optional)</label>
                                <input type="text" name="notes" class="form-control"
                                       placeholder="e.g. Personal holiday">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                Update
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Legend -->
                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div style="width:20px;height:20px;background:#d1e7dd;border-radius:4px"></div>
                            <span class="small">Available</span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div style="width:20px;height:20px;background:#f8d7da;border-radius:4px"></div>
                            <span class="small">Blocked</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <div style="width:20px;height:20px;background:#cfe2ff;border-radius:4px"></div>
                            <span class="small">Booked</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 30-Day Calendar -->
            <div class="col-md-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Your Schedule — Next 60 Days</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php for ($i = 0; $i < 60; $i++):
                                $date   = date('Y-m-d', strtotime("+$i days"));
                                $avail  = $calendar[$date] ?? null;
                                $booked_day = in_array($date, $booked);

                                if ($booked_day) {
                                    $bg    = 'bg-primary bg-opacity-10 border-primary';
                                    $label = '<span class="text-primary" style="font-size:9px">Booked</span>';
                                } elseif ($avail && !$avail['is_available']) {
                                    $bg    = 'bg-danger bg-opacity-10 border-danger';
                                    $label = '<span class="text-danger" style="font-size:9px">Blocked</span>';
                                } else {
                                    $bg    = 'bg-success bg-opacity-10 border-success';
                                    $label = '<span class="text-success" style="font-size:9px">Open</span>';
                                }
                            ?>
                            <div class="col-2 col-md-1" style="min-width:58px">
                                <div class="card border <?= $bg ?> text-center p-1">
                                    <div class="small fw-bold"><?= date('d', strtotime($date)) ?></div>
                                    <div style="font-size:9px" class="text-muted"><?= date('M', strtotime($date)) ?></div>
                                    <?= $label ?>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>