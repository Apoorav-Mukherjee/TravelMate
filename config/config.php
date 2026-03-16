<?php
session_start();

define('BASE_URL', 'http://localhost/travelmate/');
define('SITE_NAME', 'TravelMate');
define('UPLOAD_PATH', __DIR__ . '/../assets/uploads/');

// SMTP
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'your@gmail.com');
define('SMTP_PASS', 'your_app_password');
define('SMTP_PORT', 587);

// Stripe
define('STRIPE_SECRET', 'sk_test_xxxx');
define('STRIPE_PUBLIC', 'pk_test_xxxx');

// Razorpay
define('RAZORPAY_KEY',    'rzp_test_xxxx');
define('RAZORPAY_SECRET', 'xxxx');
?>