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

class AppFileSessionHandler implements SessionHandlerInterface {
    private $path;

    public function __construct($path) {
        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function open($savePath, $sessionName): bool {
        return is_dir($this->path) || @mkdir($this->path, 0777, true);
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $file = $this->getSessionFile($id);
        if (!is_file($file)) {
            return '';
        }

        $data = @file_get_contents($file);
        return $data === false ? '' : $data;
    }

    public function write($id, $data): bool {
        $file = $this->getSessionFile($id);
        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }

    public function destroy($id): bool {
        $file = $this->getSessionFile($id);
        return !is_file($file) || @unlink($file);
    }

    public function gc($maxLifetime): int|false {
        $deleted = 0;
        foreach (glob($this->path . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) + $maxLifetime < time() && @unlink($file)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function getSessionFile($id): string {
        $safeId = preg_replace('/[^a-zA-Z0-9,-]/', '', $id);
        return $this->path . DIRECTORY_SEPARATOR . 'sess_' . $safeId;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    @session_abort();
}

if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = appSessionPath();
} else {
    $sessionPath = null;
}

if ($sessionPath !== null) {
    ini_set('session.save_handler', 'files');
    ini_set('session.save_path', $sessionPath);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    session_save_path($sessionPath);
    session_set_save_handler(new AppFileSessionHandler($sessionPath), true);
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
