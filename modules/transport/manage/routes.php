<?php
$page_title = 'Manage Routes';
require_once __DIR__ . '/../../../includes/header.php';
require_role('transport_provider');

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM transport_providers WHERE user_id = ? AND is_approved = 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    set_flash('error', 'Your provider profile is not approved yet.');
    redirect('dashboards/transport_provider/index.php');
}

$provider_id = $provider['id'];
$errors      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action       = sanitize($_POST['action']       ?? 'add');
    $route_id     = (int)($_POST['route_id']        ?? 0);
    $route_name   = sanitize($_POST['route_name']   ?? '');
    $source       = sanitize($_POST['source']       ?? '');
    $destination  = sanitize($_POST['destination']  ?? '');
    $dep_time     = sanitize($_POST['departure_time'] ?? '');
    $arr_time     = sanitize($_POST['arrival_time']  ?? '');
    $journey_date = sanitize($_POST['journey_date']  ?? '');
    $base_fare    = (float)($_POST['base_fare']     ?? 0);
    $total_seats  = (int)($_POST['total_seats']     ?? 40);
    $trans_type   = sanitize($_POST['transport_type'] ?? 'bus');
    $status       = sanitize($_POST['status']       ?? 'active');

    if (empty($source))       $errors[] = 'Source is required.';
    if (empty($destination))  $errors[] = 'Destination is required.';
    if (empty($journey_date)) $errors[] = 'Journey date is required.';
    if ($base_fare <= 0)      $errors[] = 'Valid base fare is required.';
    if ($total_seats < 1)     $errors[] = 'At least 1 seat required.';

    if (empty($errors)) {
        if ($action === 'add') {
            $stmt = $conn->prepare("
                INSERT INTO transport_routes
                    (provider_id, route_name, source, destination,
                     departure_time, arrival_time, journey_date,
                     base_fare, total_seats, available_seats, transport_type, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param('issssssdiiis',
                $provider_id, $route_name, $source, $destination,
                $dep_time, $arr_time, $journey_date,
                $base_fare, $total_seats, $total_seats, $trans_type, $status
            );
            $stmt->execute();
            $new_route_id = $conn->insert_id;
            $stmt->close();

            // Auto-generate seats
            generate_seats($conn, $new_route_id, $total_seats, $trans_type, $base_fare);

            set_flash('success', 'Route added with seats generated automatically.');

        } elseif ($action === 'edit' && $route_id) {
            $stmt = $conn->prepare("
                UPDATE transport_routes
                SET route_name=?, source=?, destination=?,
                    departure_time=?, arrival_time=?, journey_date=?,
                    base_fare=?, transport_type=?, status=?
                WHERE id=? AND provider_id=?
            ");
            $stmt->bind_param('ssssssdssii',
                $route_name, $source, $destination,
                $dep_time, $arr_time, $journey_date,
                $base_fare, $trans_type, $status, $route_id, $provider_id
            );
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Route updated.');

        } elseif ($action === 'delete' && $route_id) {
            $conn->query("DELETE FROM transport_seats  WHERE route_id = $route_id");
            $conn->query("UPDATE transport_routes SET status = 'cancelled' WHERE id = $route_id AND provider_id = $provider_id");
            set_flash('success', 'Route cancelled.');
        }

        redirect('modules/transport/manage/routes.php');
    }
}

// Helper: auto generate seat matrix
function generate_seats($conn, $route_id, $total, $transport_type, $base_fare) {
    // Class distribution
    if ($transport_type === 'train') {
        $classes = [
            'first_class' => ['count' => max(1, intval($total * 0.1)), 'multiplier' => 3.0],
            'ac'          => ['count' => max(1, intval($total * 0.2)), 'multiplier' => 2.0],
            'sleeper'     => ['count' => max(1, intval($total * 0.3)), 'multiplier' => 1.5],
            'general'     => ['count' => 0, 'multiplier' => 1.0], // fill rest
        ];
    } else {
        $classes = [
            'ac'      => ['count' => max(1, intval($total * 0.3)), 'multiplier' => 1.5],
            'general' => ['count' => 0, 'multiplier' => 1.0],
        ];
    }

    // Fill remaining to general
    $assigned = array_sum(array_column($classes, 'count'));
    $classes[array_key_last($classes)]['count'] = max(1, $total - $assigned);

    $seat_num = 1;
    foreach ($classes as $class => $cfg) {
        $price = round($base_fare * $cfg['multiplier'], 2);
        for ($i = 0; $i < $cfg['count']; $i++) {
            $sn = ($transport_type === 'bus' ? 'B' : ($transport_type === 'train' ? 'T' : 'S'))
                  . str_pad($seat_num, 2, '0', STR_PAD_LEFT);
            $conn->query("
                INSERT INTO transport_seats
                    (route_id, seat_number, seat_class, price, is_booked)
                VALUES ($route_id, '$sn', '$class', $price, 0)
            ");
            $seat_num++;
        }
    }
}

// Fetch routes
$stmt = $conn->prepare("
    SELECT r.*,
           (SELECT COUNT(*) FROM transport_seats s WHERE s.route_id = r.id)           AS total_seat_count,
           (SELECT COUNT(*) FROM transport_seats s WHERE s.route_id = r.id AND s.is_booked = 0) AS avail_count
    FROM transport_routes r
    WHERE r.provider_id = ?
    ORDER BY r.journey_date DESC, r.departure_time ASC
");
$stmt->bind_param('i', $provider_id);
$stmt->execute();
$routes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/transport_provider/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Manage Routes</h5>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#routeModal">
                <i class="bi bi-plus"></i> Add Route
            </button>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Route</th>
                            <th>Date</th>
                            <th>Departure</th>
                            <th>Type</th>
                            <th>Fare</th>
                            <th>Seats</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routes as $r): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($r['source']) ?></strong>
                                → <?= htmlspecialchars($r['destination']) ?>
                                <?php if ($r['route_name']): ?>
                                <br><small class="text-muted"><?= htmlspecialchars($r['route_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= date('d M Y', strtotime($r['journey_date'])) ?></td>
                            <td>
                                <?= date('H:i', strtotime($r['departure_time'])) ?>
                                <br>
                                <small class="text-muted">
                                    → <?= date('H:i', strtotime($r['arrival_time'])) ?>
                                </small>
                            </td>
                            <td><?= ucfirst($r['transport_type']) ?></td>
                            <td>₹<?= number_format($r['base_fare'], 0) ?></td>
                            <td>
                                <span class="text-success fw-bold"><?= $r['avail_count'] ?></span>
                                / <?= $r['total_seat_count'] ?>
                            </td>
                            <td>
                                <span class="badge <?=
                                    $r['status'] === 'active'    ? 'bg-success'   :
                                    ($r['status'] === 'cancelled' ? 'bg-danger'   : 'bg-secondary')
                                ?>">
                                    <?= ucfirst($r['status']) ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="editRoute(<?= htmlspecialchars(json_encode($r)) ?>)">
                                    Edit
                                </button>
                                <a href="<?= BASE_URL ?>modules/transport/manage/seats.php?route_id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-info">Seats</a>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Cancel this route?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="action"   value="delete">
                                    <input type="hidden" name="route_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($routes)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-3">
                                No routes added yet.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Route Modal -->
<div class="modal fade" id="routeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="routeModalTitle">Add Route</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action"   value="add" id="routeAction">
                <input type="hidden" name="route_id" value=""    id="routeId">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Route Name (Optional)</label>
                            <input type="text" name="route_name" id="routeName"
                                   class="form-control" placeholder="e.g. Express 101">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Transport Type</label>
                            <select name="transport_type" id="transType" class="form-select">
                                <option value="bus">Bus</option>
                                <option value="train">Train</option>
                                <option value="ferry">Ferry</option>
                                <option value="cab">Cab</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="status" id="routeStatus" class="form-select">
                                <option value="active">Active</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From (Source) *</label>
                            <input type="text" name="source" id="routeSource"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">To (Destination) *</label>
                            <input type="text" name="destination" id="routeDest"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Journey Date *</label>
                            <input type="date" name="journey_date" id="routeDate"
                                   class="form-control"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Departure Time *</label>
                            <input type="time" name="departure_time" id="routeDep"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Arrival Time *</label>
                            <input type="time" name="arrival_time" id="routeArr"
                                   class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Base Fare (₹) *</label>
                            <input type="number" name="base_fare" id="routeFare"
                                   class="form-control" min="0" step="0.01" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Total Seats *</label>
                            <input type="number" name="total_seats" id="routeSeats"
                                   class="form-control" min="1" value="40" required>
                            <div class="form-text">
                                Seats will be auto-generated with class distribution.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Route</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editRoute(r) {
    document.getElementById('routeModalTitle').textContent = 'Edit Route';
    document.getElementById('routeAction').value = 'edit';
    document.getElementById('routeId').value     = r.id;
    document.getElementById('routeName').value   = r.route_name   || '';
    document.getElementById('routeSource').value = r.source       || '';
    document.getElementById('routeDest').value   = r.destination  || '';
    document.getElementById('routeDate').value   = r.journey_date || '';
    document.getElementById('routeDep').value    = r.departure_time || '';
    document.getElementById('routeArr').value    = r.arrival_time   || '';
    document.getElementById('routeFare').value   = r.base_fare    || '';
    document.getElementById('transType').value   = r.transport_type || 'bus';
    document.getElementById('routeStatus').value = r.status        || 'active';
    new bootstrap.Modal(document.getElementById('routeModal')).show();
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>