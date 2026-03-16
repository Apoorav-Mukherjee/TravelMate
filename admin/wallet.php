<?php
$page_title = 'Wallet Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

// Platform earnings (commission from guides)
$stmt = $conn->prepare("
    SELECT
        COALESCE(SUM(
            CASE WHEN b.payment_status = 'paid' AND b.booking_type = 'guide'
            THEN (
                SELECT bi2.subtotal * (g.commission_rate / 100)
                FROM booking_items bi2
                JOIN guides g ON bi2.item_id = g.id
                WHERE bi2.booking_id = b.id AND bi2.item_type = 'guide_session'
                LIMIT 1
            )
            ELSE 0 END
        ), 0) AS guide_commission,

        COALESCE(SUM(
            CASE WHEN b.payment_status = 'paid' THEN b.final_amount ELSE 0 END
        ), 0) AS total_revenue,

        COUNT(DISTINCT CASE WHEN b.payment_status = 'paid' THEN b.id END) AS paid_bookings,

        COALESCE(SUM(
            CASE WHEN b.payment_status = 'refunded' THEN b.final_amount ELSE 0 END
        ), 0) AS total_refunded
    FROM bookings b
");
$stmt->execute();
$platform = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Total wallet balance across all users
$stmt = $conn->prepare("
    SELECT
        COUNT(*) as user_count,
        COALESCE(SUM(wallet_balance), 0) as total_wallet
    FROM users WHERE deleted_at IS NULL
");
$stmt->execute();
$wallet_stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Monthly revenue chart data (last 12 months)
$stmt = $conn->prepare("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS month,
        SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS credits,
        SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END) AS debits
    FROM wallet_transactions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month
    ORDER BY month ASC
");
$stmt->execute();
$monthly_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Recent transactions (all users)
$stmt = $conn->prepare("
    SELECT wt.*, u.full_name, u.email
    FROM wallet_transactions wt
    JOIN users u ON wt.user_id = u.id
    ORDER BY wt.created_at DESC
    LIMIT 30
");
$stmt->execute();
$recent_txns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Top users by wallet balance
$stmt = $conn->prepare("
    SELECT id, full_name, email, wallet_balance
    FROM users
    WHERE deleted_at IS NULL AND wallet_balance > 0
    ORDER BY wallet_balance DESC
    LIMIT 10
");
$stmt->execute();
$top_users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Wallet & Payment Management</h5>
        </div>

        <!-- Platform Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-success border-4">
                    <div class="text-muted small">Total Revenue</div>
                    <div class="fs-3 fw-bold text-success">
                        ₹<?= number_format($platform['total_revenue'], 2) ?>
                    </div>
                    <div class="text-muted small">
                        <?= $platform['paid_bookings'] ?> paid bookings
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-primary border-4">
                    <div class="text-muted small">Guide Commissions</div>
                    <div class="fs-3 fw-bold text-primary">
                        ₹<?= number_format($platform['guide_commission'], 2) ?>
                    </div>
                    <div class="text-muted small">Platform earnings</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-warning border-4">
                    <div class="text-muted small">Total Wallet Holdings</div>
                    <div class="fs-3 fw-bold text-warning">
                        ₹<?= number_format($wallet_stats['total_wallet'], 2) ?>
                    </div>
                    <div class="text-muted small">
                        Across <?= $wallet_stats['user_count'] ?> users
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-4 border-start border-danger border-4">
                    <div class="text-muted small">Total Refunded</div>
                    <div class="fs-3 fw-bold text-danger">
                        ₹<?= number_format($platform['total_refunded'], 2) ?>
                    </div>
                    <div class="text-muted small">Cancellations</div>
                </div>
            </div>
        </div>

        <!-- Monthly Chart -->
        <div class="row g-4 mb-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold">
                        Wallet Flow — Last 12 Months
                    </div>
                    <div class="card-body">
                        <canvas id="walletChart" height="100"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">Top Wallet Balances</div>
                    <div class="card-body p-0">
                        <?php foreach ($top_users as $u): ?>
                        <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                            <div class="flex-grow-1">
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($u['full_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= htmlspecialchars($u['email']) ?>
                                </div>
                            </div>
                            <div class="fw-bold text-success">
                                ₹<?= number_format($u['wallet_balance'], 2) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Recent Wallet Transactions</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Balance After</th>
                            <th>Description</th>
                            <th>Ref</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_txns as $txn): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($txn['full_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= htmlspecialchars($txn['email']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $txn['type'] === 'credit'
                                    ? 'bg-success' : 'bg-danger' ?>">
                                    <?= ucfirst($txn['type']) ?>
                                </span>
                            </td>
                            <td class="fw-bold <?= $txn['type'] === 'credit'
                                ? 'text-success' : 'text-danger' ?>">
                                <?= $txn['type'] === 'credit' ? '+' : '-' ?>
                                ₹<?= number_format($txn['amount'], 2) ?>
                            </td>
                            <td>₹<?= number_format($txn['balance_after'], 2) ?></td>
                            <td>
                                <small><?= htmlspecialchars($txn['description'] ?? '') ?></small>
                            </td>
                            <td>
                                <?php if ($txn['ref_id']): ?>
                                <small class="text-muted">
                                    <?= ucfirst($txn['ref_type']) ?> #<?= $txn['ref_id'] ?>
                                </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('d M Y H:i', strtotime($txn['created_at'])) ?>
                                </small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const monthlyData = <?= json_encode($monthly_data) ?>;

const labels  = monthlyData.map(d => d.month);
const credits = monthlyData.map(d => parseFloat(d.credits));
const debits  = monthlyData.map(d => parseFloat(d.debits));

new Chart(document.getElementById('walletChart'), {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            {
                label: 'Credits (Money In)',
                data: credits,
                backgroundColor: 'rgba(25,135,84,0.7)',
                borderColor: '#198754',
                borderWidth: 1,
                borderRadius: 4,
            },
            {
                label: 'Debits (Money Out)',
                data: debits,
                backgroundColor: 'rgba(220,53,69,0.7)',
                borderColor: '#dc3545',
                borderWidth: 1,
                borderRadius: 4,
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: ctx => '₹' + ctx.raw.toLocaleString('en-IN', {
                        minimumFractionDigits: 2
                    })
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: val => '₹' + val.toLocaleString('en-IN')
                }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>