<?php $page_title = "Access Denied"; include __DIR__ . '/header.php'; ?>
<div class="container text-center py-5">
    <h1 class="display-1 text-danger">403</h1>
    <h3>Access Denied</h3>
    <p class="text-muted">You don't have permission to access this page.</p>
    <a href="<?= BASE_URL ?>" class="btn btn-primary">Go Home</a>
</div>
<?php include __DIR__ . '/footer.php'; ?>