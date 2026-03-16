<?php
$page_title = 'Select Seats';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$route_id = (int)($_GET['route_id'] ?? 0);
if (!$route_id) redirect('modules/transport/search.php');

// Fetch route
$stmt = $conn->prepare("
    SELECT r.*, tp.company_name, tp.transport_type AS provider_type
    FROM transport_routes r
    JOIN transport_providers tp ON r.provider_id = tp.id
    WHERE r.id = ? AND r.status = 'active'
");
$stmt->bind_param('i', $route_id);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
    set_flash('error', 'Route not found.');
    redirect('modules/transport/search.php');
}

// Fetch all seats for this route
$stmt = $conn->prepare("
    SELECT * FROM transport_seats
    WHERE route_id = ?
    ORDER BY
        FIELD(seat_class, 'first_class', 'ac', 'sleeper', 'general'),
        seat_number + 0 ASC
");
$stmt->bind_param('i', $route_id);
$stmt->execute();
$all_seats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group by class
$seat_groups = [];
foreach ($all_seats as $seat) {
    $seat_groups[$seat['seat_class']][] = $seat;
}

$class_labels = [
    'first_class' => 'First Class',
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

// Handle seat selection & booking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $selected_seats = $_POST['selected_seats'] ?? [];
    $selected_seats = array_map('intval', $selected_seats);

    if (empty($selected_seats)) {
        set_flash('error', 'Please select at least one seat.');
        redirect("modules/transport/select_seats.php?route_id=$route_id");
    }
    if (count($selected_seats) > 6) {
        set_flash('error', 'Maximum 6 seats per booking.');
        redirect("modules/transport/select_seats.php?route_id=$route_id");
    }

    // Verify all seats are available and belong to this route
    $placeholders = implode(',', array_fill(0, count($selected_seats), '?'));
    $types_str    = str_repeat('i', count($selected_seats));

    $stmt = $conn->prepare("
        SELECT * FROM transport_seats
        WHERE id IN ($placeholders) AND route_id = ? AND is_booked = 0
    ");
    $bind_params = array_merge($selected_seats, [$route_id]);
    $bind_types  = $types_str . 'i';
    $stmt->bind_param($bind_types, ...$bind_params);
    $stmt->execute();
    $valid_seats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($valid_seats) !== count($selected_seats)) {
        set_flash('error', 'One or more selected seats are no longer available. Please reselect.');
        redirect("modules/transport/select_seats.php?route_id=$route_id");
    }

    // Store in session and redirect to booking page
    $_SESSION['transport_booking'] = [
        'route_id'       => $route_id,
        'seat_ids'       => $selected_seats,
        'seat_details'   => $valid_seats,
    ];

    redirect('modules/transport/book.php');
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Select Your Seats</h5>
        </div>

        <!-- Route Summary -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-4 text-center">
                        <div class="fs-3 fw-bold">
                            <?= date('H:i', strtotime($route['departure_time'])) ?>
                        </div>
                        <div class="text-muted"><?= htmlspecialchars($route['source']) ?></div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="text-muted small">
                            <?= date('d M Y', strtotime($route['journey_date'])) ?>
                        </div>
                        <div class="my-1">——✈——</div>
                        <div class="badge bg-primary"><?= ucfirst($route['transport_type']) ?></div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="fs-3 fw-bold">
                            <?= date('H:i', strtotime($route['arrival_time'])) ?>
                        </div>
                        <div class="text-muted"><?= htmlspecialchars($route['destination']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Seat Map -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        Seat Selection
                        <small class="text-muted ms-2">(Max 6 seats per booking)</small>
                    </div>
                    <div class="card-body">

                        <!-- Legend -->
                        <div class="d-flex gap-3 mb-4 flex-wrap">
                            <div class="d-flex align-items-center gap-1">
                                <div style="width:28px;height:28px;background:#d1e7dd;
                                            border-radius:5px;border:2px solid #198754"></div>
                                <span class="small">Available</span>
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <div style="width:28px;height:28px;background:#f8d7da;
                                            border-radius:5px;border:2px solid #dc3545"></div>
                                <span class="small">Booked</span>
                            </div>
                            <div class="d-flex align-items-center gap-1">
                                <div style="width:28px;height:28px;background:#0d6efd;
                                            border-radius:5px;border:2px solid #0a58ca"></div>
                                <span class="small">Selected</span>
                            </div>
                        </div>

                        <form method="POST" id="seatForm">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <?php foreach ($seat_groups as $class => $seats): ?>
                            <div class="mb-4">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="badge bg-<?= $class_colors[$class] ?? 'secondary' ?>">
                                        <?= $class_labels[$class] ?? ucfirst($class) ?>
                                    </span>
                                    <span class="text-muted small">
                                        ₹<?= number_format($seats[0]['price'], 0) ?> per seat
                                    </span>
                                </div>

                                <!-- Bus/Train seat grid (4-across layout) -->
                                <div class="seat-map">
                                    <?php
                                    $col = 0;
                                    foreach ($seats as $i => $seat):
                                        // Add aisle gap after every 2 seats
                                        if ($col === 2): ?>
                                    <div class="seat-aisle"></div>
                                    <?php endif;
                                    $col++;
                                    if ($col > 4) { $col = 1; }
                                    ?>
                                    <div class="seat-wrapper <?= $seat['is_booked'] ? 'booked' : 'available' ?>"
                                         data-seat-id="<?= $seat['id'] ?>"
                                         data-price="<?= $seat['price'] ?>"
                                         data-seat-no="<?= htmlspecialchars($seat['seat_number']) ?>"
                                         data-class="<?= htmlspecialchars($class) ?>"
                                         <?= $seat['is_booked'] ? '' : 'onclick="toggleSeat(this)"' ?>>
                                        <input type="checkbox"
                                               name="selected_seats[]"
                                               value="<?= $seat['id'] ?>"
                                               id="seat_<?= $seat['id'] ?>"
                                               <?= $seat['is_booked'] ? 'disabled' : '' ?>
                                               style="display:none">
                                        <span class="seat-number"><?= htmlspecialchars($seat['seat_number']) ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <hr>
                            <?php endforeach; ?>

                            <!-- Driver/Front indicator -->
                            <div class="text-center text-muted small mb-3">
                                ⬆️ Front / Engine
                            </div>

                            <button type="submit" class="btn btn-primary w-100 btn-lg" id="proceedBtn" disabled>
                                Proceed to Booking
                                <span id="seatCount" class="badge bg-white text-primary ms-2">0 seats</span>
                                — ₹<span id="totalPrice">0</span>
                            </button>
                        </form>

                    </div>
                </div>
            </div>

            <!-- Selected Seats Summary -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm sticky-top" style="top:20px">
                    <div class="card-header bg-white fw-bold">Your Selection</div>
                    <div class="card-body">
                        <div id="selectionSummary" class="text-muted">
                            No seats selected yet.
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between fw-bold">
                            <span>Total</span>
                            <span class="text-primary">₹<span id="summaryTotal">0</span></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<style>
.seat-map {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 10px;
}
.seat-wrapper {
    width: 44px;
    height: 44px;
    border-radius: 8px 8px 4px 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    position: relative;
    border: 2px solid;
}
.seat-wrapper.available {
    background: #d1e7dd;
    border-color: #198754;
    color: #198754;
}
.seat-wrapper.available:hover {
    background: #a3cfbb;
    transform: scale(1.08);
}
.seat-wrapper.booked {
    background: #f8d7da;
    border-color: #dc3545;
    color: #dc3545;
    cursor: not-allowed;
    opacity: 0.7;
}
.seat-wrapper.selected {
    background: #0d6efd;
    border-color: #0a58ca;
    color: #fff;
    transform: scale(1.05);
}
.seat-aisle {
    width: 20px;
}
.seat-number {
    font-size: 10px;
}
</style>

<script>
const selectedSeats = {};

function toggleSeat(el) {
    const seatId    = el.dataset.seatId;
    const price     = parseFloat(el.dataset.price);
    const seatNo    = el.dataset.seatNo;
    const seatClass = el.dataset.class;
    const checkbox  = document.getElementById('seat_' + seatId);

    if (el.classList.contains('selected')) {
        el.classList.remove('selected');
        el.classList.add('available');
        checkbox.checked = false;
        delete selectedSeats[seatId];
    } else {
        if (Object.keys(selectedSeats).length >= 6) {
            alert('You can select a maximum of 6 seats.');
            return;
        }
        el.classList.remove('available');
        el.classList.add('selected');
        checkbox.checked = true;
        selectedSeats[seatId] = { seatNo, price, seatClass };
    }
    updateSummary();
}

function updateSummary() {
    const seats = Object.values(selectedSeats);
    const total = seats.reduce((sum, s) => sum + s.price, 0);
    const count = seats.length;

    document.getElementById('seatCount').textContent =
        count + ' seat' + (count !== 1 ? 's' : '');
    document.getElementById('totalPrice').textContent  = total.toFixed(0);
    document.getElementById('summaryTotal').textContent = total.toFixed(0);
    document.getElementById('proceedBtn').disabled     = count === 0;

    const summaryDiv = document.getElementById('selectionSummary');
    if (seats.length === 0) {
        summaryDiv.innerHTML = '<span class="text-muted">No seats selected yet.</span>';
    } else {
        summaryDiv.innerHTML = seats.map(s => `
            <div class="d-flex justify-content-between mb-1">
                <span>Seat <strong>${s.seatNo}</strong>
                      <small class="text-muted">(${s.seatClass})</small></span>
                <span>₹${s.price.toFixed(0)}</span>
            </div>
        `).join('');
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>/