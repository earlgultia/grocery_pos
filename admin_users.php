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
    <style>
        :root {
            --bg: #f3f6ff;
            --bg-soft: #f8fafc;
            --panel: rgba(255, 255, 255, 0.9);
            --border: rgba(15, 23, 42, 0.1);
            --text: #0f172a;
            --muted: #475569;
        }

        body {
            font-family: 'Inter', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 5% 15%, rgba(29, 78, 216, 0.13), transparent 35%),
                radial-gradient(circle at 95% 5%, rgba(14, 165, 233, 0.14), transparent 30%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-soft) 44%, #eef2ff 100%);
        }

        .dashboard-container {
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0f172a 0%, #111827 40%, #1e293b 100%);
            position: fixed;
            height: 100vh;
            color: #fff;
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 16px 0 40px rgba(15, 23, 42, 0.2);
        }

        .sidebar-header {
            padding: 1.55rem 1.4rem 1.2rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar-header h3 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.08rem;
            letter-spacing: -0.02em;
        }

        .sidebar-header p {
            margin: 0.35rem 0 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 0.86rem;
        }

        .sidebar-status {
            margin-top: 0.9rem;
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.7rem;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #dbeafe;
            border: 1px solid rgba(147, 197, 253, 0.26);
            background: rgba(37, 99, 235, 0.16);
        }

        .sidebar-menu {
            padding: 0.85rem 0.75rem;
            display: grid;
            gap: 0.25rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.94);
            border-radius: 0.95rem;
            padding: 0.85rem 0.95rem;
            border: 1px solid transparent;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .sidebar-menu a.active,
        .sidebar-menu a:hover {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.28), rgba(14, 165, 233, 0.18));
            border-color: rgba(147, 197, 253, 0.3);
        }

        .main-content {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 1.75rem;
        }

        .page-shell {
            display: grid;
            gap: 1rem;
            max-width: 1100px;
            margin: 0 auto;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 1.15rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            padding: 1.35rem;
        }

        .page-title {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.4rem;
        }

        .subtitle {
            margin: 0.4rem 0 0;
            color: var(--muted);
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.94rem;
        }

        th,
        td {
            padding: 0.72rem;
            text-align: left;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            white-space: nowrap;
        }

        th {
            background: rgba(29, 78, 216, 0.06);
            font-weight: 700;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.5rem;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-ok {
            color: #166534;
            background: rgba(34, 197, 94, 0.15);
        }

        .badge-muted {
            color: #334155;
            background: rgba(148, 163, 184, 0.22);
        }

        .muted {
            color: var(--muted);
        }

        .row-actions {
            display: flex;
            gap: 0.45rem;
        }

        .inline-form {
            margin: 0;
        }

        .btn-action {
            border: none;
            border-radius: 0.6rem;
            padding: 0.46rem 0.62rem;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.78rem;
        }

        .btn-warning {
            background: rgba(234, 179, 8, 0.18);
            color: #854d0e;
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.16);
            color: #991b1b;
        }

        .form-message {
            margin-bottom: 0.85rem;
            padding: 0.72rem 0.85rem;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.92rem;
        }

        .form-message.success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.22);
            color: #166534;
        }

        .form-message.error {
            background: rgba(239, 68, 68, 0.12);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #991b1b;
        }

        .no-data {
            color: var(--muted);
            text-align: center;
            padding: 0.85rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
        }
    </style>
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
