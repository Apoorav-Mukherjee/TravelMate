<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' | ' . SITE_NAME : SITE_NAME ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
<?php if (is_logged_in()): ?>
<!-- Global notification polling -->
<script>
async function pollNotifications() {
    try {
        const res  = await fetch('<?= BASE_URL ?>ajax/notifications.php');
        const data = await res.json();
        // Update message badge in sidebar
        const msgBadge = document.getElementById('msgBadge');
        if (msgBadge) {
            msgBadge.textContent   = data.unread_messages || '';
            msgBadge.style.display = data.unread_messages > 0 ? 'inline' : 'none';
        }
        // Update tab title
        const title = document.title.replace(/^\(\d+\) /, '');
        if (data.unread_messages > 0) {
            document.title = `(${data.unread_messages}) ${title}`;
        } else {
            document.title = title;
        }
    } catch(e) {}
}
// Poll every 10 seconds
document.addEventListener('DOMContentLoaded', () => {
    pollNotifications();
    setInterval(pollNotifications, 10000);
});
</script>
<?php endif; ?>