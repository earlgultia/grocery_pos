<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;
$errorMessage = '';
$successMessage = '';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'store_user') {
    header('Location: login.php');
    exit();
}

try {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['store_id'])) {
        header('Location: login.php');
        exit();
    }

    $stmt = $db->prepare('SELECT * FROM stores WHERE id = ?');
    $stmt->execute([$user['store_id']]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$store) {
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
        $errorMessage = 'Invalid form submission. Please try again.';
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $storeName = sanitizeInput($_POST['store_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($name === '' || $email === '' || $storeName === '') {
            $errorMessage = 'Name, email, and store name are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Please enter a valid email address.';
        } elseif ($password !== '' && strlen($password) < 8) {
            $errorMessage = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $errorMessage = 'Passwords do not match.';
        } else {
            try {
                $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $stmt->execute([$email, $user['id']]);
                $existingUser = $stmt->fetchColumn();

                if ($existingUser) {
                    $errorMessage = 'This email address is already in use.';
                } else {
                    $db->beginTransaction();

                    $updateUserSql = 'UPDATE users SET name = ?, email = ?';
                    $updateParams = [$name, $email];

                    if ($password !== '') {
                        $updateUserSql .= ', password = ?';
                        $updateParams[] = hashPassword($password);
                    }

                    $updateUserSql .= ' WHERE id = ?';
                    $updateParams[] = $user['id'];

                    $stmt = $db->prepare($updateUserSql);
                    $stmt->execute($updateParams);

                    $stmt = $db->prepare('UPDATE stores SET store_name = ? WHERE id = ?');
                    $stmt->execute([$storeName, $store['id']]);

                    $db->commit();

                    $_SESSION['profile_update_success'] = 'Your profile has been updated successfully.';
                    header('Location: profile.php');
                    exit();
                }
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errorMessage = 'Unable to update profile. Please try again later.';
            }
        }
    }
}

if (!empty($_SESSION['profile_update_success'])) {
    $successMessage = $_SESSION['profile_update_success'];
    unset($_SESSION['profile_update_success']);
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ‘¤ Profile</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>ðŸ“Š</span> Dashboard
                </a>
                <a href="pos.php">
                    <span>ðŸ’°</span> Point of Sale
                </a>
                <a href="products_management.php">
                    <span>ðŸ“¦</span> Products
                </a>
                <a href="inventory.php">
                    <span>ðŸ“‹</span> Inventory
                </a>
                <a href="sales_report.php">
                    <span>ðŸ“ˆ</span> Sales Report
                </a>
                <a href="profile.php" class="active">
                    <span>ðŸ‘¤</span> Profile
                </a>
                <a href="logout.php">
                    <span>ðŸšª</span> Logout
                </a>
            </div>
        </div>

        <div class="main-content">
            <div class="page-shell">
                <section class="panel">
                    <div>
                        <h2>Your profile</h2>
                        <p>Keep your account details up to date and change your password securely.</p>
                    </div>
                </section>

                <?php if ($successMessage): ?>
                    <section class="panel message success"><?php echo htmlspecialchars($successMessage); ?></section>
                <?php endif; ?>

                <?php if ($errorMessage): ?>
                    <section class="panel message error"><?php echo htmlspecialchars($errorMessage); ?></section>
                <?php endif; ?>

                <section class="profile-grid">
                    <div class="profile-card">
                        <h2>Account overview</h2>
                        <p>View your login email, role, and store settings.</p>
                        <div class="detail-grid">
                            <div class="detail-row">
                                <div class="detail-label">Name</div>
                                <div class="detail-value"><?php echo htmlspecialchars($user['name']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Store</div>
                                <div class="detail-value"><?php echo htmlspecialchars($store['store_name']); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Role</div>
                                <div class="status-pill"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($user['role']))); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h2>Edit profile</h2>
                        <p>Update your name, email, store name, or password below.</p>
                        <form method="post" action="profile.php">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                            <div class="form-group">
                                <label for="name">Full name</label>
                                <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($user['name']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email address</label>
                                <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="store_name">Store name</label>
                                <input id="store_name" name="store_name" type="text" value="<?php echo htmlspecialchars($store['store_name']); ?>" required>
                            </div>

                            <div class="form-group password-field">
                                <label for="password">New password <span class="form-note">(optional)</span></label>
                                <div class="password-input-wrap">
                                    <input id="password" name="password" type="password" placeholder="Leave blank to keep current password">
                                    <button type="button" class="toggle-password" data-target="password" aria-label="Toggle password visibility">ðŸ‘ï¸</button>
                                </div>
                            </div>

                            <div class="form-group password-field">
                                <label for="confirm_password">Confirm new password</label>
                                <div class="password-input-wrap">
                                    <input id="confirm_password" name="confirm_password" type="password" placeholder="Repeat new password">
                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password visibility">ðŸ‘ï¸</button>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save changes</button>
                                <a href="store_dashboard.php" class="btn btn-outline">Back to dashboard</a>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <script>
        const eyeOpenIcon = `
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"></path>
                <circle cx="12" cy="12" r="3"></circle>
            </svg>
        `;

        const eyeOffIcon = `
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M3 3l18 18"></path>
                <path d="M10.58 10.58A2 2 0 0 0 12 14a2 2 0 0 0 1.42-.58"></path>
                <path d="M9.88 5.09A9.77 9.77 0 0 1 12 5c7 0 11 7 11 7a20.2 20.2 0 0 1-3.24 4.19"></path>
                <path d="M6.61 6.61C3.62 8.44 1 12 1 12a20.3 20.3 0 0 0 7.39 5.39"></path>
            </svg>
        `;

        document.querySelectorAll('.toggle-password').forEach(button => {
            button.innerHTML = eyeOpenIcon;
            button.setAttribute('aria-label', 'Show password');

            button.addEventListener('click', () => {
                const targetId = button.dataset.target;
                const input = document.getElementById(targetId);
                if (!input) return;

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.classList.toggle('is-visible', isPassword);
                button.innerHTML = isPassword ? eyeOffIcon : eyeOpenIcon;
                button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        });
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>

