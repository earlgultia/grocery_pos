<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';
ensureDefaultDemoAccount();
redirectIfLoggedIn();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } elseif (!checkRateLimit('login_' . $_SERVER['REMOTE_ADDR'])) {
        $error = 'Too many login attempts. Please try again later.';
    } else {
        $email = sanitizeInput($_POST['email']);
        $password = $_POST['password'];
        
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && verifyPassword($password, $user['password'])) {
                $role = $user['role'] ?? '';

                if ($role === 'deactivated') {
                    $error = 'Your account is deactivated. Please contact the administrator.';
                    logActivity($user['id'], 'blocked_login', 'Blocked login for deactivated account');
                } elseif (!in_array($role, ['admin', 'store_user'], true)) {
                    $error = 'Your account role is invalid. Please contact the administrator.';
                    logActivity($user['id'], 'blocked_login', 'Blocked login for invalid role');
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $role;
                    regenerateSession();
                    logActivity($user['id'], 'login', 'User logged in successfully');
                    redirectToRoleDashboard($role);
                }
            } else {
                $error = 'Invalid email or password';
                logActivity(null, 'failed_login', "Failed login attempt for email: $email");
            }
        } catch(PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $csrf_token; ?>">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="css/style.css">
    
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="auth-panel">
                <div class="auth-brand">
                    <div class="auth-brand-badge">ðŸ›’</div>
                    <div>
                        <p class="auth-eyebrow"><?php echo SITE_NAME; ?></p>
                        <h1>Welcome back</h1>
                        <p class="auth-subtitle">Sign in to manage checkout, inventory, and store performance from one place.</p>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="auth-alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" required class="form-input" placeholder="you@example.com">
                    </div>
                    
                    <div class="form-group password-field">
                        <label>Password</label>
                        <div class="password-input-wrap">
                            <input id="login_password" type="password" name="password" required class="form-input" placeholder="Your password">
                            <button type="button" class="toggle-password" data-target="login_password" aria-label="Show password">ðŸ‘ï¸</button>
                        </div>
                    </div>
                    
                    <div class="auth-actions">
                        <button type="submit" class="btn btn-primary btn-block">Login</button>
                        <a href="index.php" class="btn btn-outline btn-block">Back to Home</a>
                    </div>
                </form>
                
                <p class="auth-footer">
                    Secure access is ready for your store account. No registration step is required.
                </p>
            </div>
        </section>
    </main>
    
    
    <script src="js/main.js"></script>
</body>
</html>
