<?php
$page_title = 'Hotel Detail';
require_once __DIR__ . '/../../includes/header.php';

$hotel_id  = (int)($_GET['id'] ?? 0);
$check_in  = sanitize($_GET['check_in']  ?? '');
$check_out = sanitize($_GET['check_out'] ?? '');

if (!$hotel_id) { redirect('modules/hotels/search.php'); }

// Fetch hotel
$stmt = $conn->prepare("
    SELECT h.*, u.full_name as owner_name
    FROM hotels h
    JOIN users u ON h.owner_id = u.id
    WHERE h.id = ? AND h.is_approved = 1 AND h.status = 'active' AND h.deleted_at IS NULL
");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hotel) {
    set_flash('error', 'Hotel not found.');
    redirect('modules/hotels/search.php');
}

// Fetch rooms
$stmt = $conn->prepare("SELECT * FROM rooms WHERE hotel_id = ? AND status = 'available'");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$rooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check room availability for selected dates
$booked_rooms = [];
if ($check_in && $check_out) {
    $stmt = $conn->prepare("
        SELECT bi.item_id
        FROM booking_items bi
        JOIN bookings b ON bi.booking_id = b.id
        WHERE bi.item_type = 'room'
          AND b.booking_status NOT IN ('cancelled')
          AND b.check_in  < ?
          AND b.check_out > ?
    ");
    $stmt->bind_param('ss', $check_out, $check_in);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $booked_rooms[] = $row['item_id'];
    }
    $stmt->close();
}

// Fetch reviews
$stmt = $conn->prepare("
    SELECT r.*, u.full_name, u.profile_picture
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.entity_type = 'hotel' AND r.entity_id = ? AND r.is_approved = 1
    ORDER BY r.created_at DESC
    LIMIT 10
");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Average rating
$stmt = $conn->prepare("
    SELECT COALESCE(AVG(rating),0) as avg, COUNT(*) as total
    FROM reviews
    WHERE entity_type = 'hotel' AND entity_id = ? AND is_approved = 1
");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$rating_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

// FIX: safe_json_decode helper — always returns an array
function safe_json_array($val) {
    if (empty($val)) return [];
    $decoded = json_decode($val, true);
    return is_array($decoded) ? $decoded : [];
}

$amenities = safe_json_array($hotel['amenities']);
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

<div class="p-3 p-md-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="<?= BASE_URL ?>modules/hotels/search.php">Hotels</a>
            </li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($hotel['name']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">
        <!-- Left: Hotel Info -->
        <div class="col-lg-8">

            <!-- Cover Image + Info -->
            <div class="card border-0 shadow-sm mb-4">
                <img src="<?= BASE_URL ?>assets/uploads/hotels/<?= htmlspecialchars($hotel['cover_image'] ?: 'default.jpg') ?>"
                     class="card-img-top"
                     style="height:350px;object-fit:cover;border-radius:12px 12px 0 0"
                     alt="<?= htmlspecialchars($hotel['name']) ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="fw-bold mb-1"><?= htmlspecialchars($hotel['name']) ?></h2>
                            <p class="text-muted">
                                <i class="bi bi-geo-alt-fill text-danger"></i>
                                <?= htmlspecialchars($hotel['address']) ?>,
                                <?= htmlspecialchars($hotel['city']) ?>,
                                <?= htmlspecialchars($hotel['country']) ?>
                            </p>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark fs-6">
                                <?= $hotel['star_rating'] ?>★
                            </span>
                            <br>
                            <small class="text-muted">
                                <?= round($rating_data['avg'], 1) ?>/5
                                (<?= $rating_data['total'] ?> review<?= $rating_data['total'] != 1 ? 's' : '' ?>)
                            </small>
                        </div>
                    </div>

                    <hr>
                    <p><?= nl2br(htmlspecialchars($hotel['description'])) ?></p>

                    <!-- Amenities -->
                    <?php if (!empty($amenities)): ?>
                    <h6 class="fw-bold mt-3">Amenities</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($amenities as $a): ?>
                        <span class="badge bg-light text-dark border">
                            <i class="bi bi-check-circle-fill text-success"></i>
                            <?= htmlspecialchars($a) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rooms -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-bold fs-5">Available Rooms</div>
                <div class="card-body">
                    <?php if (empty($rooms)): ?>
                    <p class="text-muted">No rooms available at this time.</p>
                    <?php endif; ?>

                    <?php foreach ($rooms as $room): ?>
                    <?php
                    // FIX: use safe_json_array so array_slice never receives null/false
                    $room_images    = safe_json_array($room['images']);
                    $room_amenities = safe_json_array($room['amenities']);
                    $is_booked      = in_array($room['id'], $booked_rooms);

                    // Seasonal price check
                    $price = $room['base_price'];
                    if ($check_in) {
                        $stmt = $conn->prepare("
                            SELECT override_price FROM availability_calendars
                            WHERE entity_type = 'room' AND entity_id = ? AND date = ?
                              AND override_price IS NOT NULL
                        ");
                        $stmt->bind_param('is', $room['id'], $check_in);
                        $stmt->execute();
                        $override = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($override) $price = $override['override_price'];
                    }

                    // Calculate total nights
                    $nights = 1;
                    if ($check_in && $check_out) {
                        $nights = max(1, (int)((strtotime($check_out) - strtotime($check_in)) / 86400));
                    }
                    ?>
                    <div class="card mb-3 <?= $is_booked ? 'opacity-50' : '' ?>">
                        <div class="row g-0">
                            <div class="col-md-3">
                                <img src="<?= BASE_URL ?>assets/uploads/rooms/<?= htmlspecialchars($room_images[0] ?? 'default.jpg') ?>"
                                     class="img-fluid rounded-start"
                                     style="height:140px;width:100%;object-fit:cover"
                                     alt="<?= htmlspecialchars($room['room_type']) ?>">
                            </div>
                            <div class="col-md-9">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <h6 class="fw-bold"><?= htmlspecialchars($room['room_type']) ?></h6>
                                            <p class="text-muted small mb-1">
                                                <i class="bi bi-people"></i>
                                                Max <?= $room['max_occupancy'] ?> guests
                                            </p>
                                            <!-- FIX: array_slice now always gets a real array -->
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach (array_slice($room_amenities, 0, 4) as $a): ?>
                                                <span class="badge bg-light text-dark border small">
                                                    <?= htmlspecialchars($a) ?>
                                                </span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="fs-5 fw-bold text-primary">
                                                ₹<?= number_format($price, 0) ?>
                                                <span class="fs-6 text-muted fw-normal">/night</span>
                                            </div>
                                            <?php if ($check_in && $check_out): ?>
                                            <div class="text-muted small">
                                                Total: ₹<?= number_format($price * $nights, 0) ?>
                                                for <?= $nights ?> night<?= $nights > 1 ? 's' : '' ?>
                                            </div>
                                            <?php endif; ?>

                                            <?php if ($is_booked): ?>
                                            <span class="badge bg-danger mt-2">Not Available</span>
                                            <?php elseif (is_logged_in()): ?>
                                            <a href="<?= BASE_URL ?>modules/hotels/book.php?room_id=<?= $room['id'] ?>&hotel_id=<?= $hotel_id ?>&check_in=<?= urlencode($check_in) ?>&check_out=<?= urlencode($check_out) ?>"
                                               class="btn btn-primary btn-sm mt-2">Book Now</a>
                                            <?php else: ?>
                                            <a href="<?= BASE_URL ?>auth/login.php"
                                               class="btn btn-outline-primary btn-sm mt-2">Login to Book</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Reviews -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold fs-5">Guest Reviews</div>
                <div class="card-body">
                    <?php if (empty($reviews)): ?>
                    <p class="text-muted">No reviews yet. Be the first!</p>
                    <?php endif; ?>

                    <?php foreach ($reviews as $rev): ?>
                    <div class="d-flex gap-3 mb-4">
                        <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($rev['profile_picture'] ?: 'default.png') ?>"
                             class="rounded-circle flex-shrink-0"
                             width="45" height="45" style="object-fit:cover"
                             alt="<?= htmlspecialchars($rev['full_name']) ?>">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <strong><?= htmlspecialchars($rev['full_name']) ?></strong>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($rev['created_at'])) ?>
                                </small>
                            </div>
                            <div class="mb-1">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                <?= $i <= $rev['rating']
                                    ? '<i class="bi bi-star-fill text-warning"></i>'
                                    : '<i class="bi bi-star text-muted"></i>' ?>
                                <?php endfor; ?>
                            </div>
                            <p class="mb-0 text-muted">
                                <?= nl2br(htmlspecialchars($rev['review_text'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Leave a Review -->
                    <?php if (is_logged_in() && get_role() === 'traveler'): ?>
                    <hr>
                    <h6 class="fw-bold">Leave a Review</h6>
                    <form method="POST" action="<?= BASE_URL ?>modules/hotels/submit_review.php">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="hotel_id"   value="<?= $hotel_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Rating</label>
                            <select name="rating" class="form-select" style="max-width:200px" required>
                                <option value="">Select rating</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?= $i ?>"><?= $i ?> Star<?= $i > 1 ? 's' : '' ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Your Review</label>
                            <textarea name="review_text" class="form-control" rows="3"
                                      placeholder="Share your experience..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm">Submit Review</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /col-lg-8 -->

        <!-- Right: Quick Search Sidebar -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm sticky-top" style="top:20px">
                <div class="card-header bg-primary text-white fw-bold">Check Room Availability</div>
                <div class="card-body">
                    <form method="GET" action="">
                        <input type="hidden" name="id" value="<?= $hotel_id ?>">
                        <div class="mb-3">
                            <label class="form-label">Check In</label>
                            <input type="date" name="check_in" class="form-control"
                                   value="<?= htmlspecialchars($check_in) ?>"
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Check Out</label>
                            <input type="date" name="check_out" class="form-control"
                                   value="<?= htmlspecialchars($check_out) ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search me-1"></i>Check Availability
                        </button>
                    </form>

                    <hr>
                    <div class="d-flex justify-content-between text-muted small">
                        <span><i class="bi bi-person me-1"></i>Managed by</span>
                        <span><?= htmlspecialchars($hotel['owner_name']) ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row -->

</div><!-- /p-4 -->

<?php if (is_logged_in()): ?>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>