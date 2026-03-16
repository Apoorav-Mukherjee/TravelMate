<?php
$page_title = 'Manage Bookings';
require_once __DIR__ . '/../../../includes/header.php';
require_role('transport_provider');

$user_id = $_SESSION['user_id'];

// Fetch provider
$stmt = $conn->prepare("SELECT * FROM transport_providers WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    set_flash('error', 'Your provider profile was not found.');
    redirect('dashboards/transport_provider/index.php');
}

$provider_id = $provider['id'];

// Filters
$filter_status = sanitize($_GET['status'] ?? '');
$filter_route  = (int)($_GET['route_id'] ?? 0);
$search        = sanitize($_GET['search'] ?? '');

// Pagination
$per_page    = 15;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// Build WHERE
$where   = "WHERE r.provider_id = ?";
$types   = "i";
$params  = [$provider_id];

if ($filter_status !== '') {
    $where  .= " AND b.booking_status = ?";
    $types  .= "s";
    $params[] = $filter_status;
}
if ($filter_route > 0) {
    $where  .= " AND r.id = ?";
    $types  .= "i";
    $params[] = $filter_route;
}
if ($search !== '') {
    $where  .= " AND (b.booking_ref LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $types  .= "sss";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// Count query
$count_sql = "
    SELECT COUNT(DISTINCT b.id)
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'seat'
    JOIN transport_seats  ts ON ts.id = bi.item_id
    JOIN transport_routes r  ON r.id  = ts.route_id
    JOIN users u             ON u.id  = b.user_id
    $where
    AND b.notes != '[__deleted__]'
";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_row()[0];
$stmt->close();

$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch query
$fetch_sql = "
    SELECT
        b.id,
        b.booking_ref,
        b.booking_status,
        b.payment_status,
        b.final_amount,
        b.created_at,
        u.full_name,
        u.email,
        u.phone,
        r.id          AS route_id,
        r.source,
        r.destination,
        r.journey_date,
        r.departure_time,
        ts.seat_number,
        ts.seat_class,
        bi.unit_price AS seat_price
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'seat'
    JOIN transport_seats  ts ON ts.id = bi.item_id
    JOIN transport_routes r  ON r.id  = ts.route_id
    JOIN users u             ON u.id  = b.user_id
    $where
    AND b.notes != '[__deleted__]'
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
";
$types_fetch   = $types . "ii";
$params_fetch  = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($fetch_sql);
$stmt->bind_param($types_fetch, ...$params_fetch);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Routes dropdown for filter
$stmt = $conn->prepare("
    SELECT id, route_name, source, destination, journey_date
    FROM transport_routes
    WHERE provider_id = ?
    ORDER BY journey_date DESC
");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$routes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary stats
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT b.id)                                                              AS total,
        SUM(CASE WHEN b.booking_status = 'confirmed'  THEN 1 ELSE 0 END)                 AS confirmed,
        SUM(CASE WHEN b.booking_status = 'pending'    THEN 1 ELSE 0 END)                 AS pending,
        SUM(CASE WHEN b.booking_status = 'cancelled'  THEN 1 ELSE 0 END)                 AS cancelled,
        COALESCE(SUM(CASE WHEN b.booking_status != 'cancelled' THEN b.final_amount END), 0) AS revenue
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'seat'
    JOIN transport_seats  ts ON ts.id = bi.item_id
    JOIN transport_routes r  ON r.id  = ts.route_id
    WHERE r.provider_id = ?
    AND b.notes != '[__deleted__]'
");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$status_badge = [
    'pending'   => 'bg-warning text-dark',
    'confirmed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'completed' => 'bg-secondary',
];
$payment_badge = [
    'pending'  => 'bg-warning text-dark',
    'paid'     => 'bg-success',
    'failed'   => 'bg-danger',
    'refunded' => 'bg-info',
];
$class_label = [
    'general'     => 'General',
    'sleeper'     => 'Sleeper',
    'ac'          => 'AC',
    'first_class' => '1st Class',
];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/transport_provider/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0"><i class="bi bi-ticket-perforated me-2"></i>Manage Bookings</h5>
        </div>

        <!-- Summary Stats -->
        <div class="row g-3 px-3 pt-3">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-journals fs-4 text-primary"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['total'] ?></div>
                            <div class="text-muted small">Total Bookings</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-check-circle fs-4 text-success"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['confirmed'] ?></div>
                            <div class="text-muted small">Confirmed</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['pending'] ?></div>
                            <div class="text-muted small">Pending</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-currency-rupee fs-4 text-info"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold">₹<?= number_format((float)$summary['revenue'], 0) ?></div>
                            <div class="text-muted small">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="px-3 pt-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Ref, name or email"
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Route</label>
                            <select name="route_id" class="form-select form-select-sm">
                                <option value="0">All Routes</option>
                                <?php foreach ($routes as $rt): ?>
                                    <option value="<?= $rt['id'] ?>" <?= $filter_route === (int)$rt['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($rt['source'] . ' → ' . $rt['destination']) ?>
                                        (<?= date('d M', strtotime($rt['journey_date'])) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <?php foreach (['pending', 'confirmed', 'cancelled', 'completed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                                        <?= ucfirst($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search me-1"></i>Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= BASE_URL ?>modules/transport/manage/bookings.php"
                               class="btn btn-outline-secondary btn-sm w-100">
                                <i class="bi bi-x-circle me-1"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bookings Table -->
        <div class="px-3 pt-3 pb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Booking Ref</th>
                                <th>Passenger</th>
                                <th>Route</th>
                                <th>Journey Date</th>
                                <th>Seat</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Booked On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        No bookings found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td>
                                        <a href="<?= BASE_URL ?>modules/bookings/view.php?ref=<?= urlencode($b['booking_ref']) ?>"
                                           class="fw-semibold text-decoration-none">
                                            <?= htmlspecialchars($b['booking_ref']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($b['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($b['email']) ?></small>
                                        <?php if ($b['phone']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($b['phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($b['source']) ?></strong>
                                        <i class="bi bi-arrow-right text-muted mx-1"></i>
                                        <?= htmlspecialchars($b['destination']) ?>
                                    </td>
                                    <td>
                                        <?= date('d M Y', strtotime($b['journey_date'])) ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= date('H:i', strtotime($b['departure_time'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($b['seat_number']) ?></span>
                                        <br>
                                        <small class="text-muted">
                                            <?= $class_label[$b['seat_class']] ?? ucfirst($b['seat_class']) ?>
                                        </small>
                                    </td>
                                    <td>₹<?= number_format((float)$b['final_amount'], 0) ?></td>
                                    <td>
                                        <span class="badge <?= $payment_badge[$b['payment_status']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($b['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $status_badge[$b['booking_status']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($b['booking_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= date('d M Y', strtotime($b['created_at'])) ?></small>
                                        <br>
                                        <small class="text-muted"><?= date('h:i A', strtotime($b['created_at'])) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2 px-3">
                    <small class="text-muted">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?> bookings
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page - 1 ?>&status=<?= urlencode($filter_status) ?>&route_id=<?= $filter_route ?>&search=<?= urlencode($search) ?>">
                                        &laquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php for ($p = max(1, $current_page - 2); $p <= min($total_pages, $current_page + 2); $p++): ?>
                                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $p ?>&status=<?= urlencode($filter_status) ?>&route_id=<?= $filter_route ?>&search=<?= urlencode($search) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page + 1 ?>&status=<?= urlencode($filter_status) ?>&route_id=<?= $filter_route ?>&search=<?= urlencode($search) ?>">
                                        &raquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div><!-- /main-content -->
</div><!-- /d-flex -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>