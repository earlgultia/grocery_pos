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
    <style>
        body {
            background: linear-gradient(180deg, #eef2ff 0%, #f8fafc 45%, #eef2ff 100%);
            color: #0f172a;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0f172a 0%, #111827 42%, #1e293b 100%);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 16px 0 40px rgba(15, 23, 42, 0.20);
        }

        .sidebar-header {
            padding: 1.55rem 1.4rem 1.25rem;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.10);
        }

        .sidebar-header h3 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem;
            letter-spacing: -0.03em;
            color: #fff;
        }

        .sidebar-header p {
            margin: 0.35rem 0 0;
            font-size: 0.86rem;
            color: rgba(255,255,255,0.82);
        }

        .sidebar-status {
            margin-top: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.42rem 0.72rem;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.14);
            color: #e0e7ff;
            border: 1px solid rgba(129, 140, 248, 0.24);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .sidebar-menu {
            padding: 0.85rem 0.75rem 1rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 0.75rem;
            padding: 0.9rem 0.95rem;
            margin-bottom: 0.25rem;
            border-radius: 1rem;
            color: rgba(255,255,255,0.92);
            text-decoration: none;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            font-weight: 600;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.28), rgba(34, 197, 94, 0.16));
            border-color: rgba(129, 140, 248, 0.24);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.04);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 1.75rem;
        }

        .page-shell {
            display: grid;
            gap: 1.25rem;
            max-width: 1120px;
            margin: 0 auto;
        }

        .panel {
            background: rgba(255,255,255,0.86);
            border: 1px solid rgba(15, 23, 42, 0.10);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border-radius: 1.2rem;
            padding: 1.5rem;
        }

        .panel h2 {
            margin: 0 0 0.5rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            color: #0f172a;
        }

        .panel p {
            margin: 0;
            color: #475569;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        .profile-card,
        .form-card {
            padding: 1.35rem;
            border-radius: 1.1rem;
            background: #ffffff;
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #475569;
            font-weight: 600;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.95rem 1rem;
            border-radius: 0.85rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #f8fafc;
            color: #0f172a;
            font-size: 1rem;
        }

        .password-input-wrap {
            position: relative;
        }

        .password-input-wrap input {
            width: 100%;
            box-sizing: border-box;
            padding-right: 3.9rem;
        }

        .toggle-password {
            position: absolute;
            top: 50%;
            right: 0.7rem;
            transform: translateY(-50%);
            border: 1px solid rgba(15, 23, 42, 0.14);
            background: linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%);
            color: #334155;
            cursor: pointer;
            width: 2.2rem;
            height: 2.2rem;
            line-height: 0;
            padding: 0;
            display: grid;
            place-items: center;
            border-radius: 0.7rem;
            box-shadow: 0 3px 10px rgba(15, 23, 42, 0.08);
            transition: all 0.2s ease;
        }

        .toggle-password svg {
            width: 1.1rem;
            height: 1.1rem;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .toggle-password:hover {
            color: #0f172a;
            background: #ffffff;
            transform: translateY(-50%) scale(1.03);
        }

        .toggle-password.is-visible {
            color: #1d4ed8;
            border-color: rgba(37, 99, 235, 0.28);
            background: linear-gradient(180deg, #eff6ff 0%, #dbeafe 100%);
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            margin-top: 1rem;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 0.9rem;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: #0f766e;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .message {
            padding: 1rem 1.1rem;
            border-radius: 0.95rem;
            font-weight: 600;
        }

        .message.success {
            background: rgba(22, 163, 74, 0.12);
            color: #166534;
            border: 1px solid rgba(22, 163, 74, 0.18);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.12);
            color: #991b1b;
            border: 1px solid rgba(239, 68, 68, 0.18);
        }

        @media (max-width: 960px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>🛒 <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">👤 Profile</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>📊</span> Dashboard
                </a>
                <a href="pos.php">
                    <span>💰</span> Point of Sale
                </a>
                <a href="products_management.php">
                    <span>📦</span> Products
                </a>
                <a href="inventory.php">
                    <span>📋</span> Inventory
                </a>
                <a href="sales_report.php">
                    <span>📈</span> Sales Report
                </a>
                <a href="profile.php" class="active">
                    <span>👤</span> Profile
                </a>
                <a href="logout.php">
                    <span>🚪</span> Logout
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
                        <div style="margin-top:1.5rem; display:grid; gap:1rem;">
                            <div>
                                <div style="font-size:0.85rem;color:#64748b;">Name</div>
                                <div style="font-weight:700;font-size:1rem;"><?php echo htmlspecialchars($user['name']); ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.85rem;color:#64748b;">Email</div>
                                <div style="font-weight:700;font-size:1rem;"><?php echo htmlspecialchars($user['email']); ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.85rem;color:#64748b;">Store</div>
                                <div style="font-weight:700;font-size:1rem;"><?php echo htmlspecialchars($store['store_name']); ?></div>
                            </div>
                            <div>
                                <div style="font-size:0.85rem;color:#64748b;">Role</div>
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
                                <label for="password">New password <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                                <div class="password-input-wrap">
                                    <input id="password" name="password" type="password" placeholder="Leave blank to keep current password">
                                    <button type="button" class="toggle-password" data-target="password" aria-label="Toggle password visibility">👁️</button>
                                </div>
                            </div>

                            <div class="form-group password-field">
                                <label for="confirm_password">Confirm new password</label>
                                <div class="password-input-wrap">
                                    <input id="confirm_password" name="confirm_password" type="password" placeholder="Repeat new password">
                                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Toggle password visibility">👁️</button>
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
</body>
</html>
