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
    <style>
        :root {
            --app-text: #0f172a;
            --app-muted: #475569;
            --app-surface: rgba(255, 255, 255, 0.86);
            --app-border: rgba(15, 23, 42, 0.10);
            --app-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(79, 70, 229, 0.12), transparent 30%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.10), transparent 26%),
                linear-gradient(180deg, #eef2ff 0%, #f8fafc 45%, #eef2ff 100%);
            color: var(--app-text);
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,0.45) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.45) 1px, transparent 1px);
            background-size: 38px 38px;
            mask-image: linear-gradient(180deg, rgba(0,0,0,0.14), transparent 85%);
            pointer-events: none;
            z-index: -1;
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
            min-width: 0;
        }

        .page-shell {
            display: grid;
            gap: 1.25rem;
        }

        .panel,
        .message-card {
            background: var(--app-surface);
            backdrop-filter: blur(14px);
            border: 1px solid var(--app-border);
            box-shadow: var(--app-shadow);
            border-radius: 1.2rem;
        }

        .panel {
            padding: 1.2rem;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .panel-header h2,
        .section-title {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            color: var(--app-text);
            letter-spacing: -0.03em;
        }

        .panel-header p,
        .section-subtitle {
            margin: 0.35rem 0 0;
            color: var(--app-muted);
            line-height: 1.55;
        }

        .hero-actions,
        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }

        .search-bar input,
        .search-bar select {
            min-width: 190px;
            padding: 0.85rem 1rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .stat-card {
            padding: 1.1rem;
        }

        .stat-card .label {
            display: block;
            color: var(--app-muted);
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
        }

        .stat-card .value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--app-text);
        }

        .chart-card {
            padding: 1rem;
        }

        .chart-card canvas {
            width: 100% !important;
            height: auto !important;
        }

        .report-table {
            width: 100%;
            overflow-x: auto;
            margin-top: 1rem;
        }

        .report-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th,
        .report-table td {
            padding: 0.95rem 0.8rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .report-table th {
            background: rgba(79, 70, 229, 0.06);
            color: var(--app-text);
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-success { background: rgba(34, 197, 94, 0.12); color: #166534; }
        .badge-warning { background: rgba(245, 158, 11, 0.12); color: #92400e; }
        .badge-danger { background: rgba(239, 68, 68, 0.12); color: #991b1b; }

        .page-meta {
            color: var(--app-muted);
            font-size: 0.95rem;
        }

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                display: block;
            }

            .sidebar {
                width: 100% !important;
                position: static !important;
                height: auto !important;
                max-height: none !important;
                overflow: visible !important;
                box-shadow: none;
            }

            .sidebar-header {
                padding: 1rem;
            }

            .sidebar-menu {
                padding: 0.75rem;
            }

            .sidebar-menu a {
                margin-bottom: 0.45rem;
            }

            .main-content {
                margin-left: 0 !important;
                padding: 1rem;
                width: 100%;
                box-sizing: border-box;
            }

            .stats-grid,
            .hero-actions,
            .search-bar {
                grid-template-columns: 1fr;
                flex-direction: column;
                align-items: stretch;
            }

            .search-bar input,
            .search-bar select,
            .search-bar .btn,
            .hero-actions .btn {
                width: 100%;
                min-width: 0;
            }

            .panel {
                padding: 1rem;
            }

            .report-table table {
                min-width: 700px;
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
                <div class="sidebar-status">📅 <?php echo date('F j, Y'); ?> · Sales</div>
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
                <a href="sales_report.php" class="active">
                    <span>📈</span> Sales Report
                </a>
                <a href="profile.php">
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
                            <div class="stat-card" style="grid-column: 1 / -1; text-align:center; color:#64748b;">No payment activity for the selected filters.</div>
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
                                        <td colspan="6" style="text-align:center; color:#64748b; padding:1.5rem;">No transactions found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transaction['invoice_number'] ?? '—'); ?></td>
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
