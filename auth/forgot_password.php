<?php
$page_title = 'Forgot Password';
require_once __DIR__ . '/../includes/header.php';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = sanitize($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? AND deleted_at IS NULL");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token   = generate_token();
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $stmt->bind_param('ssi', $token, $expires, $user['id']);
            $stmt->execute();
            $stmt->close();

            $link = BASE_URL . 'auth/reset_password.php?token=' . $token;
            $body = "
                <p>Hi {$user['full_name']},</p>
                <p>Click the link below to reset your password. This link expires in 1 hour.</p>
                <a href='{$link}' style='padding:10px 20px;background:#0d6efd;color:#fff;border-radius:5px;text-decoration:none'>Reset Password</a>
                <p>If you didn't request this, ignore this email.</p>
            ";
            send_email($email, $user['full_name'], 'TravelMate Password Reset', $body);
        }

        // Always show success to prevent email enumeration
        $success = true;
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="text-center mb-4">
            <div class="brand">✈️ TravelMate</div>
            <p class="text-muted">Reset your password</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                If an account exists with that email, a reset link has been sent. Please check your inbox.
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= BASE_URL ?>auth/login.php">Back to Login</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>