<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'store_user') {
    header('Location: login.php');
    exit();
}

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || empty($user['store_id'])) {
        header('Location: login.php');
        exit();
    }

    $stmt = $db->prepare("SELECT * FROM stores WHERE id = ?");
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

// Get dashboard statistics
$db = getDB();

// Today's sales
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as today_sales, COUNT(*) as today_transactions 
    FROM transactions 
    WHERE store_id = ? AND DATE(created_at) = CURDATE() AND status = 'completed'
");
$stmt->execute([$store['id']]);
$today_stats = $stmt->fetch();

// Total products
$stmt = $db->prepare("SELECT COUNT(*) as total_products FROM products WHERE store_id = ?");
$stmt->execute([$store['id']]);
$total_products = $stmt->fetch()['total_products'];

// Low stock products
$stmt = $db->prepare("
    SELECT COUNT(*) as low_stock 
    FROM products 
    WHERE store_id = ? AND quantity <= low_stock_threshold
");
$stmt->execute([$store['id']]);
$low_stock = $stmt->fetch()['low_stock'];

// Expiring products
$stmt = $db->prepare("
    SELECT COUNT(*) as expiring 
    FROM products 
    WHERE store_id = ? AND expiration_date IS NOT NULL 
    AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
");
$stmt->execute([$store['id']]);
$expiring = $stmt->fetch()['expiring'];

// Monthly sales
$stmt = $db->prepare("
    SELECT COALESCE(SUM(total_amount), 0) as monthly_sales 
    FROM transactions 
    WHERE store_id = ? AND MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE()) AND status = 'completed'
");
$stmt->execute([$store['id']]);
$monthly_sales = $stmt->fetch()['monthly_sales'];

// Recent transactions
$stmt = $db->prepare("
    SELECT * FROM transactions 
    WHERE store_id = ? 
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([$store['id']]);
$recent_transactions = $stmt->fetchAll();

// Low stock products list
$stmt = $db->prepare("
    SELECT * FROM products 
    WHERE store_id = ? AND quantity <= low_stock_threshold 
    ORDER BY quantity ASC LIMIT 5
");
$stmt->execute([$store['id']]);
$low_stock_products = $stmt->fetchAll();

// Chart data for last 7 days
$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('M d', strtotime($date));
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as daily_sales 
        FROM transactions 
        WHERE store_id = ? AND DATE(created_at) = ? AND status = 'completed'
    ");
    $stmt->execute([$store['id'], $date]);
    $daily = $stmt->fetch();
    $chart_data[] = $daily['daily_sales'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Dashboard - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --dash-bg: #eef2ff;
            --dash-bg-soft: #f8fafc;
            --dash-surface: rgba(255, 255, 255, 0.84);
            --dash-border: rgba(15, 23, 42, 0.10);
            --dash-text: #0f172a;
            --dash-muted: #475569;
        }

        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(79, 70, 229, 0.12), transparent 30%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.10), transparent 26%),
                linear-gradient(180deg, var(--dash-bg) 0%, var(--dash-bg-soft) 45%, #eef2ff 100%);
            color: var(--dash-text);
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0f172a 0%, #111827 42%, #1e293b 100%);
            color: white;
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
        
        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.08);
            transform: translateX(3px);
        }
        
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
        
        .top-bar {
            display: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }
        
        .stat-card {
            position: relative;
            background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(255,255,255,0.90));
            padding: 1.4rem;
            border-radius: 1.15rem;
            border: 1px solid var(--dash-border);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.10);
        }
        
        .stat-title {
            color: var(--dash-muted);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--dash-text);
            letter-spacing: -0.03em;
        }
        
        .stat-icon {
            position: absolute;
            top: 1rem;
            right: 1rem;
            font-size: 2rem;
            opacity: 0.18;
        }
        
        .chart-container {
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(14px);
            padding: 1.35rem;
            border-radius: 1.15rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid var(--dash-border);
        }
        
        .recent-section {
            background: rgba(255,255,255,0.84);
            backdrop-filter: blur(14px);
            padding: 1.35rem;
            border-radius: 1.15rem;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border: 1px solid var(--dash-border);
        }
        
        .section-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dash-text);
        }
        
        .transaction-table {
            width: 100%;
            overflow-x: auto;
        }
        
        .transaction-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transaction-table th,
        .transaction-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .transaction-table th {
            background: rgba(79, 70, 229, 0.06);
            font-weight: 700;
            color: var(--dash-text);
        }
        
        .badge {
            display: inline-block;
            padding: 0.35rem 0.65rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .badge-success {
            background: rgba(34, 197, 94, 0.12);
            color: #166534;
        }
        
        .badge-warning {
            background: rgba(245, 158, 11, 0.12);
            color: #92400e;
        }
        
        .badge-danger {
            background: rgba(239, 68, 68, 0.12);
            color: #991b1b;
        }
        
        .product-list {
            list-style: none;
            padding: 0;
        }
        
        .product-list li {
            padding: 0.85rem 0;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .product-name {
            font-weight: 700;
            color: var(--dash-text);
        }
        
        .product-stock {
            font-size: 0.875rem;
            color: #b91c1c;
        }

        .product-list small,
        .recent-section small,
        .top-bar span {
            color: var(--dash-muted);
        }

        .sidebar-menu a span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.6rem;
        }

        .sidebar-menu a.active span,
        .sidebar-menu a:hover span {
            color: #fff;
        }

        .transaction-table tbody tr:hover {
            background: rgba(79, 70, 229, 0.03);
        }

        .chart-container canvas {
            width: 100% !important;
        }

        .stats-grid small,
        .stat-card small {
            color: var(--dash-muted);
        }

        .recent-section a {
            font-weight: 700;
        }

        .recent-section a:hover {
            color: #4338ca !important;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s;
                z-index: 1000;
            }
            
            .sidebar.open {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .chart-container,
            .recent-section {
                padding: 1rem;
            }

            .top-bar {
                padding: 0.9rem 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .transaction-table {
                overflow-x: auto;
            }

            .product-list li {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>🛒 <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">📅 <?php echo date('F j, Y'); ?> · Dashboard</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php" class="active">
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
                <a href="profile.php">
                    <span>👤</span> Profile
                </a>
                <a href="logout.php">
                    <span>🚪</span> Logout
                </a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="top-bar">
                <h2>Dashboard</h2>
                <div>
                    <span><?php echo date('F j, Y'); ?></span>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">💰</div>
                    <div class="stat-title">Today's Sales</div>
                    <div class="stat-value">$<?php echo number_format($today_stats['today_sales'], 2); ?></div>
                    <small><?php echo $today_stats['today_transactions']; ?> transactions</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-title">Total Products</div>
                    <div class="stat-value"><?php echo number_format($total_products); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-title">Low Stock Items</div>
                    <div class="stat-value" style="color: #dc3545;"><?php echo $low_stock; ?></div>
                    <small>Need restocking</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📅</div>
                    <div class="stat-title">Expiring Soon</div>
                    <div class="stat-value" style="color: #ffc107;"><?php echo $expiring; ?></div>
                    <small>Within 7 days</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-title">Monthly Sales</div>
                    <div class="stat-value">$<?php echo number_format($monthly_sales, 2); ?></div>
                </div>
            </div>

            <!-- Chart -->
            <div class="chart-container">
                <div class="section-title">Last 7 Days Sales</div>
                <canvas id="salesChart" height="100"></canvas>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <!-- Recent Transactions -->
                <div class="recent-section">
                    <div class="section-title">Recent Transactions</div>
                    <div class="transaction-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($transaction['invoice_number']); ?></td>
                                    <td><?php echo htmlspecialchars($transaction['customer_name']); ?></td>
                                    <td>$<?php echo number_format($transaction['total_amount'], 2); ?></td>
                                    <td><span class="badge badge-success"><?php echo $transaction['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recent_transactions)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center;">No transactions yet</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Low Stock Alerts -->
                <div class="recent-section">
                    <div class="section-title">⚠️ Low Stock Alerts</div>
                    <ul class="product-list">
                        <?php foreach ($low_stock_products as $product): ?>
                        <li>
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <small><?php echo htmlspecialchars($product['category']); ?></small>
                            </div>
                            <div>
                                <span class="product-stock">Stock: <?php echo $product['quantity']; ?> <?php echo $product['unit']; ?></span>
                                <a href="products_management.php?edit=<?php echo $product['id']; ?>" style="margin-left: 1rem; color: #007bff;">Restock</a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($low_stock_products)): ?>
                        <li style="text-align: center; color: #28a745;">✓ All products have sufficient stock</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Sales Chart
        const ctx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Daily Sales ($)',
                    data: <?php echo json_encode($chart_data); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });

        // Mobile menu toggle
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
        }
    </script>
</body>
</html>