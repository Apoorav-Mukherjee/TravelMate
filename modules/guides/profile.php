<?php
$page_title = 'Guide Profile';
require_once __DIR__ . '/../../includes/header.php';

$guide_id = (int)($_GET['id']   ?? 0);
$date     = sanitize($_GET['date'] ?? '');

if (!$guide_id) redirect('modules/guides/search.php');

// Fetch guide with user info
$stmt = $conn->prepare("
    SELECT g.*, u.full_name, u.profile_picture, u.email, u.phone
    FROM guides g
    JOIN users u ON g.user_id = u.id
    WHERE g.id = ? AND g.status = 'active'
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guide) {
    set_flash('error', 'Guide not found.');
    redirect('modules/guides/search.php');
}

// Fetch avg rating
$stmt = $conn->prepare("
    SELECT COALESCE(AVG(rating), 0) as avg, COUNT(*) as total
    FROM reviews
    WHERE entity_type = 'guide' AND entity_id = ? AND is_approved = 1
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$rating_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch reviews
$stmt = $conn->prepare("
    SELECT r.*, u.full_name, u.profile_picture
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.entity_type = 'guide' AND r.entity_id = ? AND r.is_approved = 1
    ORDER BY r.created_at DESC
    LIMIT 8
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch blocked dates for this guide (next 60 days)
$stmt = $conn->prepare("
    SELECT date FROM availability_calendars
    WHERE entity_type = 'guide' AND entity_id = ? AND is_available = 0
      AND date >= CURDATE()
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$blocked_dates_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$blocked_dates = array_column($blocked_dates_raw, 'date');

// Fetch already booked dates
$stmt = $conn->prepare("
    SELECT b.check_in FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    WHERE bi.item_type = 'guide_session' AND bi.item_id = ?
      AND b.booking_status NOT IN ('cancelled')
      AND b.check_in >= CURDATE()
");
$stmt->bind_param('i', $guide_id);
$stmt->execute();
$booked_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$booked_dates = array_column($booked_raw, 'check_in');

$unavailable_dates = array_unique(array_merge($blocked_dates, $booked_dates));

$languages       = json_decode($guide['languages'],      true) ?? [];
$specializations = json_decode($guide['specializations'],true) ?? [];
$portfolio       = json_decode($guide['portfolio_images'],true) ?? [];
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
                <a href="<?= BASE_URL ?>modules/guides/search.php">Guides</a>
            </li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($guide['full_name']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">

        <!-- Left: Guide Info -->
        <div class="col-lg-8">

            <!-- Profile Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <div class="d-flex gap-4 align-items-start">
                        <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($guide['profile_picture'] ?: 'default.png') ?>"
                             class="rounded-circle"
                             style="width:110px;height:110px;object-fit:cover;border:4px solid #0d6efd">
                        <div class="flex-grow-1">
                            <h3 class="fw-bold mb-1">
                                <?= htmlspecialchars($guide['full_name']) ?>
                                <?php if ($guide['is_verified']): ?>
                                <span class="badge bg-primary ms-2">
                                    <i class="bi bi-patch-check-fill"></i> Verified
                                </span>
                                <?php endif; ?>
                            </h3>
                            <p class="text-muted mb-2">
                                <i class="bi bi-geo-alt-fill text-danger"></i>
                                <?= htmlspecialchars($guide['city'] ?? 'Location not set') ?>
                                &nbsp;&bull;&nbsp;
                                <i class="bi bi-briefcase"></i>
                                <?= $guide['experience_years'] ?> years experience
                            </p>

                            <!-- Star Rating -->
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <div>
                                    <?php
                                    $avg = round($rating_data['avg'], 1);
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $avg
                                            ? '<i class="bi bi-star-fill text-warning fs-5"></i>'
                                            : '<i class="bi bi-star text-muted fs-5"></i>';
                                    }
                                    ?>
                                </div>
                                <span class="fw-bold"><?= number_format($avg, 1) ?></span>
                                <span class="text-muted">(<?= $rating_data['total'] ?> reviews)</span>
                            </div>

                            <!-- Languages -->
                            <div class="mb-2">
                                <strong class="small">Languages: </strong>
                                <?php foreach ($languages as $l): ?>
                                <span class="badge bg-info text-dark"><?= htmlspecialchars($l) ?></span>
                                <?php endforeach; ?>
                            </div>

                            <!-- Specializations -->
                            <div>
                                <strong class="small">Specializations: </strong>
                                <?php foreach ($specializations as $s): ?>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($s) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="fw-bold">About Me</h6>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($guide['bio'] ?? 'No bio provided.')) ?></p>
                </div>
            </div>

            <!-- Portfolio -->
            <?php if ($portfolio): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold">Portfolio</div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php foreach ($portfolio as $img): ?>
                        <div class="col-4">
                            <img src="<?= BASE_URL ?>assets/uploads/guides/<?= htmlspecialchars($img) ?>"
                                 class="img-fluid rounded"
                                 style="height:150px;width:100%;object-fit:cover">
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reviews -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">Reviews</div>
                <div class="card-body">
                    <?php if (empty($reviews)): ?>
                    <p class="text-muted">No reviews yet.</p>
                    <?php endif; ?>
                    <?php foreach ($reviews as $rev): ?>
                    <div class="d-flex gap-3 mb-4">
                        <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($rev['profile_picture']) ?>"
                             class="rounded-circle" width="45" height="45" style="object-fit:cover">
                        <div>
                            <div class="d-flex gap-2 align-items-center">
                                <strong><?= htmlspecialchars($rev['full_name']) ?></strong>
                                <small class="text-muted"><?= date('d M Y', strtotime($rev['created_at'])) ?></small>
                            </div>
                            <div class="mb-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?= $i <= $rev['rating']
                                    ? '<i class="bi bi-star-fill text-warning"></i>'
                                    : '<i class="bi bi-star text-muted"></i>' ?>
                                <?php endfor; ?>
                            </div>
                            <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($rev['review_text'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Submit Review -->
                    <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                    <hr>
                    <h6 class="fw-bold">Leave a Review</h6>
                    <form method="POST" action="<?= BASE_URL ?>modules/guides/submit_review.php">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="guide_id"   value="<?= $guide_id ?>">
                        <div class="mb-2">
                            <select name="rating" class="form-select" style="max-width:200px" required>
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
                        <button type="submit" class="btn btn-success btn-sm">Submit Review</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right: Booking Panel -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:20px">
                <div class="card-header bg-primary text-white fw-bold">Book This Guide</div>
                <div class="card-body">

                    <!-- Pricing -->
                    <div class="d-flex justify-content-around mb-4">
                        <?php if ($guide['hourly_rate']): ?>
                        <div class="text-center">
                            <div class="fs-4 fw-bold text-primary">
                                ₹<?= number_format($guide['hourly_rate'], 0) ?>
                            </div>
                            <div class="text-muted small">per hour</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($guide['daily_rate']): ?>
                        <div class="text-center">
                            <div class="fs-4 fw-bold text-success">
                                ₹<?= number_format($guide['daily_rate'], 0) ?>
                            </div>
                            <div class="text-muted small">per day</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                    <form method="GET"
                          action="<?= BASE_URL ?>modules/guides/book.php">
                        <input type="hidden" name="guide_id" value="<?= $guide_id ?>">

                        <div class="mb-3">
                            <label class="form-label">Select Date</label>
                            <input type="date" name="date" id="bookingDate"
                                   class="form-control"
                                   value="<?= htmlspecialchars($date) ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                            <div class="form-text text-danger" id="dateWarning"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Booking Type</label>
                            <select name="type" class="form-select" id="bookingType" required>
                                <?php if ($guide['hourly_rate']): ?>
                                <option value="hourly">Hourly</option>
                                <?php endif; ?>
                                <?php if ($guide['daily_rate']): ?>
                                <option value="daily">Full Day</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="hoursDiv">
                            <label class="form-label">Number of Hours</label>
                            <input type="number" name="hours" class="form-control"
                                   min="1" max="12" value="2">
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-calendar-plus"></i> Book Guide
                        </button>
                    </form>

                    <!-- Unavailable Dates Notice -->
                    <?php if ($unavailable_dates): ?>
                    <div class="alert alert-warning mt-3 small">
                        <i class="bi bi-calendar-x"></i> This guide is unavailable on:
                        <?php foreach (array_slice($unavailable_dates, 0, 5) as $ud): ?>
                        <br>• <?= date('d M Y', strtotime($ud)) ?>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <a href="<?= BASE_URL ?>auth/login.php"
                       class="btn btn-primary w-100">Login to Book</a>
                    <?php endif; ?>

                    <hr>
                    <div class="text-center text-muted small">
                        <i class="bi bi-shield-check text-success"></i>
                        Verified & Background Checked<br>
                        <i class="bi bi-arrow-counterclockwise text-primary"></i>
                        Free cancellation 24hrs before
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
// Show/hide hours field based on booking type
const bookingType = document.getElementById('bookingType');
const hoursDiv    = document.getElementById('hoursDiv');
if (bookingType) {
    bookingType.addEventListener('change', function() {
        hoursDiv.style.display = this.value === 'hourly' ? 'block' : 'none';
    });
    bookingType.dispatchEvent(new Event('change'));
}

// Warn if unavailable date selected
const unavailableDates = <?= json_encode($unavailable_dates) ?>;
const bookingDate = document.getElementById('bookingDate');
if (bookingDate) {
    bookingDate.addEventListener('change', function() {
        const warning = document.getElementById('dateWarning');
        warning.textContent = unavailableDates.includes(this.value)
            ? '⚠️ Guide is not available on this date.'
            : '';
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>