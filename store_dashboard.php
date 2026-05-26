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

        .sidebar-backdrop {
            display: none;
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

        .dashboard-hero {
            display: none;
        }

        .dashboard-panels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
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
            .dashboard-container {
                min-height: auto;
            }

            .sidebar {
                top: 4.9rem;
                left: 0.85rem;
                right: 0.85rem;
                width: auto;
                height: auto;
                max-height: calc(100vh - 6.5rem);
                transform: translateY(-0.4rem) scale(0.98);
                transition: max-height 0.28s ease, opacity 0.2s ease, transform 0.2s ease;
                z-index: 1002;
                border-radius: 1.25rem;
                background: rgba(255, 255, 255, 0.98);
                color: var(--dash-text);
                border: 1px solid var(--dash-border);
                box-shadow: 0 24px 60px rgba(15, 23, 42, 0.18);
                opacity: 0;
                pointer-events: none;
                overflow-y: auto;
            }
            
            .sidebar.open {
                transform: translateY(0) scale(1);
                opacity: 1;
                pointer-events: auto;
            }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(15, 23, 42, 0.28);
                backdrop-filter: blur(8px);
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.28s ease;
                z-index: 1001;
            }

            .sidebar.open ~ .sidebar-backdrop {
                opacity: 1;
                pointer-events: auto;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0.85rem;
            }

            .top-bar {
                display: flex;
                position: sticky;
                top: 0.75rem;
                z-index: 1000;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                padding: 0.95rem 1rem;
                margin-bottom: 1rem;
                border: 1px solid var(--dash-border);
                border-radius: 1.05rem;
                background: rgba(255, 255, 255, 0.9);
                box-shadow: 0 18px 40px rgba(15, 23, 42, 0.10);
                backdrop-filter: blur(16px);
            }

            .dashboard-menu-btn {
                width: 2.75rem;
                height: 2.75rem;
                border: 1px solid rgba(15, 23, 42, 0.10);
                border-radius: 0.9rem;
                background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
                display: inline-grid;
                place-items: center;
                cursor: pointer;
                flex: 0 0 auto;
            }

            .dashboard-menu-lines {
                display: inline-grid;
                gap: 0.2rem;
                width: 1rem;
            }

            .dashboard-menu-lines span {
                display: block;
                height: 2px;
                border-radius: 999px;
                background: #0f172a;
            }

            .top-bar-copy {
                min-width: 0;
                flex: 1 1 auto;
            }

            .top-bar-copy p {
                margin: 0;
                font-size: 0.72rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--dash-muted);
            }

            .top-bar-copy h2 {
                margin: 0.15rem 0 0;
                font-family: 'Space Grotesk', sans-serif;
                font-size: 1.05rem;
                letter-spacing: -0.03em;
                color: var(--dash-text);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .top-bar span {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                padding: 0.42rem 0.72rem;
                border-radius: 999px;
                background: rgba(79, 70, 229, 0.08);
                color: #4338ca;
                font-size: 0.76rem;
                font-weight: 700;
                white-space: nowrap;
            }

            .sidebar-header {
                padding: 1.15rem 1rem 1rem;
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            }

            .sidebar-header h3 {
                color: var(--dash-text);
            }

            .sidebar-header p {
                color: var(--dash-muted);
            }

            .sidebar-status {
                background: rgba(79, 70, 229, 0.08);
                color: #4338ca;
                border-color: rgba(79, 70, 229, 0.12);
            }

            .sidebar-menu {
                padding: 0.85rem;
            }

            .sidebar-menu a {
                color: var(--dash-text);
                background: rgba(248, 250, 252, 0.95);
                border: 1px solid rgba(15, 23, 42, 0.08);
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
                margin-bottom: 0.5rem;
            }

            .sidebar-menu a:hover {
                background: rgba(79, 70, 229, 0.05);
                border-color: rgba(79, 70, 229, 0.12);
                transform: none;
            }

            .sidebar-menu a.active {
                background: linear-gradient(135deg, #4f46e5, #4338ca);
                border-color: transparent;
                color: #fff;
                box-shadow: 0 14px 26px rgba(79, 70, 229, 0.22);
            }

            .sidebar-menu a span,
            .sidebar-menu a.active span,
            .sidebar-menu a:hover span {
                color: inherit;
            }

            .dashboard-hero {
                display: grid;
                gap: 1rem;
                margin-bottom: 1rem;
                padding: 1rem;
                border-radius: 1.2rem;
                background:
                    radial-gradient(circle at top right, rgba(34, 197, 94, 0.12), transparent 28%),
                    linear-gradient(135deg, rgba(255,255,255,0.96), rgba(255,255,255,0.88));
                border: 1px solid var(--dash-border);
                box-shadow: 0 16px 34px rgba(15, 23, 42, 0.08);
                backdrop-filter: blur(14px);
            }

            .dashboard-hero-badge {
                display: inline-flex;
                width: fit-content;
                align-items: center;
                padding: 0.4rem 0.7rem;
                border-radius: 999px;
                background: rgba(79, 70, 229, 0.08);
                color: #4338ca;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }

            .dashboard-hero-copy h1 {
                margin: 0.75rem 0 0.4rem;
                font-family: 'Space Grotesk', sans-serif;
                font-size: 1.4rem;
                line-height: 1.15;
                letter-spacing: -0.04em;
                color: var(--dash-text);
            }

            .dashboard-hero-copy p {
                margin: 0;
                color: var(--dash-muted);
                font-size: 0.94rem;
            }

            .dashboard-hero-actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.65rem;
            }

            .dashboard-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 2.85rem;
                padding: 0.75rem 0.95rem;
                border-radius: 0.95rem;
                border: 1px solid var(--dash-border);
                background: rgba(255,255,255,0.92);
                color: var(--dash-text);
                text-decoration: none;
                font-size: 0.9rem;
                font-weight: 700;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
            }

            .dashboard-action-primary {
                grid-column: 1 / -1;
                border-color: transparent;
                background: linear-gradient(135deg, #4f46e5, #4338ca);
                color: #fff;
                box-shadow: 0 14px 26px rgba(79, 70, 229, 0.22);
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.75rem;
                margin-bottom: 1rem;
            }

            .stats-grid .stat-card:last-child {
                grid-column: 1 / -1;
            }

            .stat-card {
                padding: 1rem;
                border-radius: 1rem;
            }

            .stat-title {
                font-size: 0.72rem;
                letter-spacing: 0.07em;
                margin-bottom: 0.35rem;
            }

            .stat-value {
                font-size: 1.5rem;
            }

            .stat-icon {
                top: 0.85rem;
                right: 0.85rem;
                font-size: 1.45rem;
            }

            .chart-container,
            .recent-section {
                padding: 1rem;
                border-radius: 1rem;
                margin-bottom: 0.85rem;
            }

            .dashboard-panels {
                grid-template-columns: 1fr;
                gap: 0.85rem;
            }

            .transaction-table {
                overflow-x: auto;
            }

            .transaction-table th,
            .transaction-table td {
                padding: 0.62rem 0.5rem;
                font-size: 0.86rem;
            }

            .product-list li {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .product-stock {
                display: block;
                margin-bottom: 0.25rem;
            }

            .recent-section a {
                display: inline-flex;
                align-items: center;
                margin-left: 0 !important;
                margin-top: 0.15rem;
            }

            .section-title {
                font-size: 1rem;
                margin-bottom: 0.85rem;
            }
        }
    </style>
</head>
<body class="store-dashboard-page">
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar" id="dashboardSidebar">
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
        <div class="sidebar-backdrop" aria-hidden="true"></div>

        <!-- Main Content -->
        <div class="main-content">
            <div class="dashboard-hero">
                <div class="dashboard-hero-copy">
                    <div class="dashboard-hero-badge">Mobile-ready overview</div>
                    <h1>Welcome back, <?php echo htmlspecialchars($user['name']); ?></h1>
                    <p>Keep sales, stock, and restock alerts close at hand with quick actions below.</p>
                </div>
                <div class="dashboard-hero-actions">
                    <a href="pos.php" class="dashboard-action dashboard-action-primary">Open POS</a>
                    <a href="products_management.php" class="dashboard-action">Products</a>
                    <a href="sales_report.php" class="dashboard-action">Reports</a>
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

            <div class="dashboard-panels">
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
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>
