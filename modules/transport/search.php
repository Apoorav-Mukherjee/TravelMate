<?php
$page_title = 'Search Transport';
require_once __DIR__ . '/../../includes/header.php';

$source      = sanitize($_GET['source']      ?? '');
$destination = sanitize($_GET['destination'] ?? '');
$date        = sanitize($_GET['date']        ?? '');
$type        = sanitize($_GET['type']        ?? '');
$page        = max(1, (int)($_GET['page']    ?? 1));
$per_page    = 10;
$offset      = ($page - 1) * $per_page;

// FIX 1: Use r.status != 'cancelled' instead of r.status = 'active'
//         (schema doesn't guarantee 'active' is the value used)
$where  = ["r.status != 'cancelled'", "tp.is_approved = 1"];
$params = [];
$types  = '';

// Smart search: if only source filled, search both source AND destination
// so "Agra" finds both "Agra → X" and "X → Agra" routes
if ($source && !$destination) {
    $where[]  = "(r.source LIKE ? OR r.destination LIKE ?)";
    $params[] = "%$source%";
    $params[] = "%$source%";
    $types   .= 'ss';
} elseif ($source) {
    $where[]  = "r.source LIKE ?";
    $params[] = "%$source%";
    $types   .= 's';
}
if ($destination) {
    $where[]  = "r.destination LIKE ?";
    $params[] = "%$destination%";
    $types   .= 's';
}
if ($date) {
    $where[]  = "r.journey_date = ?";
    $params[] = $date;
    $types   .= 's';
}
if ($type) {
    $where[]  = "r.transport_type = ?";
    $params[] = $type;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

// Count
$count_sql = "
    SELECT COUNT(*) as total
    FROM transport_routes r
    JOIN transport_providers tp ON r.provider_id = tp.id
    WHERE $where_sql
";
$stmt = $conn->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// FIX 2: Use r.available_seats directly from transport_routes column
//         instead of a subquery on transport_seats (which may be empty)
$sql = "
    SELECT r.*,
           tp.company_name,
           tp.transport_type AS provider_type,
           u.full_name       AS provider_name
    FROM transport_routes r
    JOIN transport_providers tp ON r.provider_id = tp.id
    JOIN users u ON tp.user_id = u.id
    WHERE $where_sql
    ORDER BY r.journey_date ASC, r.departure_time ASC
    LIMIT ? OFFSET ?
";
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt = $conn->prepare($sql);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$routes = $stmt->get_result();
$stmt->close();

$type_icons = [
    'bus'   => 'bi-bus-front',
    'train' => 'bi-train-front',
    'ferry' => 'bi-water',
    'cab'   => 'bi-taxi-front',
];
?>

<?php if (is_logged_in()): ?>
<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">
        <div class="topbar">
            <h5 class="mb-0">Search Transport</h5>
        </div>
<?php else: ?>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">✈️ TravelMate</a>
        <a href="<?= BASE_URL ?>auth/login.php" class="btn btn-outline-light btn-sm">Login</a>
    </div>
</nav>
<div class="container py-4">
<?php endif; ?>

    <div class="p-3 p-md-4">

        <!-- Search Bar -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">From</label>
                        <input type="text" name="source" class="form-control"
                               placeholder="e.g. Delhi"
                               value="<?= htmlspecialchars($source) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">To</label>
                        <input type="text" name="destination" class="form-control"
                               placeholder="e.g. Mumbai"
                               value="<?= htmlspecialchars($destination) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Journey Date</label>
                        <input type="date" name="date" class="form-control"
                               value="<?= htmlspecialchars($date) ?>"
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <option value="bus"   <?= $type==='bus'   ? 'selected':'' ?>>🚌 Bus</option>
                            <option value="train" <?= $type==='train' ? 'selected':'' ?>>🚆 Train</option>
                            <option value="ferry" <?= $type==='ferry' ? 'selected':'' ?>>⛴ Ferry</option>
                            <option value="cab"   <?= $type==='cab'   ? 'selected':'' ?>>🚕 Cab</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <a href="<?= BASE_URL ?>modules/transport/search.php"
                           class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results count -->
        <div class="mb-3">
            <span class="text-muted">
                <?= $total ?> route<?= $total != 1 ? 's' : '' ?> found
                <?php if ($source || $destination || $date || $type): ?>
                <a href="<?= BASE_URL ?>modules/transport/search.php"
                   class="ms-2 small text-danger text-decoration-none">
                    <i class="bi bi-x-circle me-1"></i>Clear filters
                </a>
                <?php endif; ?>
            </span>
        </div>

        <!-- No results -->
        <?php if ($total === 0): ?>
        <div class="text-center py-5">
            <i class="bi bi-train-front display-1 text-muted opacity-25"></i>
            <h5 class="mt-3 text-muted">No routes found</h5>
            <p class="text-muted">Try changing your search criteria or clear the filters</p>
        </div>
        <?php endif; ?>

        <!-- Route cards -->
        <?php while ($route = $routes->fetch_assoc()):
            // FIX 2: Use available_seats column directly from transport_routes
            $seats_left = (int)$route['available_seats'];
        ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row align-items-center g-3">

                    <!-- Provider & Type -->
                    <div class="col-6 col-md-2 text-center">
                        <i class="bi <?= $type_icons[$route['transport_type']] ?? 'bi-truck' ?> display-6 text-primary"></i>
                        <div class="small fw-semibold mt-1">
                            <?= htmlspecialchars($route['company_name']) ?>
                        </div>
                        <span class="badge bg-light text-dark border">
                            <?= ucfirst($route['transport_type']) ?>
                        </span>
                    </div>

                    <!-- Route & Timing -->
                    <div class="col-12 col-md-5">
                        <div class="d-flex align-items-center gap-3">
                            <div class="text-center">
                                <div class="fs-4 fw-bold">
                                    <?= date('H:i', strtotime($route['departure_time'])) ?>
                                </div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($route['source']) ?>
                                </div>
                            </div>
                            <div class="flex-grow-1 text-center">
                                <?php
                                $dep  = strtotime($route['departure_time']);
                                $arr  = strtotime($route['arrival_time']);
                                $diff = $arr - $dep;
                                if ($diff < 0) $diff += 86400;
                                $hours   = floor($diff / 3600);
                                $minutes = floor(($diff % 3600) / 60);
                                ?>
                                <div class="text-muted small mb-1"><?= "{$hours}h {$minutes}m" ?></div>
                                <div style="border-top:2px dashed #dee2e6;position:relative">
                                    <i class="bi bi-arrow-right-circle-fill text-primary"
                                       style="position:absolute;top:-11px;right:-5px"></i>
                                </div>
                                <div class="text-muted small mt-1">
                                    <?= date('d M Y', strtotime($route['journey_date'])) ?>
                                </div>
                            </div>
                            <div class="text-center">
                                <div class="fs-4 fw-bold">
                                    <?= date('H:i', strtotime($route['arrival_time'])) ?>
                                </div>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($route['destination']) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Seats & Price -->
                    <div class="col-6 col-md-3 text-center">
                        <div class="fs-4 fw-bold text-primary">
                            ₹<?= number_format($route['base_fare'], 0) ?>
                        </div>
                        <div class="text-muted small">per seat</div>
                        <div class="mt-1">
                            <?php if ($seats_left > 0): ?>
                            <span class="badge bg-success">
                                <?= $seats_left ?> seat<?= $seats_left != 1 ? 's' : '' ?> available
                            </span>
                            <?php else: ?>
                            <span class="badge bg-danger">Fully Booked</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Book Button -->
                    <div class="col-12 col-md-2 text-end">
                        <?php if ($seats_left > 0): ?>
                            <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                            <a href="<?= BASE_URL ?>modules/transport/select_seats.php?route_id=<?= $route['id'] ?>"
                               class="btn btn-primary w-100">
                                <i class="bi bi-ticket-perforated me-1"></i>Select Seats
                            </a>
                            <?php else: ?>
                            <a href="<?= BASE_URL ?>auth/login.php"
                               class="btn btn-outline-primary w-100">Login to Book</a>
                            <?php endif; ?>
                        <?php else: ?>
                        <button class="btn btn-secondary w-100" disabled>Sold Out</button>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
        <?php endwhile; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div><!-- /p-4 -->

<?php if (is_logged_in()): ?>
    </div><!-- /main-content -->
</div><!-- /d-flex -->
<?php else: ?>
</div><!-- /container -->
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>