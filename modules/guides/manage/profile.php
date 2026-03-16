<?php
$page_title = 'My Guide Profile';
require_once __DIR__ . '/../../../includes/header.php';
require_role('guide');

$user_id = $_SESSION['user_id'];
$errors  = [];

// Fetch existing profile
$stmt = $conn->prepare("SELECT * FROM guides WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $bio              = sanitize($_POST['bio']              ?? '');
    $city             = sanitize($_POST['city']             ?? '');
    $experience_years = (int)($_POST['experience_years']   ?? 0);
    $hourly_rate      = (float)($_POST['hourly_rate']      ?? 0);
    $daily_rate       = (float)($_POST['daily_rate']       ?? 0);
    $languages        = array_filter(array_map('trim', explode(',', $_POST['languages']        ?? '')));
    $specializations  = array_filter(array_map('trim', explode(',', $_POST['specializations']  ?? '')));

    if (empty($city))      $errors[] = 'City is required.';
    if ($daily_rate <= 0 && $hourly_rate <= 0) $errors[] = 'At least one rate is required.';

    // Handle profile picture
    $profile_pic = null;
    if (!empty($_FILES['profile_picture']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid image format.';
        } elseif ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Image must be under 2MB.';
        } else {
            $profile_pic = uniqid('guide_') . '.' . $ext;
            move_uploaded_file(
                $_FILES['profile_picture']['tmp_name'],
                UPLOAD_PATH . $profile_pic
            );
        }
    }

    // Handle portfolio images (up to 6)
    $portfolio = $guide ? (json_decode($guide['portfolio_images'], true) ?? []) : [];
    if (!empty($_FILES['portfolio']['name'][0])) {
        foreach ($_FILES['portfolio']['tmp_name'] as $idx => $tmp) {
            if ($_FILES['portfolio']['error'][$idx] !== 0) continue;
            if (count($portfolio) >= 6) break;
            $ext     = strtolower(pathinfo($_FILES['portfolio']['name'][$idx], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowed)) continue;
            $fname   = uniqid('port_') . '.' . $ext;
            if (move_uploaded_file($tmp, UPLOAD_PATH . 'guides/' . $fname)) {
                $portfolio[] = $fname;
            }
        }
    }

    if (empty($errors)) {
        $lang_json    = json_encode(array_values($languages));
        $spec_json    = json_encode(array_values($specializations));
        $port_json    = json_encode($portfolio);

        if ($guide) {
            // Update
            if ($guide) {
                $stmt = $conn->prepare("
        UPDATE guides
        SET bio = ?, city = ?, experience_years = ?,
            hourly_rate = ?, daily_rate = ?,
            languages = ?, specializations = ?, portfolio_images = ?
        WHERE user_id = ?
    ");
                $stmt->bind_param(
                    'ssiddsssi',
                    $bio,
                    $city,
                    $experience_years,
                    $hourly_rate,
                    $daily_rate,
                    $lang_json,
                    $spec_json,
                    $port_json,
                    $user_id
                );
                $stmt->execute();
                $stmt->close();

                if ($profile_pic) {
                    $stmt = $conn->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                    $stmt->bind_param('si', $profile_pic, $user_id);
                    $stmt->execute();
                    $stmt->close();
                }

                set_flash('success', 'Profile updated. Awaiting re-verification.');
            }
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO guides
                    (user_id, bio, city, experience_years, hourly_rate, daily_rate,
                     languages, specializations, portfolio_images, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->bind_param(
                'ssiddssss',
                $user_id,
                $bio,
                $city,
                $experience_years,
                $hourly_rate,
                $daily_rate,
                $lang_json,
                $spec_json,
                $port_json
            );
            $stmt->execute();
            $stmt->close();

            if ($profile_pic) {
                $conn->query("UPDATE users SET profile_picture = '"
                    . $conn->real_escape_string($profile_pic) . "'
                    WHERE id = " . (int)$user_id);
            }

            set_flash('success', 'Profile created! Awaiting admin verification.');
        }
        redirect('dashboards/guide/index.php');
    }
}

$existing_languages       = $guide ? implode(', ', json_decode($guide['languages'],       true) ?? []) : '';
$existing_specializations = $guide ? implode(', ', json_decode($guide['specializations'], true) ?? []) : '';
$existing_portfolio       = $guide ? (json_decode($guide['portfolio_images'], true) ?? []) : [];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/guide/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">My Guide Profile</h5>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Profile Picture</label>
                            <?php
                            $stmt = $conn->prepare("SELECT profile_picture FROM users WHERE id = ?");
                            $stmt->bind_param('i', $user_id);
                            $stmt->execute();
                            $user_pic = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                            ?>
                            <?php if (!empty($user_pic['profile_picture'])): ?>
                                <div class="mb-2">
                                    <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($user_pic['profile_picture']) ?>"
                                        class="rounded-circle" width="80" height="80" style="object-fit:cover">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="profile_picture" class="form-control"
                                accept="image/*">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">City <span class="text-danger">*</span></label>
                            <input type="text" name="city" class="form-control"
                                value="<?= htmlspecialchars($guide['city'] ?? '') ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Experience (years)</label>
                            <input type="number" name="experience_years" class="form-control"
                                value="<?= $guide['experience_years'] ?? 0 ?>" min="0" max="50">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Hourly Rate (₹)</label>
                            <input type="number" name="hourly_rate" class="form-control"
                                value="<?= $guide['hourly_rate'] ?? '' ?>" min="0" step="0.01"
                                placeholder="Leave blank if not offering">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Daily Rate (₹)</label>
                            <input type="number" name="daily_rate" class="form-control"
                                value="<?= $guide['daily_rate'] ?? '' ?>" min="0" step="0.01"
                                placeholder="Leave blank if not offering">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Languages
                                <small class="text-muted">(comma-separated)</small>
                            </label>
                            <input type="text" name="languages" class="form-control"
                                value="<?= htmlspecialchars($existing_languages) ?>"
                                placeholder="Hindi, English, French">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Specializations
                                <small class="text-muted">(comma-separated)</small>
                            </label>
                            <input type="text" name="specializations" class="form-control"
                                value="<?= htmlspecialchars($existing_specializations) ?>"
                                placeholder="Heritage, Wildlife, Adventure, Food">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Bio / About Me</label>
                            <textarea name="bio" class="form-control" rows="5"
                                placeholder="Describe your experience, style, and what makes you unique..."><?= htmlspecialchars($guide['bio'] ?? '') ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">
                                Portfolio Images
                                <small class="text-muted">(up to 6 images)</small>
                            </label>
                            <?php if ($existing_portfolio): ?>
                                <div class="d-flex gap-2 mb-2 flex-wrap">
                                    <?php foreach ($existing_portfolio as $img): ?>
                                        <img src="<?= BASE_URL ?>assets/uploads/guides/<?= htmlspecialchars($img) ?>"
                                            style="width:80px;height:60px;object-fit:cover;border-radius:6px">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="portfolio[]" class="form-control"
                                accept="image/*" multiple>
                            <div class="form-text">New uploads will be added to existing portfolio.</div>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i>
                                <?= $guide ? 'Update Profile' : 'Create Profile' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>