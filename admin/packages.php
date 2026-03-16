<?php
$page_title = 'Package Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$errors = [];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = sanitize($_POST['action'] ?? '');

    // ── Approve / Feature / Delete
    if (in_array($action, ['approve', 'unapprove', 'feature', 'unfeature', 'delete'])) {
        $pkg_id = (int)($_POST['pkg_id'] ?? 0);
        switch ($action) {
            case 'approve':
                $conn->query("UPDATE tour_packages SET is_approved=1, status='active' WHERE id=$pkg_id");
                break;
            case 'unapprove':
                $conn->query("UPDATE tour_packages SET is_approved=0, status='inactive' WHERE id=$pkg_id");
                break;
            case 'feature':
                $conn->query("UPDATE tour_packages SET is_featured=1 WHERE id=$pkg_id");
                break;
            case 'unfeature':
                $conn->query("UPDATE tour_packages SET is_featured=0 WHERE id=$pkg_id");
                break;
            case 'delete':
                $conn->query("DELETE FROM tour_packages WHERE id=$pkg_id");
                break;
        }
        log_admin_action($conn, $_SESSION['user_id'], "Package $action", 'package', $pkg_id);
        set_flash('success', "Package $action successful.");
        redirect('admin/packages.php');
    }

    // ── Create / Update Package
    if ($action === 'save') {
        $pkg_id       = (int)($_POST['pkg_id'] ?? 0);
        $name         = sanitize($_POST['name']        ?? '');
        $destination  = sanitize($_POST['destination'] ?? '');
        $description  = sanitize($_POST['description'] ?? '');
        $duration     = (int)($_POST['duration_days']  ?? 1);
        $price        = (float)($_POST['price_per_person'] ?? 0);
        $max_persons  = (int)($_POST['max_persons']    ?? 10);
        $min_persons  = (int)($_POST['min_persons']    ?? 1);
        $discount     = (float)($_POST['discount_percent'] ?? 0);
        $valid_from   = sanitize($_POST['valid_from']  ?? '');
        $valid_until  = sanitize($_POST['valid_until'] ?? '');

        // JSON fields
        $highlights   = array_filter(array_map(
            'trim',
            explode("\n", $_POST['highlights'] ?? '')
        ));
        $inclusions   = array_filter(array_map(
            'trim',
            explode("\n", $_POST['inclusions'] ?? '')
        ));
        $exclusions   = array_filter(array_map(
            'trim',
            explode("\n", $_POST['exclusions'] ?? '')
        ));

        // Itinerary (dynamic fields)
        $itinerary = [];
        $day_titles = $_POST['day_title']    ?? [];
        $day_descs  = $_POST['day_desc']     ?? [];
        $day_acts   = $_POST['day_activities'] ?? [];
        $day_meals  = $_POST['day_meals']    ?? [];
        foreach ($day_titles as $idx => $title) {
            if (!empty($title)) {
                $itinerary[] = [
                    'title'       => sanitize($title),
                    'description' => sanitize($day_descs[$idx] ?? ''),
                    'activities'  => array_filter(array_map(
                        'trim',
                        explode(',', $day_acts[$idx] ?? '')
                    )),
                    'meals'       => array_filter(array_map(
                        'trim',
                        explode(',', $day_meals[$idx] ?? '')
                    )),
                ];
            }
        }

        if (empty($name))        $errors[] = 'Package name is required.';
        if (empty($destination)) $errors[] = 'Destination is required.';
        if ($price <= 0)         $errors[] = 'Price must be greater than 0.';

        // Cover image
        $cover_image = null;
        if (!empty($_FILES['cover_image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $errors[] = 'Invalid cover image format.';
            } elseif ($_FILES['cover_image']['size'] > 3 * 1024 * 1024) {
                $errors[] = 'Cover image must be under 3MB.';
            } else {
                $dir = UPLOAD_PATH . 'packages/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $cover_image = uniqid('pkg_') . '.' . $ext;
                move_uploaded_file($_FILES['cover_image']['tmp_name'], $dir . $cover_image);
            }
        }

        // Gallery images
        $gallery = [];
        if (!empty($_FILES['gallery']['name'][0])) {
            $dir = UPLOAD_PATH . 'packages/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            foreach ($_FILES['gallery']['tmp_name'] as $idx => $tmp) {
                if ($_FILES['gallery']['error'][$idx] !== 0) continue;
                if (count($gallery) >= 8) break;
                $ext = strtolower(pathinfo($_FILES['gallery']['name'][$idx], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) continue;
                $fname = uniqid('gal_') . '.' . $ext;
                if (move_uploaded_file($tmp, $dir . $fname)) {
                    $gallery[] = $fname;
                }
            }
        }

        if (empty($errors)) {
            $slug         = strtolower(preg_replace('/[^a-z0-9]+/', '-', $name))
                . '-' . ($pkg_id ?: time());
            $hl_json      = json_encode(array_values($highlights));
            $inc_json     = json_encode(array_values($inclusions));
            $exc_json     = json_encode(array_values($exclusions));
            $itin_json    = json_encode($itinerary);
            $gal_json     = json_encode($gallery);

            if ($pkg_id) {
                // Update
                $cover_sql = $cover_image ? ", cover_image='$cover_image'" : '';
                $gal_sql   = $gallery     ? ", gallery='" . $conn->real_escape_string($gal_json) . "'" : '';
                $conn->query("
                    UPDATE tour_packages SET
                        title       = '" . $conn->real_escape_string($name) . "',
                        slug         = '" . $conn->real_escape_string($slug) . "',
                        city        = '" . $conn->real_escape_string($destination) . "',
                        description  = '" . $conn->real_escape_string($description) . "',
                        duration_days= $duration,
                        fixed_price = $price,
                        max_persons  = $max_persons,
                        min_persons  = $min_persons,
                        discount_percent = $discount,
                        valid_from   = " . ($valid_from  ? "'$valid_from'"  : 'NULL') . ",
                        valid_until  = " . ($valid_until ? "'$valid_until'" : 'NULL') . ",
                        highlights   = '" . $conn->real_escape_string($hl_json)   . "',
                        inclusions   = '" . $conn->real_escape_string($inc_json)  . "',
                        exclusions   = '" . $conn->real_escape_string($exc_json)  . "',
                        itinerary    = '" . $conn->real_escape_string($itin_json) . "'
                        $cover_sql $gal_sql
                    WHERE id = $pkg_id
                ");
                set_flash('success', 'Package updated.');
            } else {
                // Insert using real_escape_string — avoids bind_param count mismatch entirely
                $e = fn($v) => $conn->real_escape_string((string)($v ?? ''));

                $dp_sql = ($discount_price > 0)
                    ? "'" . (float)$discount_price . "'"
                    : "NULL";
                $vf_sql = !empty($valid_from)
                    ? "'" . $e($valid_from) . "'"
                    : "NULL";
                $vu_sql = !empty($valid_until)
                    ? "'" . $e($valid_until) . "'"
                    : "NULL";
                $ci_sql = !empty($cover_image)
                    ? "'" . $e($cover_image) . "'"
                    : "NULL";

                $sql = "
        INSERT INTO tour_packages
            (title, slug, city, description, duration_days,
             fixed_price, discount_price,
             max_persons, min_persons, discount_percent,
             valid_from, valid_until,
             highlights, inclusions, exclusions, itinerary,
             cover_image, gallery,
             admin_id,
             is_approved, is_featured,
             includes_hotel, includes_guide, includes_transport,
             status)
        VALUES (
            '{$e($name)}',
            '{$e($slug)}',
            '{$e($destination)}',
            '{$e($description)}',
            " . (int)$duration . ",
            " . (float)$price . ",
            {$dp_sql},
            " . (int)$max_persons . ",
            " . (int)$min_persons . ",
            " . (float)$discount . ",
            {$vf_sql},
            {$vu_sql},
            '{$e($hl_json)}',
            '{$e($inc_json)}',
            '{$e($exc_json)}',
            '{$e($itin_json)}',
            {$ci_sql},
            '{$e($gal_json)}',
            " . (int)$_SESSION['user_id'] . ",
            0, 0,
            0, 0, 0,
            'active'
        )
    ";

                if ($conn->query($sql)) {
                    $pkg_id = $conn->insert_id;
                    set_flash('success', 'Package created successfully.');
                } else {
                    set_flash('error', 'Database error: ' . $conn->error);
                    error_log('Package insert error: ' . $conn->error . ' | SQL: ' . $sql);
                }
            }

            // Save components
            if ($pkg_id) {
                $conn->query("DELETE FROM package_components WHERE package_id = $pkg_id");
                $comp_types = $_POST['comp_type'] ?? [];
                $comp_ids   = $_POST['comp_id']   ?? [];
                $comp_days  = $_POST['comp_day']  ?? [];
                $comp_notes = $_POST['comp_note'] ?? [];
                foreach ($comp_types as $idx => $ctype) {
                    $cid  = (int)($comp_ids[$idx]   ?? 0);
                    $cday = (int)($comp_days[$idx]   ?? 1);
                    $cnote = sanitize($comp_notes[$idx] ?? '');
                    if ($cid && in_array($ctype, ['hotel', 'guide', 'transport'])) {
                        $conn->query("
                            INSERT INTO package_components
                                (package_id, component_type, component_id, day_number, notes)
                            VALUES ($pkg_id, '$ctype', $cid, $cday,
                                    '" . $conn->real_escape_string($cnote) . "')
                        ");
                    }
                }
            }
            redirect('admin/packages.php');
        }
    }
}

// Fetch packages
$packages = [];
$r = $conn->query("
    SELECT tp.*, u.full_name AS creator,
           COUNT(DISTINCT pb.id) AS total_bookings
    FROM tour_packages tp
    JOIN users u ON tp.admin_id = u.id
    LEFT JOIN package_bookings pb ON pb.package_id = tp.id
    WHERE tp.deleted_at IS NULL
    GROUP BY tp.id
    ORDER BY tp.created_at DESC
");
if ($r) $packages = $r->fetch_all(MYSQLI_ASSOC);

// For component dropdowns
$hotels     = [];
$guides     = [];
$transports = [];

$r = $conn->query("SELECT id, name, city FROM hotels WHERE is_approved=1 AND deleted_at IS NULL");
if ($r) $hotels = $r->fetch_all(MYSQLI_ASSOC);

$r = $conn->query("SELECT g.id, u.full_name, g.city FROM guides g JOIN users u ON g.user_id=u.id WHERE g.status='active'");
if ($r) $guides = $r->fetch_all(MYSQLI_ASSOC);

$r = $conn->query("SELECT id, CONCAT(source,' → ',destination,' (',journey_date,')') AS label FROM transport_routes WHERE status='active'");
if ($r) $transports = $r->fetch_all(MYSQLI_ASSOC);
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Package Management</h5>
            <button class="btn btn-primary btn-sm"
                data-bs-toggle="modal" data-bs-target="#pkgModal">
                <i class="bi bi-plus"></i> Create Package
            </button>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Packages table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Package</th>
                            <th>Destination</th>
                            <th>Duration</th>
                            <th>Price</th>
                            <th>Bookings</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $p): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars($p['title']) ?>
                                    </div>
                                    <?php if ($p['is_featured']): ?>
                                        <span class="badge bg-warning text-dark" style="font-size:10px">
                                            Featured
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($p['discount_percent'] > 0): ?>
                                        <span class="badge bg-danger" style="font-size:10px">
                                            <?= $p['discount_percent'] ?>% OFF
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($p['destination'] ?? '-') ?></td>
                                <td><?= $p['duration_days'] ?> Days</td>
                                <td>₹<?= number_format($p['fixed_price'], 0) ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= $p['total_bookings'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?= $p['is_approved']
                                                            ? 'bg-success' : 'bg-warning text-dark' ?>">
                                        <?= $p['is_approved'] ? 'Approved' : 'Pending' ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 flex-wrap">
                                        <a href="<?= BASE_URL ?>modules/packages/detail.php?slug=<?= urlencode($p['slug'] ?? $p['id']) ?>"
                                            target="_blank"
                                            class="btn btn-sm btn-outline-info">View</a>

                                        <?php if (!$p['is_approved']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-success">Approve</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="unapprove">
                                                <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-warning">Unapprove</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!$p['is_featured']): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="feature">
                                                <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-outline-warning">Feature</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                <input type="hidden" name="action" value="unfeature">
                                                <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
                                                <button class="btn btn-sm btn-outline-secondary">Unfeature</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST"
                                            onsubmit="return confirm('Delete this package?')">
                                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="pkg_id" value="<?= $p['id'] ?>">
                                            <button class="btn btn-sm btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($packages)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No packages yet. Create one!
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<!-- Create Package Modal -->
<div class="modal fade" id="pkgModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Tour Package</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="pkg_id" value="0">
                <div class="modal-body" style="max-height:72vh;overflow-y:auto;padding:24px">
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs mb-3" id="pkgTabs">
                        <li class="nav-item">
                            <button class="nav-link active" data-bs-toggle="tab"
                                data-bs-target="#tab-basic">Basic Info</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab"
                                data-bs-target="#tab-itinerary">Itinerary</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab"
                                data-bs-target="#tab-components">Components</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" data-bs-toggle="tab"
                                data-bs-target="#tab-media">Media</button>
                        </li>
                    </ul>

                    <div class="tab-content" style="min-height:400px">

                        <!-- Tab 1: Basic -->
                        <div class="tab-pane fade show active" id="tab-basic">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Package Name *</label>
                                    <input type="text" name="name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Destination *</label>
                                    <input type="text" name="destination" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Description</label>
                                    <textarea name="description" class="form-control" rows="4"></textarea>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Duration (Days) *</label>
                                    <input type="number" name="duration_days" class="form-control"
                                        min="1" value="3" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Price per Person (₹) *</label>
                                    <input type="number" name="price_per_person" class="form-control"
                                        min="1" step="0.01" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Discount (%)</label>
                                    <input type="number" name="discount_percent" class="form-control"
                                        min="0" max="90" value="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Max Persons</label>
                                    <input type="number" name="max_persons" class="form-control"
                                        min="1" value="10">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Min Persons</label>
                                    <input type="number" name="min_persons" class="form-control"
                                        min="1" value="1">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Valid From</label>
                                    <input type="date" name="valid_from" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Valid Until</label>
                                    <input type="date" name="valid_until" class="form-control">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        Highlights
                                        <small class="text-muted">(one per line)</small>
                                    </label>
                                    <textarea name="highlights" class="form-control" rows="5"
                                        placeholder="Visit Taj Mahal&#10;Sunset boat ride&#10;Cultural show"></textarea>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">
                                        Inclusions
                                        <small class="text-muted">(one per line)</small>
                                    </label>
                                    <textarea name="inclusions" class="form-control" rows="5"
                                        placeholder="Hotel accommodation&#10;Breakfast&#10;Guide"></textarea>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">
                                        Exclusions
                                        <small class="text-muted">(one per line)</small>
                                    </label>
                                    <textarea name="exclusions" class="form-control" rows="5"
                                        placeholder="Airfare&#10;Lunch &amp; Dinner&#10;Travel insurance"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Tab 2: Itinerary -->
                        <div class="tab-pane fade" id="tab-itinerary">
                            <div id="itineraryDays">
                                <!-- Day 1 -->
                                <div class="card mb-3 day-block">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <span class="fw-bold day-label">Day 1</span>
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-day">
                                            Remove
                                        </button>
                                    </div>
                                    <div class="card-body row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Day Title</label>
                                            <input type="text" name="day_title[]"
                                                class="form-control"
                                                placeholder="e.g. Arrival & City Tour">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">
                                                Activities
                                                <small class="text-muted">(comma-separated)</small>
                                            </label>
                                            <input type="text" name="day_activities[]"
                                                class="form-control"
                                                placeholder="Sightseeing, Museum Visit">
                                        </div>
                                        <div class="col-md-9">
                                            <label class="form-label">Description</label>
                                            <textarea name="day_desc[]"
                                                class="form-control" rows="2"></textarea>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">
                                                Meals
                                                <small class="text-muted">(comma-separated)</small>
                                            </label>
                                            <input type="text" name="day_meals[]"
                                                class="form-control"
                                                placeholder="Breakfast, Dinner">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-outline-primary"
                                id="addDayBtn">
                                <i class="bi bi-plus"></i> Add Day
                            </button>
                        </div>

                        <!-- Tab 3: Components -->
                        <div class="tab-pane fade" id="tab-components">
                            <p class="text-muted small mb-3">
                                Bundle hotels, guides and transport into this package.
                            </p>
                            <div id="componentRows">
                                <!-- Row template added by JS -->
                            </div>
                            <button type="button" class="btn btn-outline-success"
                                id="addComponentBtn">
                                <i class="bi bi-puzzle"></i> Add Component
                            </button>

                            <!-- Hidden data for JS -->
                            <script>
                                const HOTELS = <?= json_encode($hotels) ?>;
                                const GUIDES = <?= json_encode($guides) ?>;
                                const TRANSPORTS = <?= json_encode($transports) ?>;
                            </script>
                        </div>

                        <!-- Tab 4: Media -->
                        <div class="tab-pane fade" id="tab-media">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Cover Image</label>
                                    <input type="file" name="cover_image"
                                        class="form-control" accept="image/*">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">
                                        Gallery Images
                                        <small class="text-muted">(up to 8)</small>
                                    </label>
                                    <input type="file" name="gallery[]"
                                        class="form-control"
                                        accept="image/*" multiple>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="modal-footer" style="position:sticky;bottom:0;background:#fff;z-index:10;border-top:1px solid #dee2e6">
                    <button type="button" class="btn btn-secondary"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        Save Package
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // ── Add itinerary day
    let dayCount = 1;
    document.getElementById('addDayBtn').addEventListener('click', function() {
        dayCount++;
        const tpl = document.querySelector('.day-block').cloneNode(true);
        tpl.querySelector('.day-label').textContent = 'Day ' + dayCount;
        tpl.querySelectorAll('input, textarea').forEach(el => el.value = '');
        document.getElementById('itineraryDays').appendChild(tpl);
        bindRemoveDay();
        renumberDays();
    });

    function bindRemoveDay() {
        document.querySelectorAll('.remove-day').forEach(btn => {
            btn.onclick = function() {
                if (document.querySelectorAll('.day-block').length > 1) {
                    this.closest('.day-block').remove();
                    renumberDays();
                }
            };
        });
    }

    function renumberDays() {
        document.querySelectorAll('.day-label').forEach((lbl, i) => {
            lbl.textContent = 'Day ' + (i + 1);
            dayCount = i + 1;
        });
    }
    bindRemoveDay();

    // ── Add component row
    let compCount = 0;
    document.getElementById('addComponentBtn').addEventListener('click', function() {
        compCount++;
        const row = document.createElement('div');
        row.className = 'row g-2 mb-2 align-items-center comp-row';

        let hotelOpts = HOTELS.map(h =>
            `<option value="${h.id}">${h.name} (${h.city})</option>`).join('');
        let guideOpts = GUIDES.map(g =>
            `<option value="${g.id}">${g.full_name} (${g.city})</option>`).join('');
        let transOpts = TRANSPORTS.map(t =>
            `<option value="${t.id}">${t.label}</option>`).join('');

        row.innerHTML = `
        <div class="col-md-2">
            <select name="comp_type[]" class="form-select form-select-sm comp-type-sel">
                <option value="hotel">Hotel</option>
                <option value="guide">Guide</option>
                <option value="transport">Transport</option>
            </select>
        </div>
        <div class="col-md-4">
            <select name="comp_id[]" class="form-select form-select-sm comp-id-sel">
                ${hotelOpts}
            </select>
        </div>
        <div class="col-md-1">
            <input type="number" name="comp_day[]" class="form-control form-control-sm"
                   min="1" value="1" placeholder="Day">
        </div>
        <div class="col-md-4">
            <input type="text" name="comp_note[]" class="form-control form-control-sm"
                   placeholder="Note (optional)">
        </div>
        <div class="col-md-1">
            <button type="button" class="btn btn-sm btn-outline-danger remove-comp">
                <i class="bi bi-x"></i>
            </button>
        </div>
    `;
        document.getElementById('componentRows').appendChild(row);

        // Type change handler
        row.querySelector('.comp-type-sel').addEventListener('change', function() {
            const sel = row.querySelector('.comp-id-sel');
            if (this.value === 'hotel') {
                sel.innerHTML = hotelOpts;
            } else if (this.value === 'guide') {
                sel.innerHTML = guideOpts;
            } else {
                sel.innerHTML = transOpts;
            }
        });

        // Remove handler
        row.querySelector('.remove-comp').addEventListener('click', function() {
            row.remove();
        });
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>