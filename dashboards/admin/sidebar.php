<?php
// Get pending counts for badges
$pending_hotels    = $conn->query("SELECT COUNT(*) FROM hotels WHERE is_approved=0 AND deleted_at IS NULL")->fetch_row()[0];
$pending_guides    = $conn->query("SELECT COUNT(*) FROM guides WHERE is_verified=0")->fetch_row()[0];
$pending_transport = $conn->query("SELECT COUNT(*) FROM transport_providers WHERE is_approved=0")->fetch_row()[0];
$pending_reviews   = $conn->query("SELECT COUNT(*) FROM reviews WHERE is_approved=0")->fetch_row()[0];
$pending_packages  = $conn->query("SELECT COUNT(*) FROM tour_packages WHERE is_approved=0")->fetch_row()[0];
$flagged_msgs      = $conn->query("SELECT COUNT(*) FROM messages WHERE is_flagged=1")->fetch_row()[0];
$total_pending     = $pending_hotels + $pending_guides + $pending_transport + $pending_packages;
?>
<div class="sidebar">
    <div class="brand">✈️ TravelMate</div>
    <div class="px-3 py-2">
        <small class="text-white-50 text-uppercase">Admin Panel</small>
    </div>
    <nav class="nav flex-column mt-1">
        <a href="<?= BASE_URL ?>dashboards/admin/index.php" class="nav-link">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <div class="px-3 py-1">
            <small class="text-white-50 text-uppercase" style="font-size:10px">
                Management
            </small>
        </div>

        <a href="<?= BASE_URL ?>admin/users.php" class="nav-link">
            <i class="bi bi-people"></i> Users
        </a>

        <a href="<?= BASE_URL ?>admin/hotels.php" class="nav-link position-relative">
            <i class="bi bi-building"></i> Hotels
            <?php if ($pending_hotels > 0): ?>
            <span class="badge bg-warning text-dark position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $pending_hotels ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>admin/guides.php" class="nav-link position-relative">
            <i class="bi bi-person-badge"></i> Guides
            <?php if ($pending_guides > 0): ?>
            <span class="badge bg-warning text-dark position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $pending_guides ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>admin/transport.php" class="nav-link position-relative">
            <i class="bi bi-bus-front"></i> Transport
            <?php if ($pending_transport > 0): ?>
            <span class="badge bg-warning text-dark position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $pending_transport ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>admin/packages.php" class="nav-link position-relative">
            <i class="bi bi-map"></i> Packages
            <?php if ($pending_packages > 0): ?>
            <span class="badge bg-warning text-dark position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $pending_packages ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>admin/reviews.php" class="nav-link position-relative">
            <i class="bi bi-star"></i> Reviews
            <?php if ($pending_reviews > 0): ?>
            <span class="badge bg-warning text-dark position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $pending_reviews ?>
            </span>
            <?php endif; ?>
        </a>

        <div class="px-3 py-1 mt-2">
            <small class="text-white-50 text-uppercase" style="font-size:10px">
                Finance
            </small>
        </div>

        <a href="<?= BASE_URL ?>admin/payments.php" class="nav-link">
            <i class="bi bi-credit-card"></i> Payments
        </a>

        <a href="<?= BASE_URL ?>admin/wallet.php" class="nav-link">
            <i class="bi bi-wallet2"></i> Wallet
        </a>

        <a href="<?= BASE_URL ?>admin/analytics.php" class="nav-link">
            <i class="bi bi-bar-chart-line"></i> Analytics
        </a>

        <div class="px-3 py-1 mt-2">
            <small class="text-white-50 text-uppercase" style="font-size:10px">
                System
            </small>
        </div>

        <a href="<?= BASE_URL ?>admin/chat_monitor.php" class="nav-link position-relative">
            <i class="bi bi-chat-dots"></i> Chat Monitor
            <?php if ($flagged_msgs > 0): ?>
            <span class="badge bg-danger position-absolute"
                  style="right:10px;top:8px;font-size:10px">
                <?= $flagged_msgs ?>
            </span>
            <?php endif; ?>
        </a>

        <a href="<?= BASE_URL ?>admin/logs.php" class="nav-link">
            <i class="bi bi-journal-text"></i> Admin Logs
        </a>

        <a href="<?= BASE_URL ?>admin/settings.php" class="nav-link">
            <i class="bi bi-gear"></i> Settings
        </a>

        <hr style="border-color:rgba(255,255,255,0.1)">

        <a href="<?= BASE_URL ?>auth/logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
</div>