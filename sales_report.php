<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;

$startDate = sanitizeInput($_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
$endDate = sanitizeInput($_GET['end_date'] ?? date('Y-m-d'));
$paymentMethod = sanitizeInput($_GET['payment_method'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? 'completed');
$query = sanitizeInput($_GET['q'] ?? '');

try {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'store_user') {
        header('Location: login.php');
        exit();
    }

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

$where = ['store_id = ?'];
$params = [$store['id']];

if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

if ($paymentMethod !== '') {
    $where[] = 'payment_method = ?';
    $params[] = $paymentMethod;
}

if ($query !== '') {
    $where[] = '(invoice_number LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $query . '%';
    array_push($params, $like, $like);
}

if ($startDate !== '') {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $startDate;
}

if ($endDate !== '') {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $endDate;
}

$whereClause = implode(' AND ', $where);

// Summary metrics
$stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) AS total_sales, COUNT(*) AS transaction_count, COALESCE(AVG(total_amount), 0) AS avg_ticket FROM transactions WHERE {$whereClause}");
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT COUNT(DISTINCT customer_name) AS unique_customers FROM transactions WHERE {$whereClause}");
$stmt->execute($params);
$uniqueCustomers = (int)$stmt->fetchColumn();

// Payment methods
$paymentsStmt = $db->prepare("SELECT payment_method, COUNT(*) AS count FROM transactions WHERE {$whereClause} GROUP BY payment_method ORDER BY count DESC");
$paymentsStmt->execute($params);
$paymentMethods = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Chart data for last 14 days
$chartLabels = [];
$chartData = [];
for ($i = 13; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('M d', strtotime($date));
    $chartLabels[] = $label;

    $dailyStmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) AS daily_sales FROM transactions WHERE store_id = ? AND DATE(created_at) = ?" . ($statusFilter !== '' ? " AND status = ?" : "") . ($paymentMethod !== '' ? " AND payment_method = ?" : ""));
    $dailyParams = [$store['id'], $date];
    if ($statusFilter !== '') {
        $dailyParams[] = $statusFilter;
    }
    if ($paymentMethod !== '') {
        $dailyParams[] = $paymentMethod;
    }
    $dailyStmt->execute($dailyParams);
    $dailySales = $dailyStmt->fetchColumn();
    $chartData[] = (float)$dailySales;
}

// Transaction list
$transactionsStmt = $db->prepare("SELECT * FROM transactions WHERE {$whereClause} ORDER BY created_at DESC LIMIT 100");
$transactionsStmt->execute($params);
$transactions = $transactionsStmt->fetchAll(PDO::FETCH_ASSOC);

$paymentMethodOptions = ['cash' => 'Cash', 'card' => 'Card', 'other' => 'Other'];
$statusOptions = ['completed' => 'Completed'];

function buildQueryString(array $params): string
{
    return http_build_query(array_filter($params, fn($value) => $value !== '' && $value !== null));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ“… <?php echo date('F j, Y'); ?> Â· Sales</div>
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
                <a href="sales_report.php" class="active">
                    <span>ðŸ“ˆ</span> Sales Report
                </a>
                <a href="profile.php">
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
                    <div class="panel-header">
                        <div>
                            <h2>Sales report</h2>
                            <p>Analyze revenue, transaction volume, and payment trends across your store.</p>
                        </div>
                    </div>

                    <form class="search-bar" method="get" action="sales_report.php">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($query); ?>" placeholder="Search invoice or customer...">
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        <select name="payment_method">
                            <option value="">All payment types</option>
                            <?php foreach ($paymentMethodOptions as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $paymentMethod === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <?php foreach ($statusOptions as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $statusFilter === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Update report</button>
                        <a href="sales_report.php" class="btn btn-outline">Reset</a>
                    </form>
                </section>

                <section class="panel stats-grid">
                    <div class="stat-card">
                        <span class="label">Total sales</span>
                        <div class="value">$<?php echo number_format((float)$summary['total_sales'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <span class="label">Transactions</span>
                        <div class="value"><?php echo number_format((int)$summary['transaction_count']); ?></div>
                    </div>
                    <div class="stat-card">
                        <span class="label">Avg. ticket</span>
                        <div class="value">$<?php echo number_format((float)$summary['avg_ticket'], 2); ?></div>
                    </div>
                    <div class="stat-card">
                        <span class="label">Unique customers</span>
                        <div class="value"><?php echo number_format($uniqueCustomers); ?></div>
                    </div>
                </section>

                <section class="panel chart-card">
                    <div class="panel-header">
                        <div>
                            <h2>Sales over last 14 days</h2>
                            <p class="section-subtitle">Daily completed sales volume for the selected filters.</p>
                        </div>
                    </div>
                    <canvas id="salesChart"></canvas>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Payment breakdown</h2>
                            <p class="section-subtitle">How customers are paying for orders.</p>
                        </div>
                    </div>
                    <div class="stats-grid">
                        <?php if (empty($paymentMethods)): ?>
                            <div class="stat-card empty-state">No payment activity for the selected filters.</div>
                        <?php else: ?>
                            <?php foreach ($paymentMethods as $method): ?>
                                <div class="stat-card">
                                    <span class="label"><?php echo htmlspecialchars(ucfirst($method['payment_method'])); ?></span>
                                    <div class="value"><?php echo number_format((int)$method['count']); ?> sales</div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Recent transactions</h2>
                            <p class="section-subtitle">The latest orders matching your current report filters.</p>
                        </div>
                    </div>
                    <div class="report-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Total</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="6" class="empty-state-cell">No transactions found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['invoice_number'] ?? 'â€”'); ?></td>
                                            <td><?php echo htmlspecialchars($transaction['customer_name'] ?: 'Walk-in customer'); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($transaction['payment_method'])); ?></td>
                                            <td><span class="badge badge-<?php echo $transaction['status'] === 'completed' ? 'success' : 'warning'; ?>"><?php echo htmlspecialchars(ucfirst($transaction['status'])); ?></span></td>
                                            <td>$<?php echo number_format((float)$transaction['total_amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars(date('M d, Y', strtotime($transaction['created_at']))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        const labels = <?php echo json_encode($chartLabels); ?>;
        const data = <?php echo json_encode($chartData); ?>;

        const ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Daily sales',
                    data,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.16)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#4f46e5',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: value => '$' + value.toFixed(0)
                        }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: context => '$' + context.parsed.y.toFixed(2)
                        }
                    }
                }
            }
        });
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>

