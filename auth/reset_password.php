<?php
$page_title = 'Reset Password';
require_once __DIR__ . '/../includes/header.php';

$token  = sanitize($_GET['token'] ?? '');
$errors = [];

if (empty($token)) {
    set_flash('error', 'Invalid reset link.');
    redirect('auth/login.php');
}

// Validate token
$stmt = $conn->prepare(
    "SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() AND deleted_at IS NULL"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    set_flash('error', 'This reset link is invalid or has expired.');
    redirect('auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare(
            "UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?"
        );
        $stmt->bind_param('si', $hash, $user['id']);
        $stmt->execute();
        $stmt->close();

        set_flash('success', 'Password reset successfully. Please login.');
        redirect('auth/login.php');
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="brand">✈️ TravelMate</div>
            <p class="text-muted">Set a new password</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Reset Password</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>