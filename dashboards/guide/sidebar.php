<div class="sidebar">
    <div class="brand">✈️ TravelMate</div>
    <div class="px-3 py-2">
        <small class="text-white-50 text-uppercase">Guide Panel</small>
    </div>
    <nav class="nav flex-column mt-1">
        <a href="<?= BASE_URL ?>dashboards/guide/index.php" class="nav-link">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <a href="<?= BASE_URL ?>modules/guides/manage/profile.php" class="nav-link">
            <i class="bi bi-person-badge"></i> My Profile
        </a>
        <a href="<?= BASE_URL ?>modules/guides/manage/availability.php" class="nav-link">
            <i class="bi bi-calendar3"></i> Availability
        </a>
        <a href="<?= BASE_URL ?>modules/guides/manage/bookings.php" class="nav-link">
            <i class="bi bi-calendar-check"></i> My Bookings
        </a>
        <a href="<?= BASE_URL ?>modules/chat/inbox.php" class="nav-link position-relative">
            <i class="bi bi-chat-dots"></i> Messages
            <span id="msgBadge" class="badge bg-danger rounded-pill position-absolute"
                style="top:6px;right:10px;font-size:10px;display:none"></span>
        </a>
        <hr style="border-color:rgba(255,255,255,0.1)">
        <a href="<?= BASE_URL ?>auth/logout.php" class="nav-link text-danger">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>
    </nav>
</div>