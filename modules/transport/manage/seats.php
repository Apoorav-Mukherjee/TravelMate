<?php
$page_title = 'Seat Matrix';
require_once __DIR__ . '/../../../includes/header.php';
require_role('transport_provider');

$user_id  = $_SESSION['user_id'];
$route_id = (int)($_GET['route_id'] ?? 0);

if (!$route_id) redirect('modules/transport/manage/routes.php');

// Verify ownership
$stmt = $conn->prepare("
    SELECT r.*, tp.id as provider_id FROM transport_routes r
    JOIN transport_providers tp ON r.provider_id = tp.id
    WHERE r.id = ? AND tp.user_id = ?
");
$stmt->bind_param('ii', $route_id, $user_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
    set_flash('error', 'Route not found.');
    redirect('modules/transport/manage/routes.php');
}

// Handle seat price update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $seat_id    = (int)($_POST['seat_id'] ?? 0);
    $new_price  = (float)($_POST['price'] ?? 0);
    $seat_class = sanitize($_POST['seat_class'] ?? '');

    if ($seat_id && $new_price > 0) {
        $stmt = $conn->prepare("
            UPDATE transport_seats SET price = ?, seat_class = ?
            WHERE id = ? AND route_id = ?
        ");
        $stmt->bind_param('dsii', $new_price, $seat_class, $seat_id, $route_id);
        $stmt->execute();
        $stmt->close();
        set_flash('success', 'Seat updated.');
    }
    redirect("modules/transport/manage/seats.php?route_id=$route_id");
}

// Fetch seats
$stmt = $conn->prepare("
    SELECT * FROM transport_seats WHERE route_id = ?
    ORDER BY FIELD(seat_class,'first_class','ac','sleeper','general'), seat_number + 0 ASC
");
$stmt->bind_param('i', $route_id);
$stmt->execute();
$seats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$class_labels = [
    'first_class' => '1st Class',
    'ac'          => 'AC',
    'sleeper'     => 'Sleeper',
    'general'     => 'General',
];
$class_colors = [
    'first_class' => 'warning',
    'ac'          => 'info',
    'sleeper'     => 'primary',
    'general'     => 'secondary',
];

// Group by class
$grouped = [];
foreach ($seats as $s) {
    $grouped[$s['seat_class']][] = $s;
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/transport_provider/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">
                Seat Matrix —
                <?= htmlspecialchars($route['source']) ?> →
                <?= htmlspecialchars($route['destination']) ?>
                (<?= date('d M Y', strtotime($route['journey_date'])) ?>)
            </h5>
            <a href="<?= BASE_URL ?>modules/transport/manage/routes.php"
               class="btn btn-outline-secondary btn-sm">← Back to Routes</a>
        </div>

        <!-- Seat Stats -->
        <div class="row g-3 mb-4">
            <?php
            $total_seats = count($seats);
            $booked      = count(array_filter($seats, fn($s) => $s['is_booked']));
            $available   = $total_seats - $booked;
            ?>
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-primary border-4">
                    <div class="text-muted small">Total Seats</div>
                    <div class="fs-3 fw-bold"><?= $total_seats ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-success border-4">
                    <div class="text-muted small">Available</div>
                    <div class="fs-3 fw-bold text-success"><?= $available ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-danger border-4">
                    <div class="text-muted small">Booked</div>
                    <div class="fs-3 fw-bold text-danger"><?= $booked ?></div>
                </div>
            </div>
        </div>

        <!-- Seat Grid -->
        <?php foreach ($grouped as $class => $class_seats): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="badge bg-<?= $class_colors[$class] ?? 'secondary' ?>">
                    <?= $class_labels[$class] ?? ucfirst($class) ?>
                </span>
                <span class="text-muted small"><?= count($class_seats) ?> seats</span>
                <span class="ms-auto text-muted small">
                    Base Price: ₹<?= number_format($class_seats[0]['price'], 0) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($class_seats as $seat): ?>
                    <div class="seat-admin
                        <?= $seat['is_booked'] ? 'seat-booked' : 'seat-free' ?>"
                         data-bs-toggle="tooltip"
                         title="<?= htmlspecialchars($seat['seat_number']) ?> | ₹<?= number_format($seat['price'], 0) ?> | <?= $seat['is_booked'] ? 'Booked' : 'Available' ?>"
                         onclick="<?= $seat['is_booked'] ? '' : "editSeat({$seat['id']}, '{$seat['seat_number']}', {$seat['price']}, '{$seat['seat_class']}')" ?>">
                        <?= htmlspecialchars($seat['seat_number']) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

    </div>
</div>

<!-- Edit Seat Modal -->
<div class="modal fade" id="seatEditModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Edit Seat <span id="editSeatNo"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="seat_id"    id="editSeatId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Price (₹)</label>
                        <input type="number" name="price" id="editSeatPrice"
                               class="form-control" min="0" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Class</label>
                        <select name="seat_class" id="editSeatClass" class="form-select">
                            <option value="general">General</option>
                            <option value="sleeper">Sleeper</option>
                            <option value="ac">AC</option>
                            <option value="first_class">First Class</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-sm">Update Seat</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.seat-admin {
    width: 44px;
    height: 44px;
    border-radius: 8px 8px 4px 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
    border: 2px solid;
    cursor: pointer;
    transition: all 0.15s;
}
.seat-free {
    background: #d1e7dd;
    border-color: #198754;
    color: #198754;
}
.seat-free:hover {
    background: #0d6efd;
    border-color: #0a58ca;
    color: #fff;
    transform: scale(1.08);
}
.seat-booked {
    background: #f8d7da;
    border-color: #dc3545;
    color: #dc3545;
    cursor: not-allowed;
    opacity: 0.7;
}
</style>

<script>
// Init tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
});

function editSeat(id, no, price, cls) {
    document.getElementById('editSeatId').value     = id;
    document.getElementById('editSeatNo').textContent = no;
    document.getElementById('editSeatPrice').value  = price;
    document.getElementById('editSeatClass').value  = cls;
    new bootstrap.Modal(document.getElementById('seatEditModal')).show();
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>