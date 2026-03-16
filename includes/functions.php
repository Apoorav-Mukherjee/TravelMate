<?php
// -------------------------------------------------------
// Generate CSRF Token
// -------------------------------------------------------
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// -------------------------------------------------------
// Verify CSRF Token
// -------------------------------------------------------
function verify_csrf() {
    if (
        empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        die('CSRF validation failed. Go back and try again.');
    }
}

// -------------------------------------------------------
// Sanitize Input
// -------------------------------------------------------
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// -------------------------------------------------------
// Redirect
// -------------------------------------------------------
function redirect($url) {
    header('Location: ' . BASE_URL . $url);
    exit();
}

// -------------------------------------------------------
// Flash Messages
// -------------------------------------------------------
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// -------------------------------------------------------
// Generate Unique Token
// -------------------------------------------------------
function generate_token($length = 64) {
    return bin2hex(random_bytes($length / 2));
}

// -------------------------------------------------------
// Generate Booking Reference
// -------------------------------------------------------
function generate_booking_ref() {
    return 'TM-' . strtoupper(substr(uniqid(), -6)) . '-' . rand(100, 999);
}

// -------------------------------------------------------
// Log Admin Action
// -------------------------------------------------------
function log_admin_action($conn, $admin_id, $action, $target_type = null, $target_id = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO admin_logs (admin_id, action, target_type, target_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ississ', $admin_id, $action, $target_type, $target_id, $ip, $ua);
    $stmt->execute();
    $stmt->close();
}

// -------------------------------------------------------
// Check if Logged In
// -------------------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// -------------------------------------------------------
// Get Current User Role
// -------------------------------------------------------
function get_role() {
    return $_SESSION['role_slug'] ?? null;
}

// -------------------------------------------------------
// Role-Based Dashboard Redirect
// -------------------------------------------------------
function redirect_by_role($role_slug) {
    $map = [
        'admin'              => 'dashboards/admin/index.php',
        'traveler'           => 'dashboards/traveler/index.php',
        'guide'              => 'dashboards/guide/index.php',
        'hotel_staff'        => 'dashboards/hotel_staff/index.php',
        'transport_provider' => 'dashboards/transport_provider/index.php',
    ];
    redirect($map[$role_slug] ?? '');
}

// -------------------------------------------------------
// Rate Limiting (Login Attempts)
// -------------------------------------------------------
function check_rate_limit($key, $max = 5, $window = 300) {
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = ['count' => 0, 'time' => time()];
    }
    $rl = &$_SESSION['rate_limit'][$key];
    if (time() - $rl['time'] > $window) {
        $rl = ['count' => 0, 'time' => time()];
    }
    $rl['count']++;
    return $rl['count'] <= $max;
}


// ── Email template functions ──────────────────────────────────────

function email_verify_template($name, $verify_url) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto'>
        <div style='background:linear-gradient(135deg,#1a1f3a,#0d6efd);
                    padding:25px;border-radius:12px 12px 0 0;text-align:center'>
            <h2 style='color:#fff;margin:0'>✈️ TravelMate</h2>
            <p style='color:rgba(255,255,255,0.8);margin:5px 0 0'>Email Verification</p>
        </div>
        <div style='padding:30px;border:1px solid #eee;border-top:none;
                    border-radius:0 0 12px 12px;background:#fff'>
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Thank you for registering! Please verify your email address by clicking the button below.</p>
            <div style='text-align:center;margin:30px 0'>
                <a href='" . $verify_url . "'
                   style='background:#0d6efd;color:#fff;padding:14px 32px;
                          border-radius:8px;text-decoration:none;font-weight:bold;
                          font-size:16px'>
                    Verify Email Address
                </a>
            </div>
            <p style='color:#6c757d;font-size:13px'>
                Or copy this link into your browser:<br>
                <a href='" . $verify_url . "' style='color:#0d6efd;word-break:break-all'>
                    " . $verify_url . "
                </a>
            </p>
            <p style='color:#6c757d;font-size:12px'>
                This link expires in 24 hours. If you did not register, ignore this email.
            </p>
        </div>
    </div>";
}

function email_booking_confirm_template($name, $booking_ref, $details) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto'>
        <div style='background:linear-gradient(135deg,#1a1f3a,#198754);
                    padding:25px;border-radius:12px 12px 0 0;text-align:center'>
            <h2 style='color:#fff;margin:0'>✈️ TravelMate</h2>
            <p style='color:rgba(255,255,255,0.8);margin:5px 0 0'>Booking Confirmed</p>
        </div>
        <div style='padding:30px;border:1px solid #eee;border-top:none;
                    border-radius:0 0 12px 12px;background:#fff'>
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Your booking has been confirmed!</p>
            <div style='background:#f8f9fa;padding:15px;border-radius:8px;margin:20px 0'>
                <div style='font-size:13px;color:#6c757d'>Booking Reference</div>
                <div style='font-size:22px;font-weight:bold;color:#0d6efd;
                            font-family:monospace'>" . $booking_ref . "</div>
            </div>
            " . $details . "
            <p style='color:#6c757d;font-size:12px;margin-top:20px'>
                Thank you for choosing TravelMate. Have a great trip!
            </p>
        </div>
    </div>";
}

function email_password_reset_template($name, $reset_url) {
    return "
    <div style='font-family:Arial,sans-serif;max-width:500px;margin:auto'>
        <div style='background:linear-gradient(135deg,#1a1f3a,#dc3545);
                    padding:25px;border-radius:12px 12px 0 0;text-align:center'>
            <h2 style='color:#fff;margin:0'>✈️ TravelMate</h2>
            <p style='color:rgba(255,255,255,0.8);margin:5px 0 0'>Password Reset</p>
        </div>
        <div style='padding:30px;border:1px solid #eee;border-top:none;
                    border-radius:0 0 12px 12px;background:#fff'>
            <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>We received a request to reset your password. Click the button below:</p>
            <div style='text-align:center;margin:30px 0'>
                <a href='" . $reset_url . "'
                   style='background:#dc3545;color:#fff;padding:14px 32px;
                          border-radius:8px;text-decoration:none;font-weight:bold'>
                    Reset My Password
                </a>
            </div>
            <p style='color:#6c757d;font-size:12px'>
                This link expires in 1 hour. If you did not request this, ignore this email.
            </p>
        </div>
    </div>";
}

?>