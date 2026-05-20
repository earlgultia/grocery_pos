<?php
// includes/functions.php
require_once 'config.php';

// CSRF Protection
function generateCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_KEY];
}

function verifyCSRFToken($token) {
    return isset($_SESSION[CSRF_TOKEN_KEY]) && hash_equals($_SESSION[CSRF_TOKEN_KEY], $token);
}

// XSS Protection
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// Session security
function regenerateSession() {
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}

function isSessionExpired() {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        session_unset();
        session_destroy();
        return true;
    }
    return false;
}

// Authentication
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !isSessionExpired();
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . 'login.php');
        exit();
    }
}

function getDashboardPathByRole($role) {
    return $role === 'admin' ? 'admin_dashboard.php' : 'store_dashboard.php';
}

function redirectToRoleDashboard($role) {
    header('Location: ' . SITE_URL . getDashboardPathByRole($role));
    exit();
}

function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        $role = $_SESSION['user_role'] ?? 'store_user';
        redirectToRoleDashboard($role);
    }
}

// Password hashing (using PHP's built-in functions)
function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function ensureDefaultDemoAccount() {
    try {
        $db = getDB();
        $demoEmail = defined('DEFAULT_DEMO_EMAIL') ? constant('DEFAULT_DEMO_EMAIL') : 'admin@grocerypos.com';
        $demoPassword = defined('DEFAULT_DEMO_PASSWORD') ? constant('DEFAULT_DEMO_PASSWORD') : 'password123';
        $demoStoreName = defined('DEFAULT_DEMO_STORE_NAME') ? constant('DEFAULT_DEMO_STORE_NAME') : 'Demo Grocery Store';
        $demoUserName = defined('DEFAULT_DEMO_USER_NAME') ? constant('DEFAULT_DEMO_USER_NAME') : 'Demo User';
        $adminEmail = defined('DEFAULT_ADMIN_EMAIL') ? constant('DEFAULT_ADMIN_EMAIL') : 'admin@example.com';
        $adminPassword = defined('DEFAULT_ADMIN_PASSWORD') ? constant('DEFAULT_ADMIN_PASSWORD') : 'admin12345';
        $adminName = defined('DEFAULT_ADMIN_NAME') ? constant('DEFAULT_ADMIN_NAME') : 'System Admin';

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$demoEmail]);
        $existingUserId = $stmt->fetchColumn();

        $stmt = $db->prepare("SELECT id FROM stores WHERE store_name = ? LIMIT 1");
        $stmt->execute([$demoStoreName]);
        $storeId = $stmt->fetchColumn();

        if (!$storeId) {
            $stmt = $db->prepare("INSERT INTO stores (store_name, is_active) VALUES (?, 1)");
            $stmt->execute([$demoStoreName]);
            $storeId = $db->lastInsertId();
        }

        if ($existingUserId) {
            $stmt = $db->prepare("UPDATE users SET name = ?, password = ?, role = ?, store_id = ? WHERE id = ?");
            $stmt->execute([
                $demoUserName,
                hashPassword($demoPassword),
                'store_user',
                $storeId,
                $existingUserId
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $demoUserName,
                $demoEmail,
                hashPassword($demoPassword),
                'store_user',
                $storeId
            ]);
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$adminEmail]);
        $adminUserId = $stmt->fetchColumn();

        if ($adminUserId) {
            $stmt = $db->prepare("UPDATE users SET name = ?, password = ?, role = ?, store_id = ? WHERE id = ?");
            $stmt->execute([
                $adminName,
                hashPassword($adminPassword),
                'admin',
                $storeId,
                $adminUserId
            ]);
            return;
        }

        $stmt = $db->prepare("INSERT INTO users (name, email, password, role, store_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $adminName,
            $adminEmail,
            hashPassword($adminPassword),
            'admin',
            $storeId
        ]);
    } catch (PDOException $e) {
        // Ignore seeding failures so the login page still renders.
    }
}

// Rate limiting
function checkRateLimit($key, $limit = 5, $timeWindow = 300) {
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = ['count' => 1, 'first_attempt' => time()];
        return true;
    }
    
    $data = $_SESSION['rate_limit'][$key];
    $timeSinceFirst = time() - $data['first_attempt'];
    
    if ($timeSinceFirst <= $timeWindow) {
        if ($data['count'] >= $limit) {
            return false;
        }
        $_SESSION['rate_limit'][$key]['count']++;
    } else {
        $_SESSION['rate_limit'][$key] = ['count' => 1, 'first_attempt' => time()];
    }
    
    return true;
}

// Log user activity
function logActivity($userId, $action, $details = null) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $userId, 
            $action, 
            $details, 
            $_SERVER['REMOTE_ADDR'], 
            $_SERVER['HTTP_USER_AGENT']
        ]);
    } catch(PDOException $e) {
        // Silent fail for logs
    }
}

// Function to create user_logs table if not exists
function createLogsTable() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS user_logs (
            id BIGINT NOT NULL AUTO_INCREMENT,
            user_id BIGINT NOT NULL,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_user_logs_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
}
?>