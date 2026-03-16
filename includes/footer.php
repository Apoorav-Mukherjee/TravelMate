<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>assets/js/main.js"></script>

<?php
$flash = get_flash();
if ($flash): ?>
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:9999">
    <div class="toast show align-items-center text-bg-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body"><?= htmlspecialchars($flash['message']) ?></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>