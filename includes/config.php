<?php
// includes/config.php
function appSessionPath() {
    $paths = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . 'sessions',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'grocery_pos_sessions'
    ];

    foreach ($paths as $path) {
        if (!is_dir($path)) {
            @mkdir($path, 0777, true);
        }

        if (!is_dir($path)) {
            continue;
        }

        $testFile = $path . DIRECTORY_SEPARATOR . '.session_write_test';
        if (@file_put_contents($testFile, 'ok') !== false) {
            @unlink($testFile);
            return $path;
        }
    }

    return null;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_write_close();
}

if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = appSessionPath();
} else {
    $sessionPath = null;
}

if ($sessionPath !== null) {
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', $sessionPath);
    session_save_path($sessionPath);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
