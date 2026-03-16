<?php
$page_title = 'Find Hotels';
require_once __DIR__ . '/../../includes/header.php';

$city      = sanitize($_GET['city']      ?? '');
$check_in  = sanitize($_GET['check_in']  ?? '');
$check_out = sanitize($_GET['check_out'] ?? '');
$min_price = (float)($_GET['min_price']  ?? 0);
// FIX 1: 0 means "no upper limit" — do NOT default to 99999
// because when the form submits max_price=0 it wipes out all results
$max_price = (float)($_GET['max_price']  ?? 0);
$rating    = (int)($_GET['rating']       ?? 0);
$page      = max(1, (int)($_GET['page']  ?? 1));
$per_page  = 9;
$offset    = ($page - 1) * $per_page;

// Build WHERE clause
$where  = ["h.is_approved = 1", "h.status = 'active'", "h.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($city) {
    $where[]  = "h.city LIKE ?";
    $params[] = "%$city%";
    $types   .= 's';
}
if ($rating > 0) {
    $where[]  = "h.star_rating >= ?";
    $params[] = $rating;
    $types   .= 'i';
}

$where_sql = implode(' AND ', $where);

// ── FIX 2: Build HAVING dynamically based on whether price filters are set ──
// Previously HAVING always ran even with defaults, silently hiding hotels
$having_parts = [];
$having_params = [];
$having_types  = '';

if ($min_price > 0) {
    $having_parts[]  = "(MIN(rm.base_price) >= ? OR MIN(rm.base_price) IS NULL)";
    $having_params[] = $min_price;
    $having_types   .= 'd';
}
if ($max_price > 0) {
    $having_parts[]  = "(MIN(rm.base_price) <= ? OR MIN(rm.base_price) IS NULL)";
    $having_params[] = $max_price;
    $having_types   .= 'd';
}

$having_sql = !empty($having_parts) ? 'HAVING ' . implode(' AND ', $having_parts) : '';

// ── Count total (must mirror the same HAVING logic) ───────────────────────
$count_sql = "
    SELECT COUNT(*) as total FROM (
        SELECT h.id
        FROM hotels h
        LEFT JOIN rooms rm ON rm.hotel_id = h.id AND rm.status = 'available'
        WHERE $where_sql
        GROUP BY h.id
        $having_sql
    ) AS counted
";
$count_params = array_merge($params, $having_params);
$count_types  = $types . $having_types;

$stmt = $conn->prepare($count_sql);
if ($count_params) $stmt->bind_param($count_types, ...$count_params);
$stmt->execute();
$total_hotels = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total_hotels / $per_page);

// ── Fetch hotels ──────────────────────────────────────────────────────────
$sql = "
    SELECT h.*,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(DISTINCT r.id)        AS review_count,
           MIN(rm.base_price)          AS min_price
    FROM hotels h
    LEFT JOIN reviews r  ON r.entity_type = 'hotel'
                         AND r.entity_id = h.id
                         AND r.is_approved = 1
    LEFT JOIN rooms rm   ON rm.hotel_id = h.id
                         AND rm.status = 'available'
    WHERE $where_sql
    GROUP BY h.id
    $having_sql
    ORDER BY h.is_featured DESC, avg_rating DESC
    LIMIT ? OFFSET ?
";

$all_params = array_merge($params, $having_params, [$per_page, $offset]);
$all_types  = $types . $having_types . 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$hotels = $stmt->get_result();
$stmt->close();
?>

<?php if (is_logged_in()): ?>
<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">
        <div class="topbar">
            <h5 class="mb-0">Find Hotels</h5>
        </div>
<?php else: ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">✈️ TravelMate</a>
        <div class="ms-auto">
            <a href="<?= BASE_URL ?>auth/login.php" class="btn btn-outline-light btn-sm">Login</a>
        </div>
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
                        <label class="form-label fw-semibold">City</label>
                        <input type="text" name="city" class="form-control"
                               placeholder="e.g. Goa, Manali"
                               value="<?= htmlspecialchars($city) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Check In</label>
                        <input type="date" name="check_in" class="form-control"
                               value="<?= htmlspecialchars($check_in) ?>"
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Check Out</label>
                        <input type="date" name="check_out" class="form-control"
                               value="<?= htmlspecialchars($check_out) ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Min ₹</label>
                        <input type="number" name="min_price" class="form-control"
                               value="<?= $min_price > 0 ? $min_price : '' ?>"
                               placeholder="0" min="0">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Max ₹</label>
                        <input type="number" name="max_price" class="form-control"
                               value="<?= $max_price > 0 ? $max_price : '' ?>"
                               placeholder="Any" min="0">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Stars</label>
                        <select name="rating" class="form-select">
                            <option value="0">Any</option>
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= $rating == $i ? 'selected' : '' ?>>
                                <?= $i ?>★
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <a href="<?= BASE_URL ?>modules/hotels/search.php"
                           class="btn btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results header -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0 text-muted">
                <?= $total_hotels ?> hotel<?= $total_hotels != 1 ? 's' : '' ?> found
                <?= $city ? ' in <strong>' . htmlspecialchars($city) . '</strong>' : '' ?>
            </h6>
        </div>

        <!-- Hotel cards -->
        <div class="row g-4">
            <?php while ($hotel = $hotels->fetch_assoc()): ?>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100" style="border-radius:12px;overflow:hidden">

                    <!-- Cover Image -->
                    <div style="height:200px;overflow:hidden;position:relative">
                        <img src="<?= BASE_URL ?>assets/uploads/hotels/<?= htmlspecialchars($hotel['cover_image'] ?: 'default.jpg') ?>"
                             class="w-100 h-100" style="object-fit:cover"
                             alt="<?= htmlspecialchars($hotel['name']) ?>">
                        <?php if ($hotel['is_featured']): ?>
                        <span class="badge bg-warning text-dark position-absolute top-0 start-0 m-2">
                            ⭐ Featured
                        </span>
                        <?php endif; ?>
                        <span class="badge bg-dark position-absolute top-0 end-0 m-2">
                            <?= $hotel['star_rating'] ?>★
                        </span>
                    </div>

                    <div class="card-body">
                        <h5 class="card-title fw-bold mb-1">
                            <?= htmlspecialchars($hotel['name']) ?>
                        </h5>
                        <p class="text-muted small mb-2">
                            <i class="bi bi-geo-alt"></i>
                            <?= htmlspecialchars($hotel['city']) ?>,
                            <?= htmlspecialchars($hotel['country']) ?>
                        </p>

                        <!-- Star rating -->
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div>
                                <?php
                                $avg = round($hotel['avg_rating'], 1);
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $avg
                                        ? '<i class="bi bi-star-fill text-warning"></i>'
                                        : '<i class="bi bi-star text-muted"></i>';
                                }
                                ?>
                            </div>
                            <span class="text-muted small">
                                (<?= $hotel['review_count'] ?> review<?= $hotel['review_count'] != 1 ? 's' : '' ?>)
                            </span>
                        </div>

                        <!-- Price & CTA -->
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if ($hotel['min_price']): ?>
                                <span class="text-muted small">From</span>
                                <span class="fs-5 fw-bold text-primary">
                                    ₹<?= number_format($hotel['min_price'], 0) ?>
                                </span>
                                <span class="text-muted small">/night</span>
                                <?php else: ?>
                                <span class="text-muted small">Price on request</span>
                                <?php endif; ?>
                            </div>
                            <a href="<?= BASE_URL ?>modules/hotels/detail.php?id=<?= $hotel['id'] ?><?= $check_in ? '&check_in=' . urlencode($check_in) . '&check_out=' . urlencode($check_out) : '' ?>"
                               class="btn btn-primary btn-sm">
                                View Hotel
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>

            <?php if ($total_hotels === 0): ?>
            <div class="col-12 text-center py-5">
                <i class="bi bi-building-x display-1 text-muted opacity-25"></i>
                <h5 class="mt-3 text-muted">No hotels found</h5>
                <p class="text-muted">Try adjusting your search filters</p>
                <a href="<?= BASE_URL ?>modules/hotels/search.php"
                   class="btn btn-outline-primary mt-2">Clear Filters</a>
            </div>
            <?php endif; ?>
        </div>

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