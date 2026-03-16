<?php
$page_title = 'Hotel Bookings';
require_once __DIR__ . '/../../../includes/header.php';
require_role('hotel_staff');

$user_id = $_SESSION['user_id'];

// Get hotel owned by this staff
$stmt = $conn->prepare("SELECT id, name FROM hotels WHERE owner_id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$hotel = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$hotel) {
    set_flash('error', 'Please add your hotel first.');
    redirect('modules/hotels/manage/create.php');
}

$hotel_id = $hotel['id'];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $new_status = sanitize($_POST['new_status'] ?? '');
    $allowed_statuses = ['confirmed', 'cancelled', 'completed'];

    if ($booking_id && in_array($new_status, $allowed_statuses)) {
        // Verify booking belongs to this hotel
        $stmt = $conn->prepare("
            SELECT b.id FROM bookings b
            JOIN booking_items bi ON bi.booking_id = b.id
            JOIN rooms r ON bi.item_id = r.id AND bi.item_type = 'room'
            WHERE b.id = ? AND r.hotel_id = ?
            LIMIT 1
        ");
        $stmt->bind_param('ii', $booking_id, $hotel_id);
        $stmt->execute();
        $valid = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($valid) {
            $stmt = $conn->prepare("UPDATE bookings SET booking_status = ? WHERE id = ?");
            $stmt->bind_param('si', $new_status, $booking_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', 'Booking status updated to ' . ucfirst($new_status) . '.');
        }
    }
    redirect('modules/hotels/manage/bookings.php');
}

// Filters
$status_filter = sanitize($_GET['status'] ?? '');
$date_filter   = sanitize($_GET['date']   ?? '');
$search        = sanitize($_GET['search'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

// Build WHERE
$where  = ["r.hotel_id = ?"];
$params = [$hotel_id];
$types  = 'i';

if ($status_filter) {
    $where[]  = "b.booking_status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($date_filter) {
    $where[]  = "DATE(b.check_in) = ?";
    $params[] = $date_filter;
    $types   .= 's';
}
if ($search) {
    $where[]  = "(u.full_name LIKE ? OR b.booking_ref LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}

$where_sql = implode(' AND ', $where);

// Count
$count_sql = "
    SELECT COUNT(DISTINCT b.id) as total
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'room'
    JOIN rooms r  ON bi.item_id = r.id
    JOIN users u  ON b.user_id  = u.id
    WHERE $where_sql
";
$stmt = $conn->prepare($count_sql);
if (!$stmt) {
    die('Count query error: ' . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// Fetch bookings
$sql = "
    SELECT b.*,
           u.full_name, u.email, u.profile_picture,
           r.room_type,
           bi.quantity, bi.unit_price,
           p.status      AS payment_status,
           p.gateway     AS payment_method
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'room'
    JOIN rooms r  ON bi.item_id  = r.id
    JOIN users u  ON b.user_id   = u.id
    LEFT JOIN payments p ON p.booking_id = b.id
    WHERE $where_sql
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
";
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('Fetch query error: ' . $conn->error);
}
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$stmt = $conn->prepare("
    SELECT
        COUNT(DISTINCT b.id)                                            AS total_bookings,
        SUM(CASE WHEN b.booking_status = 'confirmed'  THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN b.booking_status = 'pending'    THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN b.booking_status = 'cancelled'  THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN b.booking_status = 'completed'  THEN 1 ELSE 0 END) AS completed,
        COALESCE(SUM(b.total_amount), 0)                                AS total_revenue
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id AND bi.item_type = 'room'
    JOIN rooms r ON bi.item_id = r.id
    WHERE r.hotel_id = ?
      AND b.booking_status != 'cancelled'
");
$stmt->bind_param('i', $hotel_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../../dashboards/hotel_staff/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Hotel Bookings — <?= htmlspecialchars($hotel['name']) ?></h5>
        </div>

        <?php $flash = get_flash(); ?>
        <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> mx-3 mt-3 mb-0">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
        <?php endif; ?>

        <div class="p-3 p-md-4">

            <!-- Stats Row -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm text-center p-3">
                        <div class="fs-3 fw-bold text-primary"><?= $stats['total_bookings'] ?></div>
                        <div class="text-muted small">Total Bookings</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm text-center p-3">
                        <div class="fs-3 fw-bold text-warning"><?= $stats['pending'] ?></div>
                        <div class="text-muted small">Pending</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm text-center p-3">
                        <div class="fs-3 fw-bold text-success"><?= $stats['confirmed'] ?></div>
                        <div class="text-muted small">Confirmed</div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm text-center p-3">
                        <div class="fs-3 fw-bold text-success">
                            ₹<?= number_format($stats['total_revenue'], 0) ?>
                        </div>
                        <div class="text-muted small">Total Revenue</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Guest name or booking ref"
                                   value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All</option>
                                <?php foreach (['pending','confirmed','cancelled','completed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                                    <?= ucfirst($s) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold small">Check-in Date</label>
                            <input type="date" name="date" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($date_filter) ?>">
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-search"></i> Filter
                            </button>
                            <a href="<?= BASE_URL ?>modules/hotels/manage/bookings.php"
                               class="btn btn-outline-secondary btn-sm w-100">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Bookings Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref</th>
                                    <th>Guest</th>
                                    <th>Room</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Nights</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="10" class="text-center text-muted py-4">
                                        No bookings found.
                                    </td>
                                </tr>
                                <?php endif; ?>

                                <?php foreach ($bookings as $b): ?>
                                <?php
                                $nights = 1;
                                if ($b['check_in'] && $b['check_out']) {
                                    $nights = max(1, (int)((strtotime($b['check_out']) - strtotime($b['check_in'])) / 86400));
                                }
                                $status_colors = [
                                    'pending'   => 'warning',
                                    'confirmed' => 'success',
                                    'cancelled' => 'danger',
                                    'completed' => 'secondary',
                                ];
                                $color = $status_colors[$b['booking_status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td>
                                        <span class="fw-semibold text-primary small">
                                            <?= htmlspecialchars($b['booking_ref']) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?= date('d M', strtotime($b['created_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($b['profile_picture'] ?: 'default.png') ?>"
                                                 class="rounded-circle"
                                                 width="32" height="32" style="object-fit:cover">
                                            <div>
                                                <div class="fw-semibold small">
                                                    <?= htmlspecialchars($b['full_name']) ?>
                                                </div>
                                                <div class="text-muted" style="font-size:11px">
                                                    <?= htmlspecialchars($b['email']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="small"><?= htmlspecialchars($b['room_type']) ?></td>
                                    <td class="small">
                                        <?= $b['check_in'] ? date('d M Y', strtotime($b['check_in'])) : '—' ?>
                                    </td>
                                    <td class="small">
                                        <?= $b['check_out'] ? date('d M Y', strtotime($b['check_out'])) : '—' ?>
                                    </td>
                                    <td class="text-center small"><?= $nights ?></td>
                                    <td class="fw-semibold small">
                                        ₹<?= number_format($b['total_amount'], 0) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $pay_color = match($b['payment_status'] ?? '') {
                                            'paid'    => 'success',
                                            'pending' => 'warning',
                                            'refunded'=> 'info',
                                            default   => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?= $pay_color ?>">
                                            <?= ucfirst($b['payment_status'] ?? 'N/A') ?>
                                        </span>
                                        <?php if ($b['payment_method']): ?>
                                        <div style="font-size:10px" class="text-muted">
                                            <?= htmlspecialchars($b['payment_method']) ?>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $color ?>">
                                            <?= ucfirst($b['booking_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($b['booking_status'] === 'pending'): ?>
                                        <!-- Confirm -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token"   value="<?= csrf_token() ?>">
                                            <input type="hidden" name="booking_id"   value="<?= $b['id'] ?>">
                                            <input type="hidden" name="new_status"   value="confirmed">
                                            <button type="submit" class="btn btn-success btn-sm"
                                                    onclick="return confirm('Confirm this booking?')">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        </form>
                                        <!-- Cancel -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
                                            <input type="hidden" name="booking_id"  value="<?= $b['id'] ?>">
                                            <input type="hidden" name="new_status"  value="cancelled">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Cancel this booking?')">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($b['booking_status'] === 'confirmed'): ?>
                                        <!-- Mark Complete -->
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
                                            <input type="hidden" name="booking_id"  value="<?= $b['id'] ?>">
                                            <input type="hidden" name="new_status"  value="completed">
                                            <button type="submit" class="btn btn-secondary btn-sm"
                                                    onclick="return confirm('Mark as completed?')">
                                                <i class="bi bi-flag-fill"></i> Done
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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

        </div><!-- /p-4 -->
    </div><!-- /main-content -->
</div><!-- /d-flex -->

<?php include __DIR__ . '/../../../includes/footer.php'; ?>