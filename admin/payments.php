<?php
$page_title = 'Payment Management';
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

$filter = sanitize($_GET['filter'] ?? 'all');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$where  = '1=1';
$params = [];
$types  = '';

if ($filter !== 'all') {
    $where   = 'p.status = ?';
    $params[] = $filter;
    $types   .= 's';
}

// Count
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM payments p WHERE $where");
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
$total_pages = ceil($total / $per_page);

// Fetch
$limit_params = array_merge($params, [$per_page, $offset]);
$limit_types  = $types . 'ii';
$stmt = $conn->prepare("
    SELECT p.*, u.full_name, u.email,
           b.booking_ref, b.booking_type
    FROM payments p
    JOIN users u    ON p.user_id    = u.id
    JOIN bookings b ON p.booking_id = b.id
    WHERE $where
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param($limit_types, ...$limit_params);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Payment stats
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN status = 'success'  THEN amount ELSE 0 END) AS total_success,
        SUM(CASE WHEN status = 'refunded' THEN refund_amount ELSE 0 END) AS total_refunds,
        COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending_count
    FROM payments
");
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Manually process a failed payment (mark as success — admin override)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pay_id = (int)($_POST['payment_id'] ?? 0);
    $action = sanitize($_POST['action'] ?? '');

    if ($pay_id && $action === 'verify') {
        $stmt = $conn->prepare("
            UPDATE payments SET status = 'success' WHERE id = ?
        ");
        $stmt->bind_param('i', $pay_id);
        $stmt->execute();
        $stmt->close();

        // Also confirm the booking
        $stmt = $conn->prepare("
            UPDATE bookings SET payment_status = 'paid', booking_status = 'confirmed'
            WHERE id = (SELECT booking_id FROM payments WHERE id = ?)
        ");
        $stmt->bind_param('i', $pay_id);
        $stmt->execute();
        $stmt->close();

        log_admin_action($conn, $_SESSION['user_id'],
            'Manual payment verification', 'payment', $pay_id);
        set_flash('success', 'Payment verified and booking confirmed.');
    } elseif ($pay_id && $action === 'refund') {
        // Manual refund
        $stmt = $conn->prepare("
            SELECT p.*, b.user_id, b.final_amount, b.booking_ref
            FROM payments p JOIN bookings b ON p.booking_id = b.id
            WHERE p.id = ?
        ");
        $stmt->bind_param('i', $pay_id);
        $stmt->execute();
        $pay = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($pay && $pay['status'] === 'success') {
            $conn->begin_transaction();
            try {
                // Mark payment as refunded
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET status = 'refunded',
                        refund_amount = amount,
                        refunded_at = NOW()
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $pay_id);
                $stmt->execute();
                $stmt->close();

                // Mark booking as refunded
                $stmt = $conn->prepare("
                    UPDATE bookings
                    SET payment_status = 'refunded', booking_status = 'cancelled'
                    WHERE id = ?
                ");
                $stmt->bind_param('i', $pay['booking_id']);
                $stmt->execute();
                $stmt->close();

                // Credit wallet
                $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
                $stmt->bind_param('i', $pay['user_id']);
                $stmt->execute();
                $bal = $stmt->get_result()->fetch_assoc()['wallet_balance'];
                $stmt->close();

                $new_bal = $bal + $pay['amount'];
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?");
                $stmt->bind_param('di', $new_bal, $pay['user_id']);
                $stmt->execute();
                $stmt->close();

                $desc = "Admin refund for booking {$pay['booking_ref']}";
                $stmt = $conn->prepare("
                    INSERT INTO wallet_transactions
                        (user_id, type, amount, balance_after, description, ref_id, ref_type)
                    VALUES (?, 'credit', ?, ?, ?, ?, 'refund')
                ");
                $stmt->bind_param(
                    'iddsi',
                    $pay['user_id'], $pay['amount'], $new_bal,
                    $desc, $pay['booking_id']
                );
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                log_admin_action($conn, $_SESSION['user_id'],
                    'Manual refund issued', 'payment', $pay_id);
                set_flash('success', 'Refund issued to user wallet.');

            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Refund failed: ' . $e->getMessage());
            }
        }
    }
    redirect("admin/payments.php?filter=$filter");
}
?>

<div class="d-flex">
    <?php include __DIR__ . '/../dashboards/admin/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">Payment Management</h5>
        </div>

        <!-- Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card stat-card p-3 border-start border-success border-4">
                    <div class="text-muted small">Total Collected</div>
                    <div class="fs-4 fw-bold text-success">
                        ₹<?= number_format($stats['total_success'], 2) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3 border-start border-warning border-4">
                    <div class="text-muted small">Total Refunded</div>
                    <div class="fs-4 fw-bold text-warning">
                        ₹<?= number_format($stats['total_refunds'], 2) ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3 border-start border-danger border-4">
                    <div class="text-muted small">Failed Payments</div>
                    <div class="fs-4 fw-bold text-danger">
                        <?= $stats['failed_count'] ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stat-card p-3 border-start border-primary border-4">
                    <div class="text-muted small">Pending</div>
                    <div class="fs-4 fw-bold text-primary">
                        <?= $stats['pending_count'] ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <div class="d-flex gap-2 mb-4 flex-wrap">
            <?php
            $filters = [
                'all'      => ['All',     'secondary'],
                'success'  => ['Success', 'success'],
                'pending'  => ['Pending', 'warning'],
                'failed'   => ['Failed',  'danger'],
                'refunded' => ['Refunded','info'],
            ];
            foreach ($filters as $key => [$label, $color]): ?>
            <a href="?filter=<?= $key ?>"
               class="btn btn-sm <?= $filter===$key ? "btn-$color" : "btn-outline-$color" ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Table -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Booking</th>
                            <th>Gateway</th>
                            <th>Txn ID</th>
                            <th>Amount</th>
                            <th>Refund</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($p['full_name']) ?>
                                </div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= htmlspecialchars($p['email']) ?>
                                </div>
                            </td>
                            <td>
                                <code><?= $p['booking_ref'] ?></code><br>
                                <small class="text-muted">
                                    <?= ucfirst($p['booking_type']) ?>
                                </small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <?= ucfirst($p['gateway']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted text-truncate"
                                       style="max-width:120px;display:block">
                                    <?= htmlspecialchars($p['gateway_txn_id'] ?? '-') ?>
                                </small>
                            </td>
                            <td class="fw-bold">
                                ₹<?= number_format($p['amount'], 2) ?>
                            </td>
                            <td>
                                <?php if ($p['refund_amount'] > 0): ?>
                                <span class="text-danger">
                                    ₹<?= number_format($p['refund_amount'], 2) ?>
                                </span>
                                <?php else: ?>
                                <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?=
                                    $p['status'] === 'success'  ? 'bg-success' :
                                    ($p['status'] === 'refunded' ? 'bg-info'   :
                                    ($p['status'] === 'failed'   ? 'bg-danger' : 'bg-warning text-dark'))
                                ?>">
                                    <?= ucfirst($p['status']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted">
                                    <?= date('d M Y', strtotime($p['created_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <?php if ($p['status'] === 'pending'
                                               || $p['status'] === 'failed'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= csrf_token() ?>">
                                        <input type="hidden" name="payment_id"
                                               value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action"
                                               value="verify">
                                        <button class="btn btn-success btn-sm"
                                                title="Manually verify">
                                            <i class="bi bi-check"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($p['status'] === 'success'
                                               && $p['refund_amount'] == 0): ?>
                                    <form method="POST"
                                          onsubmit="return confirm('Issue full refund?')">
                                        <input type="hidden" name="csrf_token"
                                               value="<?= csrf_token() ?>">
                                        <input type="hidden" name="payment_id"
                                               value="<?= $p['id'] ?>">
                                        <input type="hidden" name="action"
                                               value="refund">
                                        <button class="btn btn-warning btn-sm"
                                                title="Refund to wallet">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No payments found.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="?filter=<?= $filter ?>&page=<?= $i ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>