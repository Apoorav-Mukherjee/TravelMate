<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

session_destroy();
setcookie(session_name(), '', time() - 3600, '/');

header('Location: ' . BASE_URL . 'auth/login.php');
exit();
?>