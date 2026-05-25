<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = getDB();
$user = null;
$formError = '';
$formSuccess = '';

try {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: login.php');
        exit();
    }
} catch (PDOException $e) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!verifyCSRFToken($csrfToken)) {
        $formError = 'Invalid form submission. Please refresh and try again.';
    } else {
        $storeName = sanitizeInput($_POST['store_name'] ?? '');
        $ownerName = sanitizeInput($_POST['owner_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($storeName === '' || $ownerName === '' || $email === '' || $password === '' || $confirmPassword === '') {
            $formError = 'All fields are required to create a store user account.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $formError = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $formError = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $formError = 'Passwords do not match.';
        } else {
            try {
                // Check for existing email in users and (optionally) stores to avoid unique constraint errors
                $emailExists = false;
                try {
                    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    if ($stmt->fetchColumn()) {
                        $emailExists = true;
                    }

                    // Detect if stores table has a store_email column and check it
                    $hasStoreEmail = false;
                    try {
                        $colCheck = $db->prepare("SHOW COLUMNS FROM stores LIKE 'store_email'");
                        $colCheck->execute();
                        if ($colCheck->fetch()) {
                            $hasStoreEmail = true;
                        }
                    } catch (PDOException $inner) {
                        // ignore - older schema may not have store_email
                        $hasStoreEmail = false;
                    }

                    if (!$emailExists && $hasStoreEmail) {
                        $stmt = $db->prepare('SELECT id FROM stores WHERE store_email = ? LIMIT 1');
                        $stmt->execute([$email]);
                        if ($stmt->fetchColumn()) {
                            $emailExists = true;
                        }
                    }
                } catch (PDOException $e) {
                    // If the checks fail, fall back to attempting insert and let the DB report errors
                }

                if ($emailExists) {
                    $formError = 'That email is already registered.';
                } else {
                    $db->beginTransaction();

                    $storeId = insertStoreRecord($storeName, $email);

                    $stmt = $db->prepare('INSERT INTO users (name, email, password, role, store_id) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $ownerName,
                        $email,
                        hashPassword($password),
                        'store_user',
                        $storeId
                    ]);

                    $db->commit();
                    $formSuccess = 'Store user account created successfully.';
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $formError = 'Unable to create store user account right now. Please try again.';
                if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
                    logActivity($_SESSION['user_id'], 'error', 'Create store user failed: ' . $e->getMessage());
                }
            }
        }
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Create Store User - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h3>System Admin</h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status"><?php echo date('F j, Y'); ?> | Control Panel</div>
            </div>
            <nav class="sidebar-menu">
                <a href="admin_dashboard.php">
                    <span>Overview</span>
                </a>
                <a href="create_store_user.php" class="active">
                    <span>Create Store User</span>
                </a>
                <a href="admin_users.php">
                    <span>Users</span>
                </a>
                <a href="logout.php">
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <main class="main-content">
            <div class="page-shell">
                <section class="panel">
                    <h1 class="page-title">Create Store User Account</h1>
                    <p class="subtitle">Admin can only create accounts with role <strong>store_user</strong>.</p>

                    <?php if ($formSuccess): ?>
                        <div class="form-message success"><?php echo htmlspecialchars($formSuccess); ?></div>
                    <?php endif; ?>

                    <?php if ($formError): ?>
                        <div class="form-message error"><?php echo htmlspecialchars($formError); ?></div>
                    <?php endif; ?>

                    <form method="post" action="create_store_user.php" class="create-store-grid">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                        <div class="form-group">
                            <label for="store_name">Store name</label>
                            <input id="store_name" name="store_name" type="text" required class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="owner_name">Owner full name</label>
                            <input id="owner_name" name="owner_name" type="text" required class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="store_email">Store login email</label>
                            <input id="store_email" name="email" type="email" required class="form-input">
                        </div>

                        <div class="form-group">
                            <label for="store_password">Temporary password</label>
                            <div class="password-input-wrap">
                                <input id="store_password" name="password" type="password" minlength="8" required class="form-input">
                                <button type="button" class="toggle-password" data-target="store_password" aria-label="Show password"></button>
                            </div>
                        </div>

                        <div class="form-group form-full">
                            <label for="store_confirm_password">Confirm password</label>
                            <div class="password-input-wrap">
                                <input id="store_confirm_password" name="confirm_password" type="password" minlength="8" required class="form-input">
                                <button type="button" class="toggle-password" data-target="store_confirm_password" aria-label="Show password"></button>
                            </div>
                        </div>

                        <div class="form-full">
                            <button type="submit" class="action-btn">Create Store User Account</button>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
    <script src="js/main.js"></script>
    <script src="js/app-nav.js"></script>
</body>
    
</html>
