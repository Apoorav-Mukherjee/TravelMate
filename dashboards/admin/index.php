<?php
$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../../includes/header.php';
require_role('admin');

// ── KPI Stats (safe individual queries) ─────────────────────
$total_users    = $conn->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetch_row()[0] ?? 0;
$new_today      = $conn->query("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE() AND deleted_at IS NULL")->fetch_row()[0] ?? 0;
$total_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE payment_status='paid'")->fetch_row()[0] ?? 0;
$total_revenue  = $conn->query("SELECT COALESCE(SUM(final_amount),0) FROM bookings WHERE payment_status='paid'")->fetch_row()[0] ?? 0;
$revenue_today  = $conn->query("SELECT COALESCE(SUM(final_amount),0) FROM bookings WHERE payment_status='paid' AND DATE(created_at)=CURDATE()")->fetch_row()[0] ?? 0;
$pending_bk     = $conn->query("SELECT COUNT(*) FROM bookings WHERE booking_status='pending'")->fetch_row()[0] ?? 0;
$active_hotels  = $conn->query("SELECT COUNT(*) FROM hotels WHERE is_approved=1")->fetch_row()[0] ?? 0;
$active_guides  = $conn->query("SELECT COUNT(*) FROM guides WHERE is_verified=1")->fetch_row()[0] ?? 0;
$active_provs   = $conn->query("SELECT COUNT(*) FROM transport_providers WHERE is_approved=1")->fetch_row()[0] ?? 0;
$month_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE payment_status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetch_row()[0] ?? 0;
$month_revenue  = $conn->query("SELECT COALESCE(SUM(final_amount),0) FROM bookings WHERE payment_status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetch_row()[0] ?? 0;

$stats = [
    'total_users'        => $total_users,
    'new_today'          => $new_today,
    'total_bookings'     => $total_bookings,
    'total_revenue'      => $total_revenue,
    'revenue_today'      => $revenue_today,
    'pending_bookings'   => $pending_bk,
    'active_hotels'      => $active_hotels,
    'active_guides'      => $active_guides,
    'active_providers'   => $active_provs,
    'bookings_this_month'=> $month_bookings,
    'revenue_this_month' => $month_revenue,
];


// ── Revenue last 7 days (safe)
$weekly = [];
$r = $conn->query("
    SELECT DATE(created_at) AS day,
           COUNT(*) AS bookings,
           SUM(final_amount) AS revenue
    FROM bookings
    WHERE payment_status='paid'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
if ($r) $weekly = $r->fetch_all(MYSQLI_ASSOC);

// ── Pending approvals (safe - checks actual column names)
$pending_items = [];

// Pending hotels
$r = $conn->query("SELECT 'Hotel' AS type, name AS title, created_at, id, 'hotels' AS admin_page
    FROM hotels WHERE is_approved=0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
if ($r) {
    while ($row = $r->fetch_assoc()) $pending_items[] = $row;
}

// Pending guides
$r = $conn->query("SELECT 'Guide' AS type, u.full_name AS title, g.created_at, g.id, 'guides' AS admin_page
    FROM guides g JOIN users u ON g.user_id=u.id
    WHERE g.status='pending' ORDER BY g.created_at DESC LIMIT 5");
if ($r) {
    while ($row = $r->fetch_assoc()) $pending_items[] = $row;
}

// Pending transport providers
$r = $conn->query("SELECT 'Transport' AS type, company_name AS title, created_at, id, 'transport' AS admin_page
    FROM transport_providers WHERE is_approved=0 ORDER BY created_at DESC LIMIT 5");
if ($r) {
    while ($row = $r->fetch_assoc()) $pending_items[] = $row;
}

// Pending packages (use title column from your actual schema)
$r = $conn->query("SELECT 'Package' AS type, title AS title, created_at, id, 'packages' AS admin_page
    FROM tour_packages WHERE is_approved=0 AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
if ($r) {
    while ($row = $r->fetch_assoc()) $pending_items[] = $row;
}

// Sort all by created_at desc and limit to 10
usort($pending_items, fn($a,$b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$pending_items = array_slice($pending_items, 0, 10);

// ── Recent bookings (safe)
$recent_bookings = [];
$r = $conn->query("
    SELECT b.*, u.full_name, u.email
    FROM bookings b
    JOIN users u ON b.user_id = u.id
    ORDER BY b.created_at DESC
    LIMIT 8
");
if ($r) $recent_bookings = $r->fetch_all(MYSQLI_ASSOC);

// ── Recent signups (safe)
$recent_users = [];
$r = $conn->query("
    SELECT u.*, r.name AS role_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.deleted_at IS NULL
    ORDER BY u.created_at DESC
    LIMIT 6
");
if ($r) $recent_users = $r->fetch_all(MYSQLI_ASSOC);

// ── Revenue by booking type this month
$stmt = $conn->prepare("
    SELECT booking_type,
           COUNT(*) AS cnt,
           SUM(final_amount) AS rev
    FROM bookings
    WHERE payment_status='paid'
      AND MONTH(created_at)=MONTH(NOW())
      AND YEAR(created_at)=YEAR(NOW())
    GROUP BY booking_type
");
$stmt->execute();
$type_breakdown = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── System health
$db_size = $conn->query("
    SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
")->fetch_assoc()['size_mb'];

$upload_size_mb = 0;
$upload_dir = UPLOAD_PATH;
if (is_dir($upload_dir)) {
    $total_bytes = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($upload_dir));
    foreach ($iterator as $file) {
        if ($file->isFile()) $total_bytes += $file->getSize();
    }
    $upload_size_mb = round($total_bytes / 1024 / 1024, 2);
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <div>
                <h5 class="mb-0">Admin Dashboard</h5>
                <small class="text-muted">
                    <?= date('l, d F Y') ?> &mdash; Welcome back,
                    <strong><?= htmlspecialchars($_SESSION['full_name']) ?></strong>
                </small>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>admin/analytics.php"
                   class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-bar-chart-line"></i> Analytics
                </a>
                <a href="<?= BASE_URL ?>auth/logout.php"
                   class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>

        <!-- ── KPI Row 1 ─────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-4"
                     style="border-left:4px solid #0d6efd">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-muted small">Total Users</div>
                            <div class="fs-2 fw-bold"><?= number_format($stats['total_users']) ?></div>
                            <div class="text-success small">
                                +<?= $stats['new_today'] ?> today
                            </div>
                        </div>
                        <i class="bi bi-people fs-1 text-primary opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4"
                     style="border-left:4px solid #198754">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-muted small">Total Revenue</div>
                            <div class="fs-2 fw-bold text-success">
                                ₹<?= number_format($stats['total_revenue'], 0) ?>
                            </div>
                            <div class="text-success small">
                                ₹<?= number_format($stats['revenue_today'], 0) ?> today
                            </div>
                        </div>
                        <i class="bi bi-currency-rupee fs-1 text-success opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4"
                     style="border-left:4px solid #ffc107">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-muted small">This Month</div>
                            <div class="fs-2 fw-bold">
                                <?= number_format($stats['bookings_this_month']) ?>
                            </div>
                            <div class="text-muted small">
                                ₹<?= number_format($stats['revenue_this_month'], 0) ?>
                            </div>
                        </div>
                        <i class="bi bi-calendar-check fs-1 text-warning opacity-25"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4"
                     style="border-left:4px solid #dc3545">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="text-muted small">Pending Actions</div>
                            <div class="fs-2 fw-bold text-danger">
                                <?= $stats['pending_bookings'] ?>
                            </div>
                            <div class="text-muted small">bookings pending</div>
                        </div>
                        <i class="bi bi-hourglass fs-1 text-danger opacity-25"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── KPI Row 2 ─────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-primary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Active Hotels</div>
                            <div class="fs-4 fw-bold"><?= $stats['active_hotels'] ?></div>
                        </div>
                        <a href="<?= BASE_URL ?>admin/hotels.php"
                           class="btn btn-sm btn-outline-primary">Manage</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Verified Guides</div>
                            <div class="fs-4 fw-bold"><?= $stats['active_guides'] ?></div>
                        </div>
                        <a href="<?= BASE_URL ?>admin/guides.php"
                           class="btn btn-sm btn-outline-success">Manage</a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-3 border-start border-warning border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Transport Providers</div>
                            <div class="fs-4 fw-bold"><?= $stats['active_providers'] ?></div>
                        </div>
                        <a href="<?= BASE_URL ?>admin/transport.php"
                           class="btn btn-sm btn-outline-warning">Manage</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Charts + Pending ──────────────────────────── -->
        <div class="row g-4 mb-4">

            <!-- 7-Day Revenue Sparkline -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between">
                        <span>Revenue — Last 7 Days</span>
                        <span class="text-muted small">
                            ₹<?= number_format(array_sum(array_column($weekly, 'revenue')), 0) ?>
                            total
                        </span>
                    </div>
                    <div class="card-body">
                        <canvas id="weeklyChart" height="140"></canvas>
                    </div>
                </div>
            </div>

            <!-- Booking type breakdown -->
            <div class="col-lg-3">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">This Month by Type</div>
                    <div class="card-body">
                        <canvas id="typeChart" height="160"></canvas>
                        <?php if (empty($type_breakdown)): ?>
                        <div class="text-center text-muted small mt-2">
                            No data this month
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pending approvals -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between">
                        <span>Pending Approvals</span>
                        <?php if (!empty($pending_items)): ?>
                        <span class="badge bg-danger"><?= count($pending_items) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0"
                         style="max-height:280px;overflow-y:auto">
                        <?php if (empty($pending_items)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check-circle text-success fs-2"></i>
                            <p class="mt-2 mb-0">All caught up!</p>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($pending_items as $item): ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <span class="badge flex-shrink-0 <?=
                                $item['type'] === 'Hotel'     ? 'bg-primary'   :
                                ($item['type'] === 'Guide'    ? 'bg-success'   :
                                ($item['type'] === 'Package'  ? 'bg-info'      : 'bg-warning text-dark'))
                            ?>"><?= $item['type'] ?></span>
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="fw-semibold small text-truncate">
                                    <?= htmlspecialchars($item['title']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= date('d M Y H:i', strtotime($item['created_at'])) ?>
                                </div>
                            </div>
                            <a href="<?= BASE_URL ?>admin/<?= $item['admin_page'] ?>.php"
                               class="btn btn-sm btn-outline-primary flex-shrink-0">
                                Review
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── Recent Bookings + Signups ─────────────────── -->
        <div class="row g-4 mb-4">

            <!-- Recent Bookings -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between">
                        <span>Recent Bookings</span>
                        <a href="<?= BASE_URL ?>admin/payments.php"
                           class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ref</th>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_bookings as $b): ?>
                                <tr>
                                    <td>
                                        <code style="font-size:11px">
                                            <?= $b['booking_ref'] ?>
                                        </code>
                                    </td>
                                    <td>
                                        <div class="fw-semibold small">
                                            <?= htmlspecialchars($b['full_name']) ?>
                                        </div>
                                        <div class="text-muted" style="font-size:10px">
                                            <?= htmlspecialchars($b['email']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"
                                              style="font-size:10px">
                                            <?= ucfirst($b['booking_type']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold small">
                                        ₹<?= number_format($b['final_amount'], 0) ?>
                                    </td>
                                    <td>
                                        <span class="badge <?=
                                            $b['booking_status']==='confirmed'  ? 'bg-success'   :
                                            ($b['booking_status']==='pending'   ? 'bg-warning text-dark' :
                                            ($b['booking_status']==='cancelled' ? 'bg-danger'    : 'bg-info'))
                                        ?>" style="font-size:10px">
                                            <?= ucfirst($b['booking_status']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Signups -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between">
                        <span>Recent Signups</span>
                        <a href="<?= BASE_URL ?>admin/users.php"
                           class="btn btn-sm btn-outline-secondary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($recent_users as $u): ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <img src="<?= BASE_URL ?>assets/uploads/<?= htmlspecialchars($u['profile_picture'] ?? 'default.png') ?>"
                                 class="rounded-circle"
                                 style="width:38px;height:38px;object-fit:cover">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($u['full_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= htmlspecialchars($u['role_name']) ?> &bull;
                                    <?= date('d M Y', strtotime($u['created_at'])) ?>
                                </div>
                            </div>
                            <span class="badge <?=
                                $u['status']==='active' ? 'bg-success' : 'bg-warning text-dark'
                            ?>" style="font-size:10px">
                                <?= ucfirst($u['status']) ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── System Health ─────────────────────────────── -->
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">System Health</div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">PHP Version</span>
                            <span class="fw-semibold"><?= PHP_VERSION ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Database Size</span>
                            <span class="fw-semibold"><?= $db_size ?> MB</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Upload Storage</span>
                            <span class="fw-semibold"><?= $upload_size_mb ?> MB</span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Server Time</span>
                            <span class="fw-semibold"><?= date('H:i:s') ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Memory Usage</span>
                            <span class="fw-semibold">
                                <?= round(memory_get_usage(true) / 1024 / 1024, 2) ?> MB
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Quick Actions</div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="<?= BASE_URL ?>admin/users.php?action=export"
                               class="btn btn-outline-primary">
                                <i class="bi bi-download"></i> Export Users CSV
                            </a>
                            <a href="<?= BASE_URL ?>admin/bookings_export.php"
                               class="btn btn-outline-success">
                                <i class="bi bi-download"></i> Export Bookings CSV
                            </a>
                            <a href="<?= BASE_URL ?>admin/logs.php"
                               class="btn btn-outline-secondary">
                                <i class="bi bi-journal-text"></i> View Admin Logs
                            </a>
                            <a href="<?= BASE_URL ?>admin/reviews.php"
                               class="btn btn-outline-warning">
                                <i class="bi bi-star"></i> Moderate Reviews
                                <?php if ($pending_reviews > 0): ?>
                                <span class="badge bg-danger"><?= $pending_reviews ?></span>
                                <?php endif; ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Platform Summary</div>
                    <div class="card-body p-0">
                        <?php
                        $summary = [
                            ['Hotels',             $stats['active_hotels'],    'bi-building',     'primary'],
                            ['Guides',             $stats['active_guides'],    'bi-person-badge', 'success'],
                            ['Transport Providers',$stats['active_providers'], 'bi-bus-front',    'warning'],
                            ['Total Bookings',     $stats['total_bookings'],   'bi-calendar-check','info'],
                            ['Pending Bookings',   $stats['pending_bookings'], 'bi-hourglass',    'danger'],
                        ];
                        foreach ($summary as [$label, $value, $icon, $color]): ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <div class="rounded-circle bg-<?= $color ?> bg-opacity-10
                                        d-flex align-items-center justify-content-center"
                                 style="width:36px;height:36px">
                                <i class="bi <?= $icon ?> text-<?= $color ?>"></i>
                            </div>
                            <div class="flex-grow-1 small"><?= $label ?></div>
                            <div class="fw-bold"><?= number_format($value) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ── 7-Day Revenue Chart
const weeklyData = <?= json_encode($weekly) ?>;
new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: {
        labels:   weeklyData.map(d => {
            const dt = new Date(d.day);
            return dt.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric' });
        }),
        datasets: [{
            label:           'Revenue (₹)',
            data:            weeklyData.map(d => parseFloat(d.revenue) || 0),
            backgroundColor: weeklyData.map((_, i) =>
                i === weeklyData.length - 1
                    ? 'rgba(13,110,253,0.9)'
                    : 'rgba(13,110,253,0.4)'
            ),
            borderColor:     '#0d6efd',
            borderWidth:     1,
            borderRadius:    6,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx =>
                        '₹' + ctx.raw.toLocaleString('en-IN') +
                        ' (' + (weeklyData[ctx.dataIndex]?.bookings || 0) + ' bookings)'
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { callback: v => '₹' + v.toLocaleString('en-IN') }
            }
        }
    }
});

// ── Type Doughnut Chart
const typeData = <?= json_encode($type_breakdown) ?>;
if (typeData.length > 0) {
    new Chart(document.getElementById('typeChart'), {
        type: 'doughnut',
        data: {
            labels: typeData.map(d =>
                d.booking_type.charAt(0).toUpperCase() + d.booking_type.slice(1)),
            datasets: [{
                data:            typeData.map(d => parseInt(d.cnt)),
                backgroundColor: ['#0d6efd','#198754','#ffc107','#0dcaf0'],
                borderWidth:     2,
                hoverOffset:     6,
            }]
        },
        options: {
            responsive: true,
            cutout: '65%',
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } }
            }
        }
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>