<?php
$page_title = 'Package Details';
require_once __DIR__ . '/../../includes/header.php';

$slug = sanitize($_GET['slug'] ?? '');
if (!$slug) redirect('modules/packages/search.php');

// Fetch by slug or ID
$slug_clean = $conn->real_escape_string($slug);
$pkg = null;

$r = $conn->query("
    SELECT tp.*, u.full_name AS creator_name
    FROM tour_packages tp
    JOIN users u ON tp.admin_id = u.id
    WHERE (tp.slug = '$slug_clean' OR tp.id = " . (int)$slug . ")
      AND tp.is_approved = 1
    LIMIT 1
");
if ($r) $pkg = $r->fetch_assoc();

if (!$pkg) {
    set_flash('error', 'Package not found.');
    redirect('modules/packages/search.php');
}

$pkg_id       = $pkg['id'];
$highlights   = json_decode($pkg['highlights']  ?? '[]', true) ?? [];
$inclusions   = json_decode($pkg['inclusions']  ?? '[]', true) ?? [];
$exclusions   = json_decode($pkg['exclusions']  ?? '[]', true) ?? [];
$itinerary    = json_decode($pkg['itinerary']   ?? '[]', true) ?? [];
$gallery      = json_decode($pkg['gallery']     ?? '[]', true) ?? [];
$discounted   = $pkg['discount_percent'] > 0
    ? round($pkg['fixed_price'] * (1 - $pkg['discount_percent'] / 100), 2)
    : null;
$display_price = $discounted ?? $pkg['fixed_price'];

// Fetch components
$stmt = $conn->prepare("
    SELECT pc.*,
        CASE pc.component_type
            WHEN 'hotel'     THEN (SELECT name FROM hotels WHERE id = pc.component_id)
            WHEN 'guide'     THEN (SELECT u2.full_name FROM guides g JOIN users u2 ON g.user_id = u2.id WHERE g.id = pc.component_id)
            WHEN 'transport' THEN (SELECT CONCAT(source,' → ',destination) FROM transport_routes WHERE id = pc.component_id)
        END AS component_name,
        CASE pc.component_type
            WHEN 'hotel'     THEN (SELECT city FROM hotels WHERE id = pc.component_id)
            WHEN 'guide'     THEN (SELECT city FROM guides WHERE id = pc.component_id)
            WHEN 'transport' THEN (SELECT source FROM transport_routes WHERE id = pc.component_id)
        END AS component_location
    FROM package_components pc
    WHERE pc.package_id = ?
    ORDER BY pc.day_number ASC
");
$stmt->bind_param('i', $pkg_id);
$stmt->execute();
$components = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch reviews
$stmt = $conn->prepare("
    SELECT r.*, u.full_name, u.profile_picture
    FROM reviews r JOIN users u ON r.user_id = u.id
    WHERE r.entity_type = 'package' AND r.entity_id = ? AND r.is_approved = 1
    ORDER BY r.created_at DESC LIMIT 8
");
$stmt->bind_param('i', $pkg_id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Avg rating
$stmt = $conn->prepare("
    SELECT COALESCE(AVG(rating),0) AS avg, COUNT(*) AS total
    FROM reviews WHERE entity_type='package' AND entity_id=? AND is_approved=1
");
$stmt->bind_param('i', $pkg_id);
$stmt->execute();
$rating = $stmt->get_result()->fetch_assoc();
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

    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?= BASE_URL ?>modules/packages/search.php">Packages</a>
            </li>
            <li class="breadcrumb-item active">
                <?= htmlspecialchars($pkg['title']) ?>
            </li>
        </ol>
    </nav>

    <div class="row g-4">

        <!-- Left: Details -->
        <div class="col-lg-8">

            <!-- Gallery -->
            <?php if ($gallery || $pkg['cover_image']): ?>
            <div class="mb-4" style="border-radius:14px;overflow:hidden;height:320px">
                <?php $main = $pkg['cover_image'] ?: ($gallery[0] ?? null); ?>
                <?php if ($main): ?>
                <img src="<?= BASE_URL ?>assets/uploads/packages/<?= htmlspecialchars($main) ?>"
                     style="width:100%;height:100%;object-fit:cover"
                     id="mainGalleryImg">
                <?php endif; ?>
            </div>
            <?php if (count($gallery) > 1): ?>
            <div class="d-flex gap-2 mb-4 overflow-auto">
                <?php foreach ($gallery as $img): ?>
                <img src="<?= BASE_URL ?>assets/uploads/packages/<?= htmlspecialchars($img) ?>"
                     style="width:80px;height:60px;object-fit:cover;border-radius:8px;
                            cursor:pointer;border:2px solid transparent"
                     onclick="document.getElementById('mainGalleryImg').src = this.src;
                              document.querySelectorAll('.thumb-active').forEach(e => e.classList.remove('thumb-active'));
                              this.style.borderColor='#0d6efd'">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Header -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h3 class="fw-bold mb-1">
                                <?= htmlspecialchars($pkg['title']) ?>
                                <?php if ($pkg['is_featured']): ?>
                                <span class="badge bg-warning text-dark ms-2">
                                    <i class="bi bi-star-fill"></i> Featured
                                </span>
                                <?php endif; ?>
                            </h3>
                            <p class="text-muted mb-2">
                                <i class="bi bi-geo-alt-fill text-danger"></i>
                                <?= htmlspecialchars($pkg['destination'] ?? '') ?>
                                &bull;
                                <i class="bi bi-calendar3"></i>
                                <?= $pkg['duration_days'] ?> Days /
                                <?= $pkg['duration_nights'] ?? ($pkg['duration_days'] - 1) ?> Nights
                                &bull;
                                <i class="bi bi-people"></i>
                                Max <?= $pkg['max_persons'] ?> persons
                            </p>
                            <div class="d-flex align-items-center gap-2">
                                <?php $avg = round($rating['avg'], 1);
                                for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi <?= $i <= $avg
                                    ? 'bi-star-fill text-warning'
                                    : 'bi-star text-muted' ?> fs-5"></i>
                                <?php endfor; ?>
                                <span class="fw-bold"><?= number_format($avg, 1) ?></span>
                                <span class="text-muted">(<?= $rating['total'] ?> reviews)</span>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <p class="text-muted">
                        <?= nl2br(htmlspecialchars($pkg['description'] ?? '')) ?>
                    </p>

                    <!-- Highlights -->
                    <?php if ($highlights): ?>
                    <h6 class="fw-bold">Highlights</h6>
                    <div class="row g-2">
                        <?php foreach ($highlights as $h): ?>
                        <div class="col-md-6">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span class="small"><?= htmlspecialchars($h) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Itinerary -->
            <?php if ($itinerary): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold">
                    <i class="bi bi-calendar-week text-primary"></i> Day-by-Day Itinerary
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <?php foreach ($itinerary as $idx => $day): ?>
                        <div class="d-flex gap-3 mb-4">
                            <!-- Day marker -->
                            <div class="flex-shrink-0 text-center">
                                <div class="rounded-circle bg-primary text-white fw-bold d-flex
                                            align-items-center justify-content-center"
                                     style="width:42px;height:42px;font-size:14px">
                                    D<?= $idx + 1 ?>
                                </div>
                                <?php if ($idx < count($itinerary) - 1): ?>
                                <div style="width:2px;height:30px;background:#dee2e6;
                                            margin:4px auto"></div>
                                <?php endif; ?>
                            </div>
                            <!-- Content -->
                            <div class="flex-grow-1">
                                <h6 class="fw-bold mb-1">
                                    <?= htmlspecialchars($day['title'] ?? "Day " . ($idx + 1)) ?>
                                </h6>
                                <p class="text-muted small mb-1">
                                    <?= nl2br(htmlspecialchars($day['description'] ?? '')) ?>
                                </p>
                                <?php if (!empty($day['activities'])): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($day['activities'] as $act): ?>
                                    <span class="badge bg-light text-dark border" style="font-size:10px">
                                        <?= htmlspecialchars($act) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($day['meals'])): ?>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        🍽 <?= implode(' | ', array_map('htmlspecialchars', $day['meals'])) ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Components -->
            <?php if ($components): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold">
                    <i class="bi bi-puzzle text-primary"></i> Package Includes
                </div>
                <div class="card-body p-0">
                    <?php
                    $type_icons = [
                        'hotel'     => ['bi-building',    'text-primary', 'Hotel Accommodation'],
                        'guide'     => ['bi-person-badge','text-success', 'Guide Service'],
                        'transport' => ['bi-bus-front',   'text-warning', 'Transport'],
                    ];
                    foreach ($components as $comp):
                        [$icon, $color, $label] = $type_icons[$comp['component_type']] ?? ['bi-box','text-muted','Service'];
                    ?>
                    <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                        <div class="rounded-circle bg-light d-flex align-items-center
                                    justify-content-center flex-shrink-0"
                             style="width:44px;height:44px">
                            <i class="bi <?= $icon ?> <?= $color ?> fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold small"><?= $label ?> — Day <?= $comp['day_number'] ?></div>
                            <div class="text-muted" style="font-size:12px">
                                <?= htmlspecialchars($comp['component_name'] ?? '') ?>
                                <?php if ($comp['component_location']): ?>
                                <span class="ms-1">
                                    <i class="bi bi-geo-alt text-danger" style="font-size:10px"></i>
                                    <?= htmlspecialchars($comp['component_location']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($comp['notes']): ?>
                            <div class="text-muted mt-1" style="font-size:11px">
                                <?= htmlspecialchars($comp['notes']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-light text-dark border">
                            <?= ucfirst($comp['component_type']) ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Inclusions / Exclusions -->
            <div class="row g-4 mb-4">
                <?php if ($inclusions): ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-bold text-success">
                            <i class="bi bi-check-circle-fill"></i> Inclusions
                        </div>
                        <div class="card-body">
                            <?php foreach ($inclusions as $item): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-check2 text-success"></i>
                                <span class="small"><?= htmlspecialchars($item) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($exclusions): ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-bold text-danger">
                            <i class="bi bi-x-circle-fill"></i> Exclusions
                        </div>
                        <div class="card-body">
                            <?php foreach ($exclusions as $item): ?>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <i class="bi bi-x text-danger"></i>
                                <span class="small"><?= htmlspecialchars($item) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Reviews -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">Reviews</div>
                <div class="card-body">
                    <?php if (empty($reviews)): ?>
                    <p class="text-muted">No reviews yet. Be the first!</p>
                    <?php endif; ?>
                    <?php foreach ($reviews as $rev): ?>
                    <div class="d-flex gap-3 mb-4">
                        <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($rev['profile_picture'] ?? 'default.png') ?>"
                             class="rounded-circle" width="44" height="44"
                             style="object-fit:cover;flex-shrink:0">
                        <div>
                            <div class="d-flex gap-2 align-items-center">
                                <strong><?= htmlspecialchars($rev['full_name']) ?></strong>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($rev['created_at'])) ?>
                                </small>
                            </div>
                            <div class="mb-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="bi <?= $i <= $rev['rating']
                                    ? 'bi-star-fill text-warning'
                                    : 'bi-star text-muted' ?>"></i>
                                <?php endfor; ?>
                            </div>
                            <p class="mb-0 text-muted small">
                                <?= nl2br(htmlspecialchars($rev['review_text'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                    <hr>
                    <h6 class="fw-bold">Write a Review</h6>
                    <form method="POST"
                          action="<?= BASE_URL ?>modules/packages/submit_review.php">
                        <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
                        <input type="hidden" name="package_id"  value="<?= $pkg_id ?>">
                        <div class="mb-2">
                            <select name="rating" class="form-select" style="max-width:180px" required>
                                <option value="">Rating</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>"><?= $i ?> Star<?= $i > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <textarea name="review_text" class="form-control" rows="3"
                                      placeholder="Share your experience..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm">
                            Submit Review
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right: Booking Panel -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:20px">
                <div class="card-header bg-primary text-white fw-bold">
                    Book This Package
                </div>
                <div class="card-body">
                    <!-- Price -->
                    <div class="text-center mb-4">
                        <?php if ($discounted): ?>
                        <div class="text-muted text-decoration-line-through small">
                            ₹<?= number_format($pkg['fixed_price'], 0) ?> / person
                        </div>
                        <div class="display-6 fw-bold text-primary">
                            ₹<?= number_format($discounted, 0) ?>
                        </div>
                        <?php else: ?>
                        <div class="display-6 fw-bold text-primary">
                            ₹<?= number_format($pkg['fixed_price'], 0) ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-muted small">per person</div>
                    </div>

                    <!-- Validity -->
                    <?php if ($pkg['valid_from'] && $pkg['valid_until']): ?>
                    <div class="alert alert-info small py-2">
                        <i class="bi bi-calendar-check"></i>
                        Valid:
                        <?= date('d M Y', strtotime($pkg['valid_from'])) ?> —
                        <?= date('d M Y', strtotime($pkg['valid_until'])) ?>
                    </div>
                    <?php endif; ?>

                    <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                    <form method="GET"
                          action="<?= BASE_URL ?>modules/packages/book.php">
                        <input type="hidden" name="pkg_id" value="<?= $pkg_id ?>">

                        <div class="mb-3">
                            <label class="form-label">Travel Date</label>
                            <input type="date" name="travel_date" class="form-control"
                                   min="<?= date('Y-m-d') ?>"
                                   <?php if ($pkg['valid_from']): ?>
                                   min="<?= $pkg['valid_from'] ?>"
                                   <?php endif; ?>
                                   <?php if ($pkg['valid_until']): ?>
                                   max="<?= $pkg['valid_until'] ?>"
                                   <?php endif; ?>
                                   required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Number of Persons</label>
                            <input type="number" name="persons" class="form-control"
                                   min="<?= $pkg['min_persons'] ?>"
                                   max="<?= $pkg['max_persons'] ?>"
                                   value="<?= $pkg['min_persons'] ?>"
                                   id="personsInput" required>
                            <div class="form-text">
                                Min <?= $pkg['min_persons'] ?> — Max <?= $pkg['max_persons'] ?>
                            </div>
                        </div>

                        <!-- Live price calc -->
                        <div class="alert alert-primary py-2 text-center" id="priceCalc">
                            <div class="text-muted small">Total Estimate</div>
                            <div class="fw-bold fs-5" id="calcTotal">
                                ₹<?= number_format($display_price, 0) ?>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 btn-lg">
                            <i class="bi bi-calendar-plus"></i> Book Now
                        </button>
                    </form>
                    <?php else: ?>
                    <a href="<?= BASE_URL ?>auth/login.php"
                       class="btn btn-primary w-100">Login to Book</a>
                    <?php endif; ?>

                    <hr>
                    <div class="text-center text-muted small">
                        <i class="bi bi-shield-check text-success"></i>
                        Secure Booking<br>
                        <i class="bi bi-arrow-counterclockwise text-primary"></i>
                        Free cancellation 48hrs before travel
                    </div>
                </div>
            </div>
        </div>

    </div>

<?php if (is_logged_in()): ?>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<script>
const pricePerPerson = <?= $display_price ?>;
const personsInput   = document.getElementById('personsInput');
if (personsInput) {
    personsInput.addEventListener('input', function () {
        const n     = parseInt(this.value) || 1;
        const total = (pricePerPerson * n).toLocaleString('en-IN');
        document.getElementById('calcTotal').textContent = '₹' + total;
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>