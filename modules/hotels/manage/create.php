<?php
$page_title = 'My Hotel';
require_once __DIR__ . '/../../../includes/header.php';
require_role('hotel_staff');

$user_id = $_SESSION['user_id'];
$errors  = [];

// Fetch existing hotel
$stmt = $conn->prepare("SELECT * FROM hotels WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name        = sanitize($_POST['name']        ?? '');
    $description = sanitize($_POST['description'] ?? '');
    $city        = sanitize($_POST['city']        ?? '');
    $state       = sanitize($_POST['state']       ?? '');
    $country     = sanitize($_POST['country']     ?? 'India');
    $address     = sanitize($_POST['address']     ?? '');
    $star_rating = (int)($_POST['star_rating']    ?? 3);
    $amenities   = array_filter(array_map('trim', explode(',', $_POST['amenities'] ?? '')));
    $slug        = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name)) . '-' . $user_id;

    if (empty($name))    $errors[] = 'Hotel name is required.';
    if (empty($city))    $errors[] = 'City is required.';
    if (empty($address)) $errors[] = 'Address is required.';

    // Handle cover image upload
    $cover_image = $hotel['cover_image'] ?? 'default.jpg';
    if (!empty($_FILES['cover_image']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid image format. Use JPG, PNG, or WEBP.';
        } elseif ($_FILES['cover_image']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Image must be under 2MB.';
        } else {
            $cover_image = uniqid('hotel_') . '.' . $ext;
            $dest        = UPLOAD_PATH . 'hotels/' . $cover_image;
            if (!move_uploaded_file($_FILES['cover_image']['tmp_name'], $dest)) {
                $errors[] = 'Failed to upload image.';
            }
        }
    }

    if (empty($errors)) {
        $amenities_json = json_encode(array_values($amenities));

        if ($hotel) {
            // ── UPDATE ──────────────────────────────────────────────────
            // 10 SET columns (sssssssis s) + 1 WHERE id (i) = 11 types, 11 values
            // REMOVED the broken first block: bind_param('sssssssiss i', ...) 
            // which had a space in the type string causing the fatal error on line 64
            $stmt = $conn->prepare("
                UPDATE hotels
                SET name=?, slug=?, description=?, city=?, state=?, country=?,
                    address=?, star_rating=?, amenities=?, cover_image=?
                WHERE id=?
            ");
            $stmt->bind_param('sssssssissi',
                $name, $slug, $description, $city, $state, $country,
                $address, $star_rating, $amenities_json, $cover_image,
                $hotel['id']
            );
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Hotel updated successfully.');

        } else {
            // ── INSERT ──────────────────────────────────────────────────
            // owner_id(i) + 10 string/int fields = isssssssiss (11 types, 11 values)
            $stmt = $conn->prepare("
                INSERT INTO hotels
                    (owner_id, name, slug, description, city, state, country,
                     address, star_rating, amenities, cover_image, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->bind_param('isssssssiss',
                $user_id, $name, $slug, $description, $city, $state, $country,
                $address, $star_rating, $amenities_json, $cover_image
            );
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Hotel submitted for admin approval.');
        }

        redirect('dashboards/hotel_staff/index.php');
    }
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/hotel_staff/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0"><?= $hotel ? 'Edit Hotel' : 'Add Hotel' ?></h5>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger mx-3 mt-3">
            <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($hotel && !$hotel['is_approved']): ?>
        <div class="alert alert-warning mx-3 mt-3">
            <i class="bi bi-clock me-1"></i> Your hotel is pending admin approval.
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                        <div class="row g-3">

                            <div class="col-md-8">
                                <label class="form-label fw-semibold">
                                    Hotel Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="name" class="form-control"
                                       value="<?= htmlspecialchars($hotel['name'] ?? $_POST['name'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Star Rating</label>
                                <select name="star_rating" class="form-select">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?= $i ?>"
                                        <?= (($hotel['star_rating'] ?? 3) == $i) ? 'selected' : '' ?>>
                                        <?= $i ?> Star<?= $i > 1 ? 's' : '' ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">
                                    City <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="city" class="form-control"
                                       value="<?= htmlspecialchars($hotel['city'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">State</label>
                                <input type="text" name="state" class="form-control"
                                       value="<?= htmlspecialchars($hotel['state'] ?? '') ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Country</label>
                                <input type="text" name="country" class="form-control"
                                       value="<?= htmlspecialchars($hotel['country'] ?? 'India') ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    Full Address <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="address" class="form-control"
                                       value="<?= htmlspecialchars($hotel['address'] ?? '') ?>"
                                       required>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Description</label>
                                <textarea name="description" class="form-control" rows="4"
                                          placeholder="Describe your hotel..."><?= htmlspecialchars($hotel['description'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    Amenities
                                    <small class="text-muted fw-normal">
                                        (comma-separated, e.g. WiFi, Pool, Gym)
                                    </small>
                                </label>
                                <?php
                                $existing_amenities = '';
                                if ($hotel) {
                                    $am = json_decode($hotel['amenities'], true) ?? [];
                                    $existing_amenities = implode(', ', $am);
                                }
                                ?>
                                <input type="text" name="amenities" class="form-control"
                                       value="<?= htmlspecialchars($existing_amenities) ?>"
                                       placeholder="WiFi, Pool, Gym, Parking, Restaurant">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold">Cover Image</label>
                                <?php if (!empty($hotel['cover_image']) && $hotel['cover_image'] !== 'default.jpg'): ?>
                                <div class="mb-2">
                                    <img src="<?= BASE_URL ?>assets/uploads/hotels/<?= htmlspecialchars($hotel['cover_image']) ?>"
                                         style="max-height:150px;border-radius:8px"
                                         alt="Current cover">
                                </div>
                                <?php endif; ?>
                                <input type="file" name="cover_image" class="form-control"
                                       accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">Max 2MB. JPG, PNG, WEBP only.</div>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>
                                    <?= $hotel ? 'Update Hotel' : 'Submit Hotel' ?>
                                </button>
                                <a href="<?= BASE_URL ?>dashboards/hotel_staff/index.php"
                                   class="btn btn-outline-secondary ms-2">Cancel</a>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>