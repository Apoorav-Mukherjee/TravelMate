<?php
$page_title = 'My Bookings';
require_once __DIR__ . '/../../../includes/header.php';
require_role('guide');

$user_id = $_SESSION['user_id'];

// Fetch guide record
$stmt = $conn->prepare("SELECT * FROM guides WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$guide = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guide) {
    set_flash('error', 'Guide profile not found. Please create your profile first.');
    redirect('modules/guides/manage/profile.php');
}

$guide_id = $guide['id'];

// Filters
$filter_status = sanitize($_GET['status'] ?? '');
$search        = sanitize($_GET['search'] ?? '');

// Pagination
$per_page     = 15;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// Build WHERE
$where   = "WHERE bi.item_id = ? AND bi.item_type = 'guide_session'";
$types   = "i";
$params  = [$guide_id];

if ($filter_status !== '') {
    $where   .= " AND b.booking_status = ?";
    $types   .= "s";
    $params[] = $filter_status;
}
if ($search !== '') {
    $where   .= " AND (b.booking_ref LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
    $types   .= "sss";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$where .= " AND b.notes != '[__deleted__]'";

// Count
$count_sql = "
    SELECT COUNT(DISTINCT b.id)
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN users u           ON u.id = b.user_id
    $where
";
$stmt = $conn->prepare($count_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total_rows = $stmt->get_result()->fetch_row()[0];
$stmt->close();

$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch
$fetch_sql = "
    SELECT
        b.id,
        b.booking_ref,
        b.booking_status,
        b.payment_status,
        b.final_amount,
        b.check_in,
        b.check_out,
        b.notes,
        b.created_at,
        u.full_name,
        u.email,
        u.phone,
        bi.unit_price,
        bi.quantity,
        bi.subtotal,
        bi.meta
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN users u           ON u.id = b.user_id
    $where
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
";
$types_fetch  = $types . "ii";
$params_fetch = array_merge($params, [$per_page, $offset]);
$stmt = $conn->prepare($fetch_sql);
$stmt->bind_param($types_fetch, ...$params_fetch);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Summary stats
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT b.id)                                                                 AS total,
        SUM(CASE WHEN b.booking_status = 'confirmed'  THEN 1 ELSE 0 END)                    AS confirmed,
        SUM(CASE WHEN b.booking_status = 'pending'    THEN 1 ELSE 0 END)                    AS pending,
        SUM(CASE WHEN b.booking_status = 'cancelled'  THEN 1 ELSE 0 END)                    AS cancelled,
        COALESCE(SUM(CASE WHEN b.booking_status != 'cancelled' THEN b.final_amount END), 0) AS revenue
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    WHERE bi.item_id = ? AND bi.item_type = 'guide_session'
    AND b.notes != '[__deleted__]'
");
$stmt->bind_param("i", $guide_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$status_badge = [
    'pending'   => 'bg-warning text-dark',
    'confirmed' => 'bg-success',
    'cancelled' => 'bg-danger',
    'completed' => 'bg-secondary',
];
$payment_badge = [
    'pending'  => 'bg-warning text-dark',
    'paid'     => 'bg-success',
    'failed'   => 'bg-danger',
    'refunded' => 'bg-info',
];
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/guide/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>My Bookings</h5>
        </div>

        <!-- Summary Stats -->
        <div class="row g-3 px-3 pt-3">
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-journals fs-4 text-primary"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['total'] ?></div>
                            <div class="text-muted small">Total Bookings</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-success bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-check-circle fs-4 text-success"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['confirmed'] ?></div>
                            <div class="text-muted small">Confirmed</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-warning bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-hourglass-split fs-4 text-warning"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold"><?= (int)$summary['pending'] ?></div>
                            <div class="text-muted small">Pending</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="bg-info bg-opacity-10 rounded-3 p-3">
                            <i class="bi bi-currency-rupee fs-4 text-info"></i>
                        </div>
                        <div>
                            <div class="fs-4 fw-bold">₹<?= number_format((float)$summary['revenue'], 0) ?></div>
                            <div class="text-muted small">Total Revenue</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="px-3 pt-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body py-2">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Booking ref, name or email"
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <?php foreach (['pending', 'confirmed', 'cancelled', 'completed'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                                        <?= ucfirst($s) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search me-1"></i>Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="<?= BASE_URL ?>modules/guides/manage/bookings.php"
                               class="btn btn-outline-secondary btn-sm w-100">
                                <i class="bi bi-x-circle me-1"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Bookings Table -->
        <div class="px-3 pt-3 pb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Booking Ref</th>
                                <th>Traveler</th>
                                <th>Date</th>
                                <th>Duration</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Booked On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        No bookings found.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($bookings as $b): ?>
                                <?php
                                $meta = is_array(json_decode($b['meta'], true)) ? json_decode($b['meta'], true) : [];
                                ?>
                                <tr>
                                    <td>
                                        <a href="<?= BASE_URL ?>modules/bookings/view.php?ref=<?= urlencode($b['booking_ref']) ?>"
                                           class="fw-semibold text-decoration-none">
                                            <?= htmlspecialchars($b['booking_ref']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($b['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($b['email']) ?></small>
                                        <?php if ($b['phone']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($b['phone']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['check_in']): ?>
                                            <?= date('d M Y', strtotime($b['check_in'])) ?>
                                            <?php if ($b['check_out'] && $b['check_out'] !== $b['check_in']): ?>
                                                <br>
                                                <small class="text-muted">
                                                    to <?= date('d M Y', strtotime($b['check_out'])) ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['quantity'] > 1): ?>
                                            <?= $b['quantity'] ?> hrs
                                        <?php elseif (!empty($meta['booking_type'])): ?>
                                            <?= htmlspecialchars(ucfirst($meta['booking_type'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>₹<?= number_format((float)$b['final_amount'], 0) ?></td>
                                    <td>
                                        <span class="badge <?= $payment_badge[$b['payment_status']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($b['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= $status_badge[$b['booking_status']] ?? 'bg-secondary' ?>">
                                            <?= ucfirst($b['booking_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small><?= date('d M Y', strtotime($b['created_at'])) ?></small>
                                        <br>
                                        <small class="text-muted"><?= date('h:i A', strtotime($b['created_at'])) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-2 px-3">
                    <small class="text-muted">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_rows) ?> of <?= $total_rows ?> bookings
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page - 1 ?>&status=<?= urlencode($filter_status) ?>&search=<?= urlencode($search) ?>">
                                        &laquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                            <?php for ($p = max(1, $current_page - 2); $p <= min($total_pages, $current_page + 2); $p++): ?>
                                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $p ?>&status=<?= urlencode($filter_status) ?>&search=<?= urlencode($search) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $current_page + 1 ?>&status=<?= urlencode($filter_status) ?>&search=<?= urlencode($search) ?>">
                                        &raquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div><!-- /main-content -->
</div><!-- /d-flex -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>