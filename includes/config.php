<?php
// includes/config.php
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sessions';

    if (!is_dir($sessionPath)) {
        mkdir($sessionPath, 0777, true);
    }

    if (is_dir($sessionPath) && is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
}

session_start();

// Database configuration for MySQL in XAMPP
define('DB_HOST', 'localhost');
define('DB_USER', 'root');  // Default XAMPP MySQL user
define('DB_PASS', '');      // Default XAMPP MySQL password is empty
define('DB_NAME', 'grocery_pos');

// Site configuration
define('SITE_NAME', 'Grocery POS System');
define('SITE_URL', 'http://localhost/grocery_pos/');
define('DEFAULT_DEMO_EMAIL', 'store@example.com');
define('DEFAULT_DEMO_PASSWORD', 'password123');
define('DEFAULT_DEMO_STORE_NAME', 'Demo Grocery Store');
define('DEFAULT_DEMO_USER_NAME', 'Store User');
define('DEFAULT_ADMIN_EMAIL', 'admin@example.com');
define('DEFAULT_ADMIN_PASSWORD', 'password123');
define('DEFAULT_ADMIN_NAME', 'System Admin');

// Security settings
define('CSRF_TOKEN_KEY', 'csrf_token');
define('SESSION_TIMEOUT', 3600); // 1 hour

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
