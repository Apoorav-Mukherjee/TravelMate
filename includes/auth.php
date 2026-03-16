<?php
// -------------------------------------------------------
// Require Login — redirect if not logged in
// -------------------------------------------------------
function require_login() {
    if (!is_logged_in()) {
        set_flash('error', 'Please login to continue.');
        redirect('auth/login.php');
    }
}

// -------------------------------------------------------
// Require Specific Role
// -------------------------------------------------------
function require_role($allowed_roles) {
    require_login();
    if (!in_array(get_role(), (array)$allowed_roles)) {
        http_response_code(403);
        include __DIR__ . '/../includes/403.php';
        exit();
    }
}
?>