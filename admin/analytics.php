<?php
$page_title = 'Analytics Dashboard';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

// ── Revenue by month (last 12 months)
$stmt = $conn->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month,
        SUM(final_amount) AS revenue,
        COUNT(*) AS bookings
    FROM bookings
    WHERE payment_status = 'paid'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
");
$stmt->execute();
$monthly_revenue = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Bookings by type
$stmt = $conn->prepare("
    SELECT booking_type,
           COUNT(*) AS total,
           SUM(CASE WHEN payment_status='paid' THEN final_amount ELSE 0 END) AS revenue
    FROM bookings
    GROUP BY booking_type
");
$stmt->execute();
$by_type = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Top booked cities
$stmt = $conn->prepare("
    SELECT h.city, COUNT(b.id) AS bookings
    FROM bookings b
    JOIN booking_items bi ON bi.booking_id = b.id
    JOIN rooms r ON bi.item_id = r.id AND bi.item_type = 'room'
    JOIN hotels h ON r.hotel_id = h.id
    WHERE b.payment_status = 'paid'
    GROUP BY h.city
    ORDER BY bookings DESC
    LIMIT 8
");
$stmt->execute();
$top_cities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Most active guides
$stmt = $conn->prepare("
    SELECT u.full_name, g.city,
           COUNT(b.id) AS bookings,
           COALESCE(AVG(r.rating), 0) AS avg_rating
    FROM guides g
    JOIN users u ON g.user_id = u.id
    LEFT JOIN booking_items bi ON bi.item_id = g.id AND bi.item_type = 'guide_session'
    LEFT JOIN bookings b ON bi.booking_id = b.id AND b.payment_status = 'paid'
    LEFT JOIN reviews r ON r.entity_type = 'guide' AND r.entity_id = g.id
    GROUP BY g.id
    ORDER BY bookings DESC
    LIMIT 5
");
$stmt->execute();
$top_guides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── User growth (last 12 months)
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
           COUNT(*) AS new_users
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
");
$stmt->execute();
$user_growth = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Overall stats
$stmt = $conn->prepare("
    SELECT
        (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS total_users,
        (SELECT COUNT(*) FROM bookings WHERE payment_status='paid') AS total_bookings,
        (SELECT COALESCE(SUM(final_amount),0) FROM bookings
         WHERE payment_status='paid') AS total_revenue,
        (SELECT COUNT(*) FROM hotels WHERE is_approved=1) AS total_hotels,
        (SELECT COUNT(*) FROM guides WHERE is_verified=1) AS total_guides,
        (SELECT COUNT(*) FROM bookings WHERE booking_status='pending') AS pending_approvals
");
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Analytics Dashboard</h5>
            <span class="text-muted small">
                Last updated: <?= date('d M Y H:i') ?>
            </span>
        </div>

        <!-- KPI Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-primary border-4">
                    <div class="text-muted small">Total Users</div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['total_users']) ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-success border-4">
                    <div class="text-muted small">Total Revenue</div>
                    <div class="fs-4 fw-bold text-success">
                        ₹<?= number_format($stats['total_revenue'], 0) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-info border-4">
                    <div class="text-muted small">Paid Bookings</div>
                    <div class="fs-4 fw-bold">
                        <?= number_format($stats['total_bookings']) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-warning border-4">
                    <div class="text-muted small">Hotels</div>
                    <div class="fs-4 fw-bold"><?= $stats['total_hotels'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-secondary border-4">
                    <div class="text-muted small">Guides</div>
                    <div class="fs-4 fw-bold"><?= $stats['total_guides'] ?></div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stat-card p-3 border-start border-danger border-4">
                    <div class="text-muted small">Pending</div>
                    <div class="fs-4 fw-bold text-danger">
                        <?= $stats['pending_approvals'] ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 1 -->
        <div class="row g-4 mb-4">
            <!-- Monthly Revenue -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        Monthly Revenue (Last 12 Months)
                    </div>
                    <div class="card-body">
                        <canvas id="revenueChart" height="100"></canvas>
                    </div>
                </div>
            </div>

            <!-- Bookings by Type -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Bookings by Type</div>
                    <div class="card-body">
                        <canvas id="typeChart" height="200"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row 2 -->
        <div class="row g-4 mb-4">
            <!-- User Growth -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        User Growth (Last 12 Months)
                    </div>
                    <div class="card-body">
                        <canvas id="userChart" height="120"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top Cities -->
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        Top Booked Cities
                    </div>
                    <div class="card-body">
                        <canvas id="cityChart" height="120"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Guides & Pending Approvals -->
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">Most Active Guides</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Guide</th>
                                    <th>City</th>
                                    <th>Bookings</th>
                                    <th>Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_guides as $g): ?>
                                <tr>
                                    <td class="fw-semibold">
                                        <?= htmlspecialchars($g['full_name']) ?>
                                    </td>
                                    <td><?= htmlspecialchars($g['city'] ?? '-') ?></td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?= $g['bookings'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= number_format($g['avg_rating'], 1) ?>★
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold d-flex justify-content-between">
                        <span>Pending Approvals</span>
                        <a href="<?= BASE_URL ?>admin/hotels.php"
                           class="btn btn-sm btn-outline-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php
                        $stmt = $conn->prepare("
                            SELECT 'Hotel' as type, name as title, created_at
                            FROM hotels WHERE is_approved = 0 AND deleted_at IS NULL
                            UNION ALL
                            SELECT 'Guide' as type, u.full_name as title, g.created_at
                            FROM guides g JOIN users u ON g.user_id = u.id
                            WHERE g.is_verified = 0
                            UNION ALL
                            SELECT 'Transport' as type, company_name as title, created_at
                            FROM transport_providers WHERE is_approved = 0
                            ORDER BY created_at DESC LIMIT 8
                        ");
                        $stmt->execute();
                        $pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        ?>
                        <?php if (empty($pending)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-check-circle text-success fs-3"></i>
                            <p class="mt-2">All approvals are up to date!</p>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($pending as $item): ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <span class="badge <?=
                                $item['type'] === 'Hotel'     ? 'bg-primary'   :
                                ($item['type'] === 'Guide'    ? 'bg-success'   : 'bg-warning text-dark')
                            ?>"><?= $item['type'] ?></span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($item['title']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= date('d M Y', strtotime($item['created_at'])) ?>
                                </div>
                            </div>
                            <a href="<?= BASE_URL ?>admin/<?= strtolower($item['type']) ?>s.php"
                               class="btn btn-sm btn-outline-primary">Review</a>
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
// ── Revenue Chart
const revData = <?= json_encode($monthly_revenue) ?>;
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels:   revData.map(d => d.month),
        datasets: [{
            label:           'Revenue (₹)',
            data:            revData.map(d => parseFloat(d.revenue)),
            borderColor:     '#0d6efd',
            backgroundColor: 'rgba(13,110,253,0.1)',
            borderWidth:     2,
            fill:            true,
            tension:         0.4,
            pointRadius:     4,
            pointHoverRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            tooltip: {
                callbacks: {
                    label: ctx => '₹' + ctx.raw.toLocaleString('en-IN')
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

// ── Type Donut Chart
const typeData = <?= json_encode($by_type) ?>;
new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
        labels: typeData.map(d => d.booking_type.charAt(0).toUpperCase() + d.booking_type.slice(1)),
        datasets: [{
            data:            typeData.map(d => parseInt(d.total)),
            backgroundColor: ['#0d6efd','#198754','#ffc107','#0dcaf0'],
            borderWidth:     2,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

// ── User Growth Chart
const ugData = <?= json_encode($user_growth) ?>;
new Chart(document.getElementById('userChart'), {
    type: 'bar',
    data: {
        labels:   ugData.map(d => d.month),
        datasets: [{
            label:           'New Users',
            data:            ugData.map(d => parseInt(d.new_users)),
            backgroundColor: 'rgba(25,135,84,0.7)',
            borderColor:     '#198754',
            borderWidth:     1,
            borderRadius:    4,
        }]
    },
    options: {
        responsive: true,
        scales: { y: { beginAtZero: true } }
    }
});

// ── Top Cities Chart
const cityData = <?= json_encode($top_cities) ?>;
new Chart(document.getElementById('cityChart'), {
    type: 'bar',
    data: {
        labels:   cityData.map(d => d.city),
        datasets: [{
            label:           'Hotel Bookings',
            data:            cityData.map(d => parseInt(d.bookings)),
            backgroundColor: 'rgba(13,110,253,0.7)',
            borderColor:     '#0d6efd',
            borderWidth:     1,
            borderRadius:    4,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        scales: { x: { beginAtZero: true } }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>