<div class="sidebar">
    <div class="brand">✈️ TravelMate</div>
    <nav class="nav flex-column mt-3">
        <a href="<?= BASE_URL ?>dashboards/traveler/index.php"
            class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="<?= BASE_URL ?>modules/hotels/search.php" class="nav-link">
            <i class="bi bi-building"></i> Find Hotels
        </a>
        <a href="<?= BASE_URL ?>modules/guides/search.php" class="nav-link">
            <i class="bi bi-person-badge"></i> Find Guides
        </a>
        <a href="<?= BASE_URL ?>modules/transport/search.php" class="nav-link">
            <i class="bi bi-train-front"></i> Transport
        </a>
        <a href="<?= BASE_URL ?>modules/packages/search.php" class="nav-link">
            <i class="bi bi-gift"></i> Tour Packages
        </a>
        <a href="<?= BASE_URL ?>modules/bookings/my_bookings.php" class="nav-link">
            <i class="bi bi-calendar2-check"></i> My Bookings
        </a>
        <a href="<?= BASE_URL ?>modules/chat/inbox.php" class="nav-link position-relative">
            <i class="bi bi-chat-dots"></i> Messages
            <span id="msgBadge" class="badge bg-danger rounded-pill position-absolute"
                style="top:6px;right:10px;font-size:10px;display:none"></span>
        </a>
        <a href="<?= BASE_URL ?>modules/wallet/index.php" class="nav-link">
            <i class="bi bi-wallet2"></i> Wallet
        </a>
        <hr style="border-color:rgba(255,255,255,0.1)">
        <a href="<?= BASE_URL ?>auth/logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
</div>