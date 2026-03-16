<?php
$page_title = 'Login';
require_once __DIR__ . '/../includes/header.php';

if (is_logged_in()) {
    redirect_by_role(get_role());
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Rate limiting — max 5 attempts per 5 minutes
    if (!check_rate_limit('login_' . $email, 5, 300)) {
        $errors[] = 'Too many login attempts. Please wait 5 minutes and try again.';
    }

    if (empty($errors)) {
        if (empty($email) || empty($password)) {
            $errors[] = 'Email and password are required.';
        } else {
            $stmt = $conn->prepare(
                "SELECT u.*, r.slug as role_slug
                 FROM users u
                 JOIN roles r ON u.role_id = r.id
                 WHERE u.email = ? AND u.deleted_at IS NULL"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $errors[] = 'Invalid email or password.';
            } elseif (!$user['email_verified']) {
                $errors[] = 'Please verify your email before logging in.';
            } elseif ($user['status'] !== 'active') {
                $errors[] = 'Your account is ' . $user['status'] . '. Please contact support.';
            } else {
                // ✅ Login success
                session_regenerate_id(true);

                $_SESSION['user_id']    = $user['id'];
                $_SESSION['full_name']  = $user['full_name'];
                $_SESSION['email']      = $user['email'];
                $_SESSION['role_id']    = $user['role_id'];
                $_SESSION['role_slug']  = $user['role_slug'];
                $_SESSION['profile_picture'] = $user['profile_picture'];

                // Clear rate limit
                unset($_SESSION['rate_limit']['login_' . $email]);

                set_flash('success', 'Welcome back, ' . $user['full_name'] . '!');
                redirect_by_role($user['role_slug']);
            }
        }
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="brand">✈️ TravelMate</div>
            <p class="text-muted">Sign in to your account</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
            </div>

            <div class="mb-4">
                <label class="form-label d-flex justify-content-between">
                    Password
                    <a href="<?= BASE_URL ?>auth/forgot_password.php" class="text-decoration-none small">Forgot password?</a>
                </label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>

        <div class="text-center mt-3">
            Don't have an account? <a href="<?= BASE_URL ?>auth/register.php">Register</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>