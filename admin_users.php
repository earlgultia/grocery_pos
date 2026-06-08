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
$actionError = '';
$actionSuccess = '';

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
    $action = $_POST['action'] ?? '';
    $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;

    if (!verifyCSRFToken($csrfToken)) {
        $actionError = 'Invalid form submission. Please refresh and try again.';
    } elseif ($targetUserId <= 0) {
        $actionError = 'Invalid user selected.';
    } elseif (!in_array($action, ['deactivate', 'delete'], true)) {
        $actionError = 'Invalid action.';
    } else {
        try {
            $stmt = $db->prepare("SELECT id, role, store_id, name, email FROM users WHERE id = ? AND role IN ('store_user', 'deactivated') LIMIT 1");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $actionError = 'User not found or cannot be managed.';
            } elseif ((int) $targetUser['id'] === (int) $_SESSION['user_id']) {
                $actionError = 'You cannot modify your own account from this screen.';
            } else {
                if ($action === 'deactivate') {
                    if ($targetUser['role'] === 'deactivated') {
                        $actionError = 'That account is already deactivated.';
                    } else {
                        $stmt = $db->prepare("UPDATE users SET role = 'deactivated' WHERE id = ? AND role = 'store_user'");
                        $stmt->execute([$targetUserId]);

                        if ($stmt->rowCount() > 0) {
                            $actionSuccess = 'User account deactivated successfully.';
                        } else {
                            $actionError = 'Unable to deactivate that account.';
                        }
                    }
                }

                if ($action === 'delete') {
                    $db->beginTransaction();

                    $stmt = $db->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
                    $stmt->execute([$targetUserId]);

                    if ($stmt->rowCount() === 0) {
                        $db->rollBack();
                        $actionError = 'Unable to delete that account.';
                    } else {
                        $storeId = isset($targetUser['store_id']) ? (int) $targetUser['store_id'] : 0;

                        if ($storeId > 0) {
                            $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE store_id = ?');
                            $stmt->execute([$storeId]);
                            $remainingUsers = (int) $stmt->fetchColumn();

                            if ($remainingUsers === 0) {
                                $stmt = $db->prepare('DELETE FROM stores WHERE id = ? LIMIT 1');
                                $stmt->execute([$storeId]);
                            }
                        }

                        $db->commit();
                        $actionSuccess = 'User account deleted successfully.';
                    }
                }
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $actionError = 'Unable to process the action right now. Please try again.';
        }
    }
}

$managedUsers = [];
try {
    $stmt = $db->query(
        "SELECT u.id, u.name, u.email, u.role, u.store_id, s.store_name
         FROM users u
         LEFT JOIN stores s ON s.id = u.store_id
         WHERE u.role IN ('store_user', 'deactivated')
         ORDER BY u.id DESC"
    );
    $managedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $actionError = 'Unable to load users list right now.';
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?php echo htmlspecialchars(SITE_NAME); ?></title>
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
                <a href="create_store_user.php">
                    <span>Create Store User</span>
                </a>
                <a href="admin_users.php" class="active">
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
                    <h1 class="page-title">Users Management</h1>
                    <p class="subtitle">View created store users and deactivate or delete accounts.</p>
                </section>

                <section class="panel">
                    <?php if ($actionSuccess): ?>
                        <div class="form-message success"><?php echo htmlspecialchars($actionSuccess); ?></div>
                    <?php endif; ?>

                    <?php if ($actionError): ?>
                        <div class="form-message error"><?php echo htmlspecialchars($actionError); ?></div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Store</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($managedUsers as $managedUser): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($managedUser['name']); ?></td>
                                        <td class="muted"><?php echo htmlspecialchars($managedUser['email']); ?></td>
                                        <td><?php echo htmlspecialchars($managedUser['store_name'] ?: 'N/A'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $managedUser['role'] === 'store_user' ? 'badge-ok' : 'badge-muted'; ?>">
                                                <?php echo $managedUser['role'] === 'store_user' ? 'active' : 'deactivated'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="row-actions">
                                                <?php if ($managedUser['role'] === 'store_user'): ?>
                                                    <form method="post" class="inline-form" action="admin_users.php" data-confirm="Deactivate this user account?" data-confirm-text="Deactivate">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                                                        <button type="submit" class="btn-action btn-warning">Deactivate</button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="post" class="inline-form" action="admin_users.php" onsubmit="return confirm('Delete this user account permanently?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="user_id" value="<?php echo (int) $managedUser['id']; ?>">
                                                    <button type="submit" class="btn-action btn-danger">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($managedUsers)): ?>
                                    <tr>
                                        <td colspan="5" class="no-data">No store user accounts found.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script src="js/app-nav.js"></script>
</body>
</html>

