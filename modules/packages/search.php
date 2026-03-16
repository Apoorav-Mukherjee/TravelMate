<?php
$page_title = 'Tour Packages';
require_once __DIR__ . '/../../includes/header.php';

$search    = sanitize($_GET['search']    ?? '');
$city      = sanitize($_GET['city']      ?? '');
$duration  = (int)($_GET['duration']    ?? 0);
$max_price = (float)($_GET['max_price'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$per_page  = 9;
$offset    = ($page - 1) * $per_page;

// Build WHERE safely
$conditions = ["tp.status = 'active'"];
$params     = [];
$types      = '';

// Check if is_approved column exists before using it
$col_check = $conn->query("SHOW COLUMNS FROM tour_packages LIKE 'is_approved'");
if ($col_check && $col_check->num_rows > 0) {
    $conditions[] = "tp.is_approved = 1";
}

if ($search !== '') {
    $conditions[] = "(tp.title LIKE ? OR tp.description LIKE ?)";
    $params[]     = "%$search%";
    $params[]     = "%$search%";
    $types       .= 'ss';
}
if ($city !== '') {
    $conditions[] = "tp.city LIKE ?";
    $params[]     = "%$city%";
    $types       .= 's';
}
if ($duration > 0) {
    $conditions[] = "tp.duration_days = ?";
    $params[]     = $duration;
    $types       .= 'i';
}
if ($max_price > 0) {
    $conditions[] = "tp.fixed_price <= ?";
    $params[]     = $max_price;
    $types       .= 'd';
}

$where_sql = implode(' AND ', $conditions);

// Count total
$count_sql  = "SELECT COUNT(*) as total FROM tour_packages tp WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt === false) {
    $total = 0;
} else {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $total = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
}
$total_pages = $total > 0 ? ceil($total / $per_page) : 1;

// Fetch packages
$packages = [];
$main_sql = "
    SELECT tp.id, tp.title, tp.city, tp.duration_days,
           tp.fixed_price, tp.status,
           " . (($col_check && $col_check->num_rows > 0) ? "tp.is_featured, tp.discount_percent, tp.cover_image, tp.gallery, tp.highlights, tp.slug," : "0 as is_featured, 0 as discount_percent, NULL as cover_image, NULL as gallery, NULL as highlights, tp.id as slug,") . "
           COALESCE(AVG(r.rating), 0)  AS avg_rating,
           COUNT(DISTINCT r.id)        AS review_count
    FROM tour_packages tp
    LEFT JOIN reviews r ON r.entity_type = 'package'
                       AND r.entity_id   = tp.id
                       AND r.is_approved = 1
    WHERE $where_sql
    GROUP BY tp.id
    ORDER BY avg_rating DESC, tp.created_at DESC
    LIMIT ? OFFSET ?
";

$main_params = array_merge($params, [$per_page, $offset]);
$main_types  = $types . 'ii';
$main_stmt   = $conn->prepare($main_sql);

if ($main_stmt === false) {
    $packages = [];
} else {
    $main_stmt->bind_param($main_types, ...$main_params);
    $main_stmt->execute();
    $packages = $main_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $main_stmt->close();
}
?>

<?php if (is_logged_in()): ?>
<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">
<?php else: ?>
<!-- Guest navbar -->
<nav class="navbar navbar-dark"
     style="background:linear-gradient(135deg,#1a1f3a,#0d6efd)">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">✈️ TravelMate</a>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>auth/login.php"
               class="btn btn-outline-light btn-sm">Login</a>
            <a href="<?= BASE_URL ?>auth/register.php"
               class="btn btn-light btn-sm fw-bold">Register</a>
        </div>
    </div>
</nav>
<div class="container py-4">
<?php endif; ?>

    <!-- Search Hero -->
    <div class="card border-0 shadow-sm mb-4"
         style="background:linear-gradient(135deg,#1a1f3a,#0d6efd);border-radius:16px">
        <div class="card-body p-4">
            <h4 class="text-white fw-bold mb-3">
                <i class="bi bi-map"></i> Find Your Perfect Package
            </h4>
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search packages..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" name="city" class="form-control"
                           placeholder="Destination"
                           value="<?= htmlspecialchars($city) ?>">
                </div>
                <div class="col-md-2">
                    <select name="duration" class="form-select">
                        <option value="">Any Duration</option>
                        <?php foreach ([1,2,3,5,7,10,14] as $d): ?>
                        <option value="<?= $d ?>"
                                <?= $duration === $d ? 'selected' : '' ?>>
                            <?= $d ?> Day<?= $d > 1 ? 's' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="number" name="max_price" class="form-control"
                           placeholder="Max price per person (₹)"
                           value="<?= $max_price > 0 ? $max_price : '' ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-light w-100 fw-bold">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Results count -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-muted">
            <?= $total ?> package<?= $total != 1 ? 's' : '' ?> found
        </h6>
        <a href="<?= BASE_URL ?>modules/packages/search.php"
           class="btn btn-sm btn-outline-secondary">
            Clear Filters
        </a>
    </div>

    <!-- Package Cards -->
    <div class="row g-4">
        <?php if (empty($packages)): ?>
        <div class="col-12 text-center py-5">
            <i class="bi bi-map display-1 text-muted"></i>
            <h5 class="mt-3 text-muted">No packages found</h5>
            <p class="text-muted">Try adjusting your filters or check back later.</p>
            <a href="?" class="btn btn-primary">Show All Packages</a>
        </div>
        <?php endif; ?>

        <?php foreach ($packages as $pkg):
            $gallery    = json_decode($pkg['gallery']    ?? '[]', true) ?? [];
            $highlights = json_decode($pkg['highlights'] ?? '[]', true) ?? [];
            $cover_img  = $pkg['cover_image'] ?: ($gallery[0] ?? null);
            $discount   = (float)($pkg['discount_percent'] ?? 0);
            $discounted = $discount > 0
                ? round($pkg['fixed_price'] * (1 - $discount / 100), 0)
                : null;
            $slug       = $pkg['slug'] ?: $pkg['id'];
        ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 stat-card"
                 style="border-radius:14px;overflow:hidden">

                <!-- Cover image -->
                <div style="position:relative;height:200px;overflow:hidden">
                    <?php if ($cover_img): ?>
                    <img src="<?= BASE_URL ?>assets/uploads/packages/<?= htmlspecialchars($cover_img) ?>"
                         style="width:100%;height:100%;object-fit:cover"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div style="display:none;width:100%;height:100%;
                                background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                                align-items:center;justify-content:center;
                                font-size:4rem;opacity:0.4">🗺️</div>
                    <?php else: ?>
                    <div style="width:100%;height:100%;
                                background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                                display:flex;align-items:center;justify-content:center">
                        <span style="font-size:4rem;opacity:0.4">🗺️</span>
                    </div>
                    <?php endif; ?>

                    <!-- Badges -->
                    <div style="position:absolute;top:10px;left:10px">
                        <?php if (!empty($pkg['is_featured'])): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="bi bi-star-fill"></i> Featured
                        </span>
                        <?php endif; ?>
                        <?php if ($discount > 0): ?>
                        <span class="badge bg-danger ms-1">
                            <?= $discount ?>% OFF
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Duration badge -->
                    <div style="position:absolute;bottom:10px;right:10px">
                        <span class="badge bg-dark bg-opacity-75">
                            <i class="bi bi-calendar3"></i>
                            <?= $pkg['duration_days'] ?>
                            Day<?= $pkg['duration_days'] > 1 ? 's' : '' ?>
                        </span>
                    </div>
                </div>

                <div class="card-body p-3 d-flex flex-column">
                    <h6 class="fw-bold mb-1">
                        <?= htmlspecialchars($pkg['title']) ?>
                    </h6>
                    <div class="text-muted small mb-2">
                        <i class="bi bi-geo-alt text-danger"></i>
                        <?= htmlspecialchars($pkg['city'] ?? 'Various') ?>
                    </div>

                    <!-- Stars -->
                    <div class="d-flex align-items-center gap-1 mb-2">
                        <?php $avg = round((float)$pkg['avg_rating'], 1);
                        for ($i = 1; $i <= 5; $i++): ?>
                        <i class="bi <?= $i <= $avg
                            ? 'bi-star-fill text-warning'
                            : 'bi-star text-muted' ?>"
                           style="font-size:12px"></i>
                        <?php endfor; ?>
                        <small class="text-muted ms-1">
                            (<?= (int)$pkg['review_count'] ?>)
                        </small>
                    </div>

                    <!-- Highlights -->
                    <?php if (!empty($highlights)): ?>
                    <div class="mb-2">
                        <?php foreach (array_slice($highlights, 0, 3) as $h): ?>
                        <span class="badge bg-light text-dark border me-1 mb-1"
                              style="font-size:10px">
                            ✓ <?= htmlspecialchars($h) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Price + CTA -->
                    <div class="d-flex justify-content-between align-items-center mt-auto">
                        <div>
                            <?php if ($discounted): ?>
                            <div class="text-muted small text-decoration-line-through">
                                ₹<?= number_format($pkg['fixed_price'], 0) ?>
                            </div>
                            <div class="fs-5 fw-bold text-primary">
                                ₹<?= number_format($discounted, 0) ?>
                            </div>
                            <?php else: ?>
                            <div class="fs-5 fw-bold text-primary">
                                ₹<?= number_format($pkg['fixed_price'], 0) ?>
                            </div>
                            <?php endif; ?>
                            <div class="text-muted" style="font-size:10px">per person</div>
                        </div>
                        <a href="<?= BASE_URL ?>modules/packages/detail.php?slug=<?= urlencode($slug) ?>"
                           class="btn btn-primary btn-sm">
                            View Details
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
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

<?php if (is_logged_in()): ?>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>