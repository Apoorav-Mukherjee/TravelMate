<?php
$page_title = 'TravelMate — Book Your Journey';
require_once __DIR__ . '/includes/header.php';

// Featured packages
$featured_packages = [];
$result = $conn->query("
    SELECT tp.*, COALESCE(AVG(r.rating),0) AS avg_rating
    FROM tour_packages tp
    LEFT JOIN reviews r ON r.entity_type='package'
        AND r.entity_id=tp.id AND r.is_approved=1
    WHERE tp.is_approved=1 AND tp.is_featured=1 AND tp.status='active'
    GROUP BY tp.id LIMIT 3
");
if ($result) $featured_packages = $result->fetch_all(MYSQLI_ASSOC);

// Stats
$total_hotels   = $conn->query("SELECT COUNT(*) FROM hotels WHERE is_approved=1")->fetch_row()[0] ?? 0;
$total_guides   = $conn->query("SELECT COUNT(*) FROM guides WHERE is_verified=1")->fetch_row()[0] ?? 0;
$total_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE payment_status='paid'")->fetch_row()[0] ?? 0;
?>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark"
     style="background:linear-gradient(135deg,#1a1f3a,#0d6efd)">
    <div class="container">
        <a class="navbar-brand fw-bold fs-4" href="<?= BASE_URL ?>">✈️ TravelMate</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>modules/hotels/search.php">
                        🏨 Hotels
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>modules/guides/search.php">
                        🧭 Guides
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>modules/transport/search.php">
                        🚌 Transport
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>modules/packages/search.php">
                        🗺️ Packages
                    </a>
                </li>
            </ul>
            <div class="d-flex gap-2">
                <?php if (is_logged_in()): ?>
                <a href="<?= BASE_URL ?>dashboards/traveler/index.php"
                   class="btn btn-light btn-sm fw-bold">Dashboard</a>
                <a href="<?= BASE_URL ?>auth/logout.php"
                   class="btn btn-outline-light btn-sm">Logout</a>
                <?php else: ?>
                <a href="<?= BASE_URL ?>auth/login.php"
                   class="btn btn-outline-light btn-sm">Login</a>
                <a href="<?= BASE_URL ?>auth/register.php"
                   class="btn btn-light btn-sm fw-bold">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<div style="background:linear-gradient(135deg,#1a1f3a 0%,#0d6efd 100%);
            min-height:500px;display:flex;align-items:center">
    <!-- <div style="background:url('https://images.unsplash.com/photo-1519451241324-20b4ea2c4220?q=80&w=870&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D') center/cover;
                min-height:500px;display:flex;align-items:center"> -->
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-6 text-white">
                <h1 class="display-4 fw-bold mb-3">
                    Your Journey,<br>Our Expertise
                </h1>
                <p class="fs-5 opacity-75 mb-4">
                    Book hotels, hire local guides, find transport and explore
                    curated tour packages — all in one place.
                </p>
                <div class="d-flex gap-3 flex-wrap">
                    <a href="<?= BASE_URL ?>modules/packages/search.php"
                       class="btn btn-light btn-lg fw-bold px-4">
                        <i class="bi bi-map"></i> Browse Packages
                    </a>
                    <a href="<?= BASE_URL ?>auth/register.php"
                       class="btn btn-outline-light btn-lg px-4">
                        Get Started Free
                    </a>
                </div>
            </div>
            <div class="col-lg-6 d-none d-lg-block text-center">
                <div style="font-size:10rem;opacity:0.5">✈️</div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="card border-0 shadow-lg mt-5" style="border-radius:16px">
            <div class="card-body p-4">
                <ul class="nav nav-pills mb-3">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="pill"
                                data-bs-target="#searchHotel">🏨 Hotels</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill"
                                data-bs-target="#searchGuide">🧭 Guides</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill"
                                data-bs-target="#searchTransport">🚌 Transport</button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="pill"
                                data-bs-target="#searchPackage">🗺️ Packages</button>
                    </li>
                </ul>
                <div class="tab-content">

                    <!-- Hotel Search Tab -->
                    <div class="tab-pane fade show active" id="searchHotel">
                        <form action="<?= BASE_URL ?>modules/hotels/search.php"
                              method="GET" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" name="city"
                                       class="form-control form-control-lg"
                                       placeholder="City or destination">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="check_in"
                                       class="form-control form-control-lg"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="check_out"
                                       class="form-control form-control-lg"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-2">
                                <button type="submit"
                                        class="btn btn-primary btn-lg w-100">Search</button>
                            </div>
                        </form>
                    </div>

                    <!-- Guide Search Tab -->
                    <div class="tab-pane fade" id="searchGuide">
                        <form action="<?= BASE_URL ?>modules/guides/search.php"
                              method="GET" class="row g-3">
                            <div class="col-md-4">
                                <input type="text" name="city"
                                       class="form-control form-control-lg"
                                       placeholder="City">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="date"
                                       class="form-control form-control-lg"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="language"
                                       class="form-control form-control-lg"
                                       placeholder="Language">
                            </div>
                            <div class="col-md-2">
                                <button type="submit"
                                        class="btn btn-primary btn-lg w-100">Search</button>
                            </div>
                        </form>
                    </div>

                    <!-- Transport Search Tab -->
                    <div class="tab-pane fade" id="searchTransport">
                        <form action="<?= BASE_URL ?>modules/transport/search.php"
                              method="GET" class="row g-3">
                            <div class="col-md-3">
                                <input type="text" name="source"
                                       class="form-control form-control-lg"
                                       placeholder="From">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="destination"
                                       class="form-control form-control-lg"
                                       placeholder="To">
                            </div>
                            <div class="col-md-3">
                                <input type="date" name="date"
                                       class="form-control form-control-lg"
                                       min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="submit"
                                        class="btn btn-primary btn-lg w-100">Search</button>
                            </div>
                        </form>
                    </div>

                    <!-- Package Search Tab -->
                    <div class="tab-pane fade" id="searchPackage">
                        <form action="<?= BASE_URL ?>modules/packages/search.php"
                              method="GET" class="row g-3">
                            <div class="col-md-5">
                                <input type="text" name="search"
                                       class="form-control form-control-lg"
                                       placeholder="Search packages...">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="city"
                                       class="form-control form-control-lg"
                                       placeholder="Destination">
                            </div>
                            <div class="col-md-2">
                                <input type="number" name="max_price"
                                       class="form-control form-control-lg"
                                       placeholder="Max ₹">
                            </div>
                            <div class="col-md-2">
                                <button type="submit"
                                        class="btn btn-primary btn-lg w-100">Search</button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stats Bar -->
<div style="background:#1a1f3a" class="py-4">
    <div class="container">
        <div class="row text-center text-white g-3">
            <div class="col-6 col-md-3">
                <div class="fs-2 fw-bold text-primary">
                    <?= number_format($total_hotels) ?>+
                </div>
                <div class="opacity-75 small">Hotels</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-2 fw-bold text-success">
                    <?= number_format($total_guides) ?>+
                </div>
                <div class="opacity-75 small">Expert Guides</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-2 fw-bold text-warning">
                    <?= number_format($total_bookings) ?>+
                </div>
                <div class="opacity-75 small">Happy Travelers</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="fs-2 fw-bold text-info">15+</div>
                <div class="opacity-75 small">Destinations</div>
            </div>
        </div>
    </div>
</div>

<!-- Services Section -->
<div class="container py-5">
    <div class="text-center mb-5">
        <h2 class="fw-bold">Everything You Need for Your Trip</h2>
        <p class="text-muted">One platform for all your travel needs</p>
    </div>
    <div class="row g-4">
        <div class="col-md-3">
            <a href="<?= BASE_URL ?>modules/hotels/search.php"
               class="card border-0 shadow-sm text-center p-4 h-100
                      text-decoration-none text-dark stat-card d-block">
                <div style="font-size:3rem">🏨</div>
                <h5 class="fw-bold mt-3">Hotels</h5>
                <p class="text-muted small mb-0">
                    Browse and book hotels with real-time availability
                </p>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= BASE_URL ?>modules/guides/search.php"
               class="card border-0 shadow-sm text-center p-4 h-100
                      text-decoration-none text-dark stat-card d-block">
                <div style="font-size:3rem">🧭</div>
                <h5 class="fw-bold mt-3">Local Guides</h5>
                <p class="text-muted small mb-0">
                    Hire verified local guides by the hour or day
                </p>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= BASE_URL ?>modules/transport/search.php"
               class="card border-0 shadow-sm text-center p-4 h-100
                      text-decoration-none text-dark stat-card d-block">
                <div style="font-size:3rem">🚌</div>
                <h5 class="fw-bold mt-3">Transport</h5>
                <p class="text-muted small mb-0">
                    Book bus, train, cab and ferry tickets
                </p>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= BASE_URL ?>modules/packages/search.php"
               class="card border-0 shadow-sm text-center p-4 h-100
                      text-decoration-none text-dark stat-card d-block">
                <div style="font-size:3rem">🗺️</div>
                <h5 class="fw-bold mt-3">Tour Packages</h5>
                <p class="text-muted small mb-0">
                    All-inclusive packages with hotel, guide and transport
                </p>
            </a>
        </div>
    </div>
</div>

<!-- Featured Packages (only shows if any exist) -->
<?php if (!empty($featured_packages)): ?>
<div style="background:#f8f9fa" class="py-5">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Featured Packages</h2>
            <p class="text-muted">Handpicked experiences for unforgettable trips</p>
        </div>
        <div class="row g-4">
            <?php foreach ($featured_packages as $pkg):
                $cover = $pkg['cover_image']
                    ? BASE_URL . 'assets/uploads/packages/' . $pkg['cover_image']
                    : null;
                $discounted = $pkg['discount_percent'] > 0
                    ? round($pkg['fixed_price'] * (1 - $pkg['discount_percent'] / 100), 0)
                    : null;
            ?>
            <div class="col-md-4">
                <div class="card border-0 shadow h-100"
                     style="border-radius:14px;overflow:hidden">
                    <div style="height:200px;overflow:hidden">
                        <?php if ($cover): ?>
                        <img src="<?= htmlspecialchars($cover) ?>"
                             style="width:100%;height:100%;object-fit:cover">
                        <?php else: ?>
                        <div style="width:100%;height:100%;
                                    background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                                    display:flex;align-items:center;justify-content:center">
                            <span style="font-size:4rem;opacity:0.4">🗺️</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <h6 class="fw-bold">
                            <?= htmlspecialchars($pkg['title']) ?>
                        </h6>
                        <p class="text-muted small">
                            <i class="bi bi-geo-alt text-danger"></i>
                            <?= htmlspecialchars($pkg['destination'] ?? '') ?>
                            &bull; <?= $pkg['duration_days'] ?> Days
                        </p>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <?php if ($discounted): ?>
                                <div class="text-muted small text-decoration-line-through">
                                    ₹<?= number_format($pkg['fixed_price'], 0) ?>
                                </div>
                                <div class="fw-bold text-primary fs-5">
                                    ₹<?= number_format($discounted, 0) ?>
                                </div>
                                <?php else: ?>
                                <div class="fw-bold text-primary fs-5">
                                    ₹<?= number_format($pkg['fixed_price'], 0) ?>
                                </div>
                                <?php endif; ?>
                                <div class="text-muted" style="font-size:11px">per person</div>
                            </div>
                            <a href="<?= BASE_URL ?>modules/packages/detail.php?slug=<?= urlencode($pkg['slug'] ?? $pkg['id']) ?>"
                               class="btn btn-primary btn-sm">View</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-4">
            <a href="<?= BASE_URL ?>modules/packages/search.php"
               class="btn btn-outline-primary btn-lg">
                View All Packages →
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- CTA Section -->
<div style="background:linear-gradient(135deg,#1a1f3a,#0d6efd)" class="py-5">
    <div class="container text-center text-white py-3">
        <h2 class="fw-bold mb-3">Ready to Explore?</h2>
        <p class="opacity-75 mb-4 fs-5">
            Join thousands of travelers who trust TravelMate for their journeys.
        </p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <a href="<?= BASE_URL ?>auth/register.php"
               class="btn btn-light btn-lg fw-bold px-5">
                Create Free Account
            </a>
            <a href="<?= BASE_URL ?>modules/packages/search.php"
               class="btn btn-outline-light btn-lg px-5">
                Browse Packages
            </a>
        </div>
    </div>
</div>

<!-- Footer -->
<footer style="background:#1a1f3a;color:rgba(255,255,255,0.7)" class="py-4">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-4">
                <h5 class="text-white fw-bold">✈️ TravelMate</h5>
                <p class="small">
                    Your complete travel companion for hotels, guides,
                    transport and packages.
                </p>
            </div>
            <div class="col-md-2">
                <h6 class="text-white">Explore</h6>
                <ul class="list-unstyled small">
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>modules/hotels/search.php"
                           class="text-decoration-none" style="color:inherit">Hotels</a>
                    </li>
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>modules/guides/search.php"
                           class="text-decoration-none" style="color:inherit">Guides</a>
                    </li>
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>modules/transport/search.php"
                           class="text-decoration-none" style="color:inherit">Transport</a>
                    </li>
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>modules/packages/search.php"
                           class="text-decoration-none" style="color:inherit">Packages</a>
                    </li>
                </ul>
            </div>
            <div class="col-md-2">
                <h6 class="text-white">Account</h6>
                <ul class="list-unstyled small">
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>auth/login.php"
                           class="text-decoration-none" style="color:inherit">Login</a>
                    </li>
                    <li class="mb-1">
                        <a href="<?= BASE_URL ?>auth/register.php"
                           class="text-decoration-none" style="color:inherit">Register</a>
                    </li>
                </ul>
            </div>
            <div class="col-md-4">
                <h6 class="text-white">Contact</h6>
                <p class="small">
                    📧 support@travelmate.com<br>
                    📞 +91 98765 43210
                </p>
            </div>
        </div>
        <hr style="border-color:rgba(255,255,255,0.1)">
        <div class="text-center small">
            &copy; <?= date('Y') ?> TravelMate. All rights reserved.
        </div>
    </div>
</footer>

<?php include __DIR__ . '/includes/footer.php'; ?>