<?php
require_once __DIR__ . '/../includes/header.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('admin/guides.php');
verify_csrf();

$guide_id        = (int)($_POST['guide_id']        ?? 0);
$commission_rate = min(50, max(0, (float)($_POST['commission_rate'] ?? 10)));

if ($guide_id) {
    $stmt = $conn->prepare("UPDATE guides SET commission_rate = ? WHERE id = ?");
    $stmt->bind_param('di', $commission_rate, $guide_id);
    $stmt->execute();
    $stmt->close();

    log_admin_action($conn, $_SESSION['user_id'],
        "Updated commission to {$commission_rate}%", 'guide', $guide_id);
    set_flash('success', 'Commission rate updated.');
}

redirect('admin/guides.php');
?>