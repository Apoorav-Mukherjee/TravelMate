<?php
$page_title = 'Find Guides';
require_once __DIR__ . '/../../includes/header.php';

$city         = sanitize($_GET['city']         ?? '');
$language     = sanitize($_GET['language']     ?? '');
$specialization = sanitize($_GET['specialization'] ?? '');
$max_rate     = (float)($_GET['max_rate']      ?? 99999);
$date         = sanitize($_GET['date']         ?? '');
$page         = max(1, (int)($_GET['page']     ?? 1));
$per_page     = 9;
$offset       = ($page - 1) * $per_page;

$where  = ["g.status = 'active'", "g.is_verified = 1"];
$params = [];
$types  = '';

if ($city) {
    $where[]  = "g.city LIKE ?";
    $params[] = "%$city%";
    $types   .= 's';
}
if ($language) {
    $where[]  = "g.languages LIKE ?";
    $params[] = "%$language%";
    $types   .= 's';
}
if ($specialization) {
    $where[]  = "g.specializations LIKE ?";
    $params[] = "%$specialization%";
    $types   .= 's';
}
if ($max_rate < 99999) {
    $where[]  = "g.daily_rate <= ?";
    $params[] = $max_rate;
    $types   .= 'd';
}

// Exclude guides booked on selected date
if ($date) {
    $where[]  = "g.id NOT IN (
        SELECT bi.item_id FROM booking_items bi
        JOIN bookings b ON bi.booking_id = b.id
        WHERE bi.item_type = 'guide_session'
          AND b.booking_status NOT IN ('cancelled')
          AND b.check_in = ?
    )";
    $params[] = $date;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

// Count
$count_sql = "SELECT COUNT(*) as total FROM guides g WHERE $where_sql";
$stmt = $conn->prepare($count_sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// Fetch guides
$sql = "
    SELECT g.*, u.full_name, u.profile_picture, u.email,
           COALESCE(AVG(r.rating), 0) AS avg_rating,
           COUNT(DISTINCT r.id)       AS review_count
    FROM guides g
    JOIN users u  ON g.user_id = u.id
    LEFT JOIN reviews r ON r.entity_type = 'guide' AND r.entity_id = g.id AND r.is_approved = 1
    WHERE $where_sql
    GROUP BY g.id
    ORDER BY avg_rating DESC, g.experience_years DESC
    LIMIT ? OFFSET ?
";
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt = $conn->prepare($sql);
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$guides = $stmt->get_result();
$stmt->close();
?>

<?php if (is_logged_in()): ?>
<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">
<?php else: ?>
<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">✈️ TravelMate</a>
        <a href="<?= BASE_URL ?>auth/login.php" class="btn btn-outline-light btn-sm">Login</a>
    </div>
</nav>
<div class="container py-4">
<?php endif; ?>

    <!-- Search -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label fw-semibold">City</label>
                    <input type="text" name="city" class="form-control"
                           placeholder="e.g. Jaipur"
                           value="<?= htmlspecialchars($city) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Language</label>
                    <input type="text" name="language" class="form-control"
                           placeholder="e.g. Hindi, English"
                           value="<?= htmlspecialchars($language) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Specialization</label>
                    <input type="text" name="specialization" class="form-control"
                           placeholder="e.g. Heritage, Adventure"
                           value="<?= htmlspecialchars($specialization) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Max Daily Rate (₹)</label>
                    <input type="number" name="max_rate" class="form-control"
                           value="<?= $max_rate < 99999 ? $max_rate : '' ?>" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Available On</label>
                    <input type="date" name="date" class="form-control"
                           value="<?= htmlspecialchars($date) ?>"
                           min="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Results Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 text-muted">
            <?= $total ?> guide<?= $total != 1 ? 's' : '' ?> found
            <?= $city ? 'in <strong>' . htmlspecialchars($city) . '</strong>' : '' ?>
        </h6>
    </div>

    <!-- Guide Cards -->
    <div class="row g-4">
        <?php while ($guide = $guides->fetch_assoc()): ?>
        <?php
        $languages      = json_decode($guide['languages'], true)      ?? [];
        $specializations = json_decode($guide['specializations'], true) ?? [];
        ?>
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body text-center p-4">
                    <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($guide['profile_picture'] ?: 'default.png') ?>"
                         class="rounded-circle mb-3"
                         style="width:90px;height:90px;object-fit:cover;border:3px solid #0d6efd">

                    <h5 class="fw-bold mb-1">
                        <?= htmlspecialchars($guide['full_name']) ?>
                        <?php if ($guide['is_verified']): ?>
                        <i class="bi bi-patch-check-fill text-primary" title="Verified Guide"></i>
                        <?php endif; ?>
                    </h5>

                    <p class="text-muted small mb-2">
                        <i class="bi bi-geo-alt"></i>
                        <?= htmlspecialchars($guide['city'] ?? '') ?>
                        &bull; <?= $guide['experience_years'] ?> yrs experience
                    </p>

                    <!-- Rating -->
                    <div class="mb-2">
                        <?php
                        $avg = round($guide['avg_rating'], 1);
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= $avg
                                ? '<i class="bi bi-star-fill text-warning"></i>'
                                : '<i class="bi bi-star text-muted"></i>';
                        }
                        ?>
                        <small class="text-muted ms-1">(<?= $guide['review_count'] ?>)</small>
                    </div>

                    <!-- Languages -->
                    <?php if ($languages): ?>
                    <div class="mb-2">
                        <?php foreach (array_slice($languages, 0, 3) as $lang): ?>
                        <span class="badge bg-info text-dark"><?= htmlspecialchars($lang) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Specializations -->
                    <?php if ($specializations): ?>
                    <div class="mb-3">
                        <?php foreach (array_slice($specializations, 0, 3) as $spec): ?>
                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($spec) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Pricing -->
                    <div class="d-flex justify-content-center gap-3 mb-3">
                        <?php if ($guide['hourly_rate']): ?>
                        <div class="text-center">
                            <div class="fw-bold text-primary">₹<?= number_format($guide['hourly_rate'], 0) ?></div>
                            <div class="text-muted" style="font-size:11px">per hour</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($guide['daily_rate']): ?>
                        <div class="text-center">
                            <div class="fw-bold text-success">₹<?= number_format($guide['daily_rate'], 0) ?></div>
                            <div class="text-muted" style="font-size:11px">per day</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <a href="<?= BASE_URL ?>modules/guides/profile.php?id=<?= $guide['id'] ?><?= $date ? '&date=' . urlencode($date) : '' ?>"
                       class="btn btn-primary btn-sm w-100">View Profile</a>
                </div>
            </div>
        </div>
        <?php endwhile; ?>

        <?php if ($total === 0): ?>
        <div class="col-12 text-center py-5">
            <i class="bi bi-person-x display-1 text-muted"></i>
            <h5 class="mt-3 text-muted">No guides found</h5>
            <p class="text-muted">Try adjusting your filters</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
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