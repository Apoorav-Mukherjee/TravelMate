<?php
$page_title = 'My Wallet';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

$user_id = $_SESSION['user_id'];

// Fetch wallet balance
$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();

// Fetch transaction history
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset   = ($page - 1) * $per_page;

$stmt = $conn->prepare("
    SELECT COUNT(*) as total FROM wallet_transactions WHERE user_id = ?
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$total       = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$total_pages = ceil($total / $per_page);

$stmt = $conn->prepare("
    SELECT * FROM wallet_transactions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param('iii', $user_id, $per_page, $offset);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Monthly summary
$stmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN type='credit' THEN amount ELSE 0 END) AS total_in,
        SUM(CASE WHEN type='debit'  THEN amount ELSE 0 END) AS total_out
    FROM wallet_transactions
    WHERE user_id = ?
      AND MONTH(created_at) = MONTH(NOW())
      AND YEAR(created_at)  = YEAR(NOW())
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$monthly = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/../../dashboards/traveler/sidebar.php'; ?>
    <div class="main-content w-100">

        <div class="topbar">
            <h5 class="mb-0">My Wallet</h5>
            <button class="btn btn-primary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#topupModal">
                <i class="bi bi-plus-circle"></i> Add Money
            </button>
        </div>

        <!-- Wallet Balance Card -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card border-0 text-white"
                     style="background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                            border-radius:16px">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="opacity-75 small mb-1">Available Balance</div>
                                <div class="display-5 fw-bold">
                                    ₹<?= number_format($wallet, 2) ?>
                                </div>
                                <div class="opacity-75 small mt-2">
                                    <?= htmlspecialchars($_SESSION['full_name']) ?>
                                </div>
                            </div>
                            <i class="bi bi-wallet2" style="font-size:3rem;opacity:0.3"></i>
                        </div>
                        <div class="d-flex gap-3 mt-4">
                            <button class="btn btn-light btn-sm flex-grow-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#topupModal">
                                <i class="bi bi-plus-circle"></i> Add Money
                            </button>
                            <button class="btn btn-outline-light btn-sm flex-grow-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#transferModal">
                                <i class="bi bi-arrow-left-right"></i> Transfer
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-success border-4 h-100">
                    <div class="text-muted small mb-1">Money In This Month</div>
                    <div class="fs-3 fw-bold text-success">
                        ₹<?= number_format($monthly['total_in'] ?? 0, 2) ?>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-arrow-down-circle text-success"></i>
                        Credits & Refunds
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-danger border-4 h-100">
                    <div class="text-muted small mb-1">Money Out This Month</div>
                    <div class="fs-3 fw-bold text-danger">
                        ₹<?= number_format($monthly['total_out'] ?? 0, 2) ?>
                    </div>
                    <div class="text-muted small mt-1">
                        <i class="bi bi-arrow-up-circle text-danger"></i>
                        Payments & Debits
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Top-up Amounts -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-bold">Quick Add</div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ([100, 250, 500, 1000, 2000, 5000] as $amount): ?>
                    <button class="btn btn-outline-primary quick-topup"
                            data-amount="<?= $amount ?>">
                        + ₹<?= number_format($amount) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Transaction History -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Transaction History</span>
                <span class="text-muted small"><?= $total ?> transactions</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($transactions)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-receipt display-3"></i>
                    <p class="mt-3">No transactions yet.</p>
                </div>
                <?php endif; ?>
                <?php foreach ($transactions as $txn): ?>
                <div class="d-flex align-items-center gap-3 p-3 border-bottom">
                    <!-- Icon -->
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;
                                background:<?= $txn['type'] === 'credit' ? '#d1fae5' : '#fee2e2' ?>">
                        <i class="bi <?= $txn['type'] === 'credit'
                            ? 'bi-arrow-down-circle-fill text-success'
                            : 'bi-arrow-up-circle-fill text-danger' ?>"></i>
                    </div>

                    <!-- Details -->
                    <div class="flex-grow-1">
                        <div class="fw-semibold small">
                            <?= htmlspecialchars($txn['description'] ?? 'Transaction') ?>
                        </div>
                        <div class="text-muted" style="font-size:11px">
                            <?= date('d M Y, h:i A', strtotime($txn['created_at'])) ?>
                            <?php if ($txn['ref_type']): ?>
                            &bull; <?= ucfirst($txn['ref_type']) ?>
                            #<?= $txn['ref_id'] ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Amount -->
                    <div class="text-end">
                        <div class="fw-bold <?= $txn['type'] === 'credit' ? 'text-success' : 'text-danger' ?>">
                            <?= $txn['type'] === 'credit' ? '+' : '-' ?>
                            ₹<?= number_format($txn['amount'], 2) ?>
                        </div>
                        <div class="text-muted" style="font-size:11px">
                            Bal: ₹<?= number_format($txn['balance_after'], 2) ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>

<!-- Top-up Modal -->
<div class="modal fade" id="topupModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Money to Wallet</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= BASE_URL ?>modules/wallet/topup.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" name="amount" id="topupAmount"
                               class="form-control form-control-lg text-center fw-bold"
                               min="10" max="50000" step="1"
                               placeholder="Enter amount" required>
                        <div class="form-text">Min ₹10 — Max ₹50,000 per transaction</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Payment Method</label>
                        <select name="gateway" class="form-select">
                            <option value="stripe">Credit/Debit Card (Stripe)</option>
                            <option value="razorpay">UPI / Net Banking (Razorpay)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary w-100">
                        Proceed to Pay
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer to User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="<?= BASE_URL ?>modules/wallet/transfer.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Recipient Email</label>
                        <input type="email" name="email" class="form-control"
                               placeholder="user@example.com" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" name="amount" class="form-control"
                               min="1" max="<?= $wallet ?>"
                               placeholder="Enter amount" required>
                        <div class="form-text">
                            Available: ₹<?= number_format($wallet, 2) ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Note (Optional)</label>
                        <input type="text" name="note" class="form-control"
                               placeholder="e.g. Split bill">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary w-100">Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Quick top-up buttons fill the modal amount
document.querySelectorAll('.quick-topup').forEach(btn => {
    btn.addEventListener('click', function () {
        document.getElementById('topupAmount').value = this.dataset.amount;
        new bootstrap.Modal(document.getElementById('topupModal')).show();
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>