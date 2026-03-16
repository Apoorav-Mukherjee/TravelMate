<?php
$page_title = 'Traveler Dashboard';
require_once __DIR__ . '/../../includes/header.php';
require_role('traveler');

// Fetch booking stats for this traveler
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$total_bookings = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM bookings WHERE user_id = ? AND booking_status = 'confirmed'");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$confirmed = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc()['wallet_balance'];
$stmt->close();
?>

<div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="main-content w-100">

        <!-- Topbar -->
        <div class="topbar">
            <h5 class="mb-0">Dashboard</h5>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted small"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
                <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-primary border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Total Bookings</div>
                            <div class="fs-2 fw-bold"><?= $total_bookings ?></div>
                        </div>
                        <i class="bi bi-calendar-check fs-1 text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-success border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Confirmed</div>
                            <div class="fs-2 fw-bold"><?= $confirmed ?></div>
                        </div>
                        <i class="bi bi-check-circle fs-1 text-success opacity-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stat-card p-4 border-start border-warning border-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small">Wallet Balance</div>
                            <div class="fs-2 fw-bold">₹<?= number_format($wallet, 2) ?></div>
                        </div>
                        <i class="bi bi-wallet2 fs-1 text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Bookings -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold">Recent Bookings</div>
            <div class="card-body p-0">
                <?php
                $stmt = $conn->prepare(
                    "SELECT * FROM bookings WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
                );
                $stmt->bind_param('i', $user_id);
                $stmt->execute();
                $bookings = $stmt->get_result();
                $stmt->close();
                ?>
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ref</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($b = $bookings->fetch_assoc()): ?>
                        <tr>
                            <td><code><?= $b['booking_ref'] ?></code></td>
                            <td><?= ucfirst($b['booking_type']) ?></td>
                            <td>₹<?= number_format($b['final_amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= $b['booking_status'] ?>">
                                    <?= ucfirst($b['booking_status']) ?>
                                </span>
                            </td>
                            <td><?= date('d M Y', strtotime($b['created_at'])) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>modules/bookings/view.php?id=<?= $b['id'] ?>"
                                   class="btn btn-sm btn-outline-primary">View</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($bookings->num_rows === 0): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">No bookings yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>