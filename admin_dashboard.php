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
$errorMessage = '';

function fetchScalarSafe(PDO $db, $sql, $params = [], $default = 0) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function fetchAllSafe(PDO $db, $sql, $params = []) {
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

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

$totalStores = (int) fetchScalarSafe($db, 'SELECT COUNT(*) FROM stores', [], 0);
$activeStores = (int) fetchScalarSafe($db, 'SELECT COUNT(*) FROM stores WHERE is_active = 1', [], 0);
$totalUsers = (int) fetchScalarSafe($db, 'SELECT COUNT(*) FROM users', [], 0);
$totalProducts = (int) fetchScalarSafe($db, 'SELECT COUNT(*) FROM products', [], 0);
$todayTransactions = (int) fetchScalarSafe(
    $db,
    "SELECT COUNT(*) FROM transactions WHERE DATE(created_at) = CURDATE()",
    [],
    0
);
$todaySales = (float) fetchScalarSafe(
    $db,
    "SELECT COALESCE(SUM(total_amount), 0) FROM transactions WHERE DATE(created_at) = CURDATE() AND status = 'completed'",
    [],
    0
);
$monthlySales = (float) fetchScalarSafe(
    $db,
    "SELECT COALESCE(SUM(total_amount), 0) FROM transactions WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'",
    [],
    0
);

$lowStockCount = (int) fetchScalarSafe(
    $db,
    'SELECT COUNT(*) FROM products WHERE quantity <= low_stock_threshold',
    [],
    0
);

$topStores = fetchAllSafe(
    $db,
    "SELECT s.store_name,
            COUNT(t.id) AS total_transactions,
            COALESCE(SUM(t.total_amount), 0) AS total_revenue
     FROM stores s
     LEFT JOIN transactions t
            ON t.store_id = s.id
           AND t.status = 'completed'
     GROUP BY s.id, s.store_name
     ORDER BY total_revenue DESC
     LIMIT 6"
);

$recentUsers = fetchAllSafe(
    $db,
    'SELECT id, name, email, role FROM users ORDER BY id DESC LIMIT 8'
);

$recentTransactions = fetchAllSafe(
    $db,
    "SELECT invoice_number, customer_name, total_amount, status, created_at
     FROM transactions
     ORDER BY created_at DESC
     LIMIT 8"
);

$chartLabels = [];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chartLabels[] = date('M d', strtotime($date));
    $chartData[] = (float) fetchScalarSafe(
        $db,
        "SELECT COALESCE(SUM(total_amount), 0)
         FROM transactions
         WHERE DATE(created_at) = ?
           AND status = 'completed'",
        [$date],
        0
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
                <a href="admin_dashboard.php" class="active">
                    <span>Overview</span>
                </a>
                <a href="create_store_user.php">
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
                    <h1 class="page-title">Admin Dashboard</h1>
                    <p class="subtitle">Monitor system health, sales, users, and store performance from one screen.</p>
                </section>

                <?php if ($errorMessage): ?>
                    <section class="panel"><?php echo htmlspecialchars($errorMessage); ?></section>
                <?php endif; ?>

                <section class="stats-grid">
                    <article class="stat-card">
                        <div class="stat-label">Total Stores</div>
                        <div class="stat-value"><?php echo number_format($totalStores); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Active Stores</div>
                        <div class="stat-value"><?php echo number_format($activeStores); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-value"><?php echo number_format($totalUsers); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Products</div>
                        <div class="stat-value"><?php echo number_format($totalProducts); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Today Sales</div>
                        <div class="stat-value">$<?php echo number_format($todaySales, 2); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Monthly Sales</div>
                        <div class="stat-value">$<?php echo number_format($monthlySales, 2); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Today Transactions</div>
                        <div class="stat-value"><?php echo number_format($todayTransactions); ?></div>
                    </article>
                    <article class="stat-card">
                        <div class="stat-label">Low Stock Items</div>
                        <div class="stat-value"><?php echo number_format($lowStockCount); ?></div>
                    </article>
                </section>

                <section class="panel">
                    <h2 class="section-title">Sales Trend (Last 7 Days)</h2>
                    <canvas id="adminSalesChart" height="95"></canvas>
                </section>

                <section class="layout-grid">
                    <article class="panel">
                        <h2 class="section-title">Top Stores by Revenue</h2>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Store</th>
                                        <th>Transactions</th>
                                        <th>Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topStores as $storeRow): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($storeRow['store_name']); ?></td>
                                            <td><?php echo number_format((int) $storeRow['total_transactions']); ?></td>
                                            <td>$<?php echo number_format((float) $storeRow['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($topStores)): ?>
                                        <tr>
                                            <td colspan="3" class="no-data">No store sales data available.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="panel">
                        <h2 class="section-title">Recent Users</h2>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentUsers as $recentUser): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($recentUser['name']); ?></td>
                                            <td class="muted"><?php echo htmlspecialchars($recentUser['email']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $recentUser['role'] === 'admin' ? 'badge-ok' : 'badge-muted'; ?>">
                                                    <?php echo htmlspecialchars($recentUser['role']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($recentUsers)): ?>
                                        <tr>
                                            <td colspan="3" class="no-data">No users found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <section class="panel">
                    <h2 class="section-title">Recent Transactions</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentTransactions as $transaction): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transaction['invoice_number']); ?></td>
                                        <td><?php echo htmlspecialchars($transaction['customer_name']); ?></td>
                                        <td>$<?php echo number_format((float) $transaction['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge <?php echo $transaction['status'] === 'completed' ? 'badge-ok' : 'badge-muted'; ?>">
                                                <?php echo htmlspecialchars($transaction['status']); ?>
                                            </span>
                                        </td>
                                        <td class="muted"><?php echo htmlspecialchars($transaction['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentTransactions)): ?>
                                    <tr>
                                        <td colspan="5" class="no-data">No transactions available.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <script>
        const chartTarget = document.getElementById('adminSalesChart');
        if (chartTarget) {
            const adminSalesChart = new Chart(chartTarget.getContext('2d'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chartLabels); ?>,
                    datasets: [{
                        label: 'Daily Sales ($)',
                        data: <?php echo json_encode($chartData); ?>,
                        borderColor: '#1d4ed8',
                        backgroundColor: 'rgba(29, 78, 216, 0.12)',
                        fill: true,
                        borderWidth: 2,
                        tension: 0.32,
                        pointRadius: 3,
                        pointHoverRadius: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>

