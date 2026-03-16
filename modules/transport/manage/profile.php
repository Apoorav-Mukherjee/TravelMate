<?php
$page_title = 'Company Profile';
require_once __DIR__ . '/../../../includes/header.php';
require_role('transport_provider');

$user_id = $_SESSION['user_id'];

// Fetch provider record
$stmt = $conn->prepare("SELECT * FROM transport_providers WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$provider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$provider) {
    set_flash('danger', 'Transport provider profile not found.');
    redirect('dashboards/transport_provider/index.php');
}

$provider_id = $provider['id'];

// Fetch user info
$stmt = $conn->prepare("SELECT full_name, email, phone FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Stats
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT r.id)                                                                     AS total_routes,
        SUM(CASE WHEN r.status != 'cancelled' AND r.journey_date >= CURDATE() THEN 1 ELSE 0 END) AS active_routes,
        COUNT(DISTINCT b.id)                                                                     AS total_bookings,
        COALESCE(SUM(b.final_amount), 0)                                                         AS total_revenue
    FROM transport_routes r
    LEFT JOIN booking_items bi ON bi.item_id = r.id AND bi.item_type = 'seat'
    LEFT JOIN bookings b       ON b.id = bi.booking_id AND b.booking_status != 'cancelled'
    WHERE r.provider_id = ?
");
$stmt->bind_param("i", $provider_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $company_name   = sanitize($_POST['company_name']   ?? '');
    $transport_type = sanitize($_POST['transport_type'] ?? '');
    $license_number = sanitize($_POST['license_number'] ?? '');
    $full_name      = sanitize($_POST['full_name']      ?? '');
    $phone          = sanitize($_POST['phone']          ?? '');

    $allowed_types = ['bus', 'train', 'ferry', 'cab'];

    if ($company_name === '')                       $errors[] = 'Company name is required.';
    if (!in_array($transport_type, $allowed_types)) $errors[] = 'Invalid transport type.';
    if ($license_number === '')                     $errors[] = 'License number is required.';
    if ($full_name === '')                          $errors[] = 'Contact name is required.';

    if (empty($errors)) {
        $stmt = $conn->prepare("
            UPDATE transport_providers
            SET company_name = ?, transport_type = ?, license_number = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("sssi", $company_name, $transport_type, $license_number, $provider_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $phone, $user_id);
        $stmt->execute();
        $stmt->close();

        // Refresh local vars
        $provider['company_name']   = $company_name;
        $provider['transport_type'] = $transport_type;
        $provider['license_number'] = $license_number;
        $user['full_name']          = $full_name;
        $user['phone']              = $phone;

        $success = true;
    }
}

$status_badge = [
    'active'   => 'bg-success',
    'inactive' => 'bg-secondary',
    'pending'  => 'bg-warning text-dark',
];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/transport_provider/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0"><i class="bi bi-building me-2"></i>Company Profile</h5>
            <span class="badge <?= $status_badge[$provider['status']] ?? 'bg-secondary' ?> fs-6">
                <?= ucfirst($provider['status']) ?>
            </span>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
                Company profile updated successfully.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger mx-3 mt-3">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row g-3 px-3 pt-3">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-map fs-4 text-primary"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$stats['total_routes'] ?></div>
                            <div class="text-muted small">Total Routes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-signpost-2 fs-4 text-success"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$stats['active_routes'] ?></div>
                            <div class="text-muted small">Upcoming Routes</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-ticket-perforated fs-4 text-info"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$stats['total_bookings'] ?></div>
                            <div class="text-muted small">Total Bookings</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-currency-rupee fs-4 text-warning"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold">₹<?= number_format((float)$stats['total_revenue'], 0) ?></div>
                            <div class="text-muted small">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form + Status -->
        <div class="row g-3 px-3 pt-3 pb-4">

            <!-- Edit Form -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom fw-semibold py-3">
                        <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Company Details
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <h6 class="text-muted text-uppercase small fw-bold mb-3">Company Information</h6>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                                <input type="text" name="company_name" class="form-control"
                                       value="<?= htmlspecialchars($provider['company_name']) ?>" required>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Transport Type <span class="text-danger">*</span></label>
                                    <select name="transport_type" class="form-select" required>
                                        <?php foreach (['bus', 'train', 'ferry', 'cab'] as $t): ?>
                                            <option value="<?= $t ?>" <?= $provider['transport_type'] === $t ? 'selected' : '' ?>>
                                                <?= ucfirst($t) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">License Number <span class="text-danger">*</span></label>
                                    <input type="text" name="license_number" class="form-control"
                                           value="<?= htmlspecialchars($provider['license_number'] ?? '') ?>" required>
                                </div>
                            </div>

                            <hr class="my-4">
                            <h6 class="text-muted text-uppercase small fw-bold mb-3">Contact Information</h6>

                            <div class="row g-3 mb-3">
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Contact Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control"
                                           value="<?= htmlspecialchars($user['full_name']) ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label fw-semibold">Phone</label>
                                    <input type="text" name="phone" class="form-control"
                                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" class="form-control bg-light"
                                       value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                <div class="form-text">Email cannot be changed here. Contact admin to update.</div>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary px-4">
                                    <i class="bi bi-save me-1"></i>Save Changes
                                </button>
                                <a href="<?= BASE_URL ?>dashboards/transport_provider/index.php"
                                   class="btn btn-outline-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Status Panel -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white border-bottom fw-semibold py-3">
                        <i class="bi bi-shield-check me-2 text-success"></i>Account Status
                    </div>
                    <div class="card-body p-4">
                        <div class="mb-3">
                            <div class="text-muted small mb-1">Approval Status</div>
                            <span class="badge <?= $provider['is_approved'] ? 'bg-success' : 'bg-warning text-dark' ?> fs-6">
                                <?= $provider['is_approved'] ? 'Approved' : 'Pending Approval' ?>
                            </span>
                        </div>
                        <div class="mb-3">
                            <div class="text-muted small mb-1">Account Status</div>
                            <span class="badge <?= $status_badge[$provider['status']] ?? 'bg-secondary' ?> fs-6">
                                <?= ucfirst($provider['status']) ?>
                            </span>
                        </div>
                        <div class="mb-3">
                            <div class="text-muted small mb-1">Member Since</div>
                            <div class="fw-semibold"><?= date('d M Y', strtotime($provider['created_at'])) ?></div>
                        </div>
                        <?php if (!empty($provider['updated_at'])): ?>
                        <div>
                            <div class="text-muted small mb-1">Last Updated</div>
                            <div class="fw-semibold"><?= date('d M Y, h:i A', strtotime($provider['updated_at'])) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!$provider['is_approved']): ?>
                        <hr>
                        <div class="alert alert-warning mb-0 p-2 small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Your account is awaiting admin approval. Routes will not appear in search until approved.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white border-bottom fw-semibold py-3">
                        <i class="bi bi-link-45deg me-2 text-primary"></i>Quick Links
                    </div>
                    <div class="list-group list-group-flush rounded-bottom">
                        <a href="<?= BASE_URL ?>modules/transport/manage/routes.php"
                           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                            <i class="bi bi-map text-primary"></i> Manage Routes
                        </a>
                        <a href="<?= BASE_URL ?>modules/transport/manage/bookings.php"
                           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                            <i class="bi bi-ticket-perforated text-success"></i> View Bookings
                        </a>
                        <a href="<?= BASE_URL ?>auth/change_password.php"
                           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                            <i class="bi bi-key text-warning"></i> Change Password
                        </a>
                    </div>
                </div>
            </div>

        </div><!-- /row -->
    </div><!-- /main-content -->
</div><!-- /d-flex -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>