<?php
$page_title = 'Register';
require_once __DIR__ . '/../includes/header.php';

if (is_logged_in()) {
    redirect_by_role(get_role());
}

$errors = [];
$old    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email'] ?? '');
    $phone     = sanitize($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role_id   = (int)($_POST['role_id'] ?? 2);
    $old       = compact('full_name', 'email', 'phone', 'role_id');

    // Validation
    if (empty($full_name)) $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';
    if (!in_array($role_id, [2, 3, 4, 5])) $errors[] = 'Invalid role selected.';

    if (empty($errors)) {
        // Check duplicate email
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'Email already registered.';
        }
        $stmt->close();
    }

    if (empty($errors)) {
        $hash  = password_hash($password, PASSWORD_BCRYPT);
        $token = generate_token();

        // Insert user
        $stmt = $conn->prepare("
    INSERT INTO users (full_name, email, phone, password_hash, role_id, verify_token)
    VALUES (?, ?, ?, ?, ?, ?)
");
        $stmt->bind_param('ssssis', $full_name, $email, $phone, $hashed, $role_id, $token);
        $stmt->execute();
        $new_user_id = $conn->insert_id;
        $stmt->close();

        // ── Try to send verification email
        // In development (localhost), auto-verify instead of sending email
        $is_localhost = in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1']);

        if ($is_localhost) {
            // AUTO-VERIFY on localhost — no email needed
            $stmt = $conn->prepare("
        UPDATE users SET email_verified=1, status='active' WHERE id=?
    ");
            $stmt->bind_param('i', $new_user_id);
            $stmt->execute();
            $stmt->close();

            set_flash('success', 'Account created! You can now log in.');
            redirect('auth/login.php');
        } else {
            // Production — send real verification email
            $verify_url = BASE_URL . 'auth/verify.php?token=' . $token;
            $body       = email_verify_template($full_name, $verify_url);
            send_email($email, $full_name, 'Verify your TravelMate account', $body);

            set_flash('success', 'Registration successful! Check your email to verify your account.');
            redirect('auth/login.php');
        }
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="brand">✈️ TravelMate</div>
            <p class="text-muted">Create your account</p>
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
                <label class="form-label">Full Name</label>
                <input type="text" name="full_name" class="form-control"
                    value="<?= htmlspecialchars($old['full_name'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control"
                    value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
            </div>

            <div class="mb-3">
                <label class="form-label">Phone Number</label>
                <input type="text" name="phone" class="form-control"
                    value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label class="form-label">Register As</label>
                <select name="role_id" class="form-select">
                    <option value="2" <?= (($old['role_id'] ?? 2) == 2) ? 'selected' : '' ?>>Traveler</option>
                    <option value="3" <?= (($old['role_id'] ?? 2) == 3) ? 'selected' : '' ?>>Tour Guide</option>
                    <option value="4" <?= (($old['role_id'] ?? 2) == 4) ? 'selected' : '' ?>>Hotel Staff</option>
                    <option value="5" <?= (($old['role_id'] ?? 2) == 5) ? 'selected' : '' ?>>Transport Provider</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
                <div class="form-text">Minimum 8 characters</div>
            </div>

            <div class="mb-4">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary w-100">Create Account</button>
        </form>

        <div class="text-center mt-3">
            Already have an account? <a href="<?= BASE_URL ?>auth/login.php">Login</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>