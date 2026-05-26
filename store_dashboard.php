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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Store Dashboard - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ============================================
           GLOBAL VARIABLES & RESET
        ============================================ */
        :root {
            --dash-bg: #eef2ff;
            --dash-bg-soft: #f8fafc;
            --dash-surface: rgba(255, 255, 255, 0.92);
            --dash-border: rgba(15, 23, 42, 0.10);
            --dash-text: #0f172a;
            --dash-muted: #475569;
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --primary-gradient: linear-gradient(135deg, #4f46e5, #4338ca);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: radial-gradient(circle at top left, rgba(79, 70, 229, 0.12), transparent 30%),
                        radial-gradient(circle at top right, rgba(16, 185, 129, 0.10), transparent 26%),
                        linear-gradient(180deg, var(--dash-bg) 0%, var(--dash-bg-soft) 45%, #eef2ff 100%);
            color: var(--dash-text);
            min-height: 100vh;
        }

        /* ============================================
           DESKTOP LAYOUT (default - screen > 900px)
        ============================================ */
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            position: relative;
        }

        /* Sidebar - Desktop */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0f172a 0%, #111827 42%, #1e293b 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 16px 0 40px rgba(15, 23, 42, 0.20);
            z-index: 1000;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 1.55rem 1.4rem 1.25rem;
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
        }

        .sidebar-menu {
            padding: 0.85rem 0.75rem 1rem;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 0.95rem;
            margin-bottom: 0.25rem;
            border-radius: 1rem;
            color: rgba(255,255,255,0.92);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .sidebar-menu a:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.08);
            transform: translateX(3px);
        }
        .sidebar-menu a.active {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.28), rgba(34, 197, 94, 0.16));
            border-color: rgba(129, 140, 248, 0.24);
        }
        .sidebar-menu a span {
            width: 1.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Main Content - Desktop */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 1.75rem;
        }

        /* Stats Grid */
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
            transition: transform 0.2s, box-shadow 0.2s;
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
        .stat-card small {
            color: var(--dash-muted);
            font-size: 0.75rem;
        }

        /* Chart Container */
        .chart-container {
            background: rgba(255,255,255,0.84);
            backdrop-filter: blur(14px);
            padding: 1.35rem;
            border-radius: 1.15rem;
            margin-bottom: 1.25rem;
            border: 1px solid var(--dash-border);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        /* Recent Sections */
        .recent-section {
            background: rgba(255,255,255,0.86);
            backdrop-filter: blur(14px);
            padding: 1.35rem;
            border-radius: 1.15rem;
            border: 1px solid var(--dash-border);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .section-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--dash-text);
        }

        /* Dashboard Panels (2 columns on desktop) */
        .dashboard-panels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        /* Transaction Table */
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

        /* Badges */
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

        /* Product List */
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
        .product-list small {
            color: var(--dash-muted);
        }

        /* Desktop Hero (only visible on desktop) */
        .desktop-hero {
            background: linear-gradient(135deg, rgba(255,255,255,0.84), rgba(255,255,255,0.68));
            backdrop-filter: blur(12px);
            border-radius: 1.2rem;
            padding: 1.1rem 1.3rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--dash-border);
        }
        .desktop-hero h2 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .desktop-hero p {
            color: var(--dash-muted);
        }

        /* Chart canvas */
        .chart-container canvas {
            width: 100% !important;
            max-height: 280px;
        }

        /* Links */
        a {
            text-decoration: none;
        }
        .recent-section a {
            font-weight: 700;
            color: var(--primary);
        }
        .recent-section a:hover {
            color: var(--primary-dark) !important;
        }

        /* Sidebar backdrop (desktop hidden) */
        .sidebar-backdrop {
            display: none;
        }

        /* Mobile elements hidden on desktop */
        .mobile-top-bar, .mobile-hero {
            display: none;
        }

        /* ============================================
           MOBILE LAYOUT (screen <= 900px)
           Completely separate from desktop
        ============================================ */
        @media (max-width: 900px) {
            /* Hide desktop elements */
            .desktop-hero {
                display: none;
            }

            /* Show mobile elements */
            .mobile-top-bar, .mobile-hero {
                display: block;
            }

            /* Override container */
            .dashboard-container {
                min-height: auto;
            }

            /* Sidebar - Mobile version (slide from left) */
            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 85%;
                max-width: 320px;
                height: 100vh;
                background: #ffffff;
                color: var(--dash-text);
                transition: left 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
                z-index: 1100;
                box-shadow: 0 0 30px rgba(0, 0, 0, 0.25);
                overflow-y: auto;
                border-right: 1px solid var(--dash-border);
                border-radius: 0;
            }
            .sidebar.open {
                left: 0;
            }

            /* Sidebar mobile styling */
            .sidebar .sidebar-header h3,
            .sidebar .sidebar-header p {
                color: var(--dash-text);
            }
            .sidebar .sidebar-status {
                background: rgba(79, 70, 229, 0.08);
                color: #4338ca;
                border-color: rgba(79, 70, 229, 0.12);
            }
            .sidebar-menu a {
                color: var(--dash-text);
                background: #f8fafc;
                border: 1px solid rgba(15, 23, 42, 0.08);
                margin-bottom: 0.5rem;
            }
            .sidebar-menu a.active {
                background: var(--primary-gradient);
                color: white;
                border-color: transparent;
                box-shadow: 0 8px 18px rgba(79, 70, 229, 0.25);
            }

            /* Backdrop */
            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.4);
                backdrop-filter: blur(6px);
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.25s ease;
                z-index: 1050;
            }
            .sidebar-backdrop.active {
                opacity: 1;
                pointer-events: auto;
            }

            /* Main content - no margin */
            .main-content {
                margin-left: 0 !important;
                padding: 0.85rem !important;
                width: 100%;
            }

            /* Mobile Top Bar */
            .mobile-top-bar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.75rem;
                background: rgba(255, 255, 255, 0.94);
                backdrop-filter: blur(18px);
                border: 1px solid var(--dash-border);
                border-radius: 1.2rem;
                padding: 0.85rem 1rem;
                margin-bottom: 1.2rem;
                box-shadow: 0 12px 24px rgba(0, 0, 0, 0.05);
            }
            .menu-toggle-btn {
                background: white;
                border: 1px solid rgba(15, 23, 42, 0.12);
                border-radius: 0.9rem;
                width: 2.8rem;
                height: 2.8rem;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                flex-shrink: 0;
            }
            .menu-icon {
                display: flex;
                flex-direction: column;
                gap: 5px;
                width: 20px;
            }
            .menu-icon span {
                height: 2.5px;
                background: #0f172a;
                border-radius: 10px;
                width: 100%;
            }
            .top-bar-info {
                flex: 1;
                min-width: 0;
            }
            .top-bar-info p {
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--dash-muted);
                margin-bottom: 0.2rem;
            }
            .top-bar-info h2 {
                font-size: 1rem;
                font-weight: 700;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                font-family: 'Space Grotesk', sans-serif;
            }
            .store-badge-mobile {
                background: rgba(79, 70, 229, 0.1);
                padding: 0.4rem 0.8rem;
                border-radius: 40px;
                font-size: 0.7rem;
                font-weight: 600;
                color: #4f46e5;
                white-space: nowrap;
            }

            /* Mobile Hero Section */
            .mobile-hero {
                display: flex;
                flex-direction: column;
                gap: 0.9rem;
                background: linear-gradient(135deg, rgba(255,255,255,0.96), rgba(255,255,255,0.88));
                backdrop-filter: blur(12px);
                border-radius: 1.2rem;
                border: 1px solid var(--dash-border);
                padding: 1.2rem;
                margin-bottom: 1.25rem;
            }
            .hero-badge {
                background: rgba(79, 70, 229, 0.08);
                padding: 0.3rem 0.8rem;
                border-radius: 60px;
                font-size: 0.7rem;
                font-weight: 700;
                width: fit-content;
                color: #4338ca;
            }
            .hero-title {
                font-size: 1.4rem;
                font-weight: 800;
                letter-spacing: -0.03em;
                margin: 0.25rem 0 0.15rem;
            }
            .hero-sub {
                font-size: 0.85rem;
                color: var(--dash-muted);
                margin-bottom: 0.5rem;
            }
            .action-buttons-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.7rem;
                margin-top: 0.2rem;
            }
            .mobile-action {
                text-align: center;
                padding: 0.75rem;
                background: rgba(255,255,255,0.9);
                border-radius: 1rem;
                border: 1px solid var(--dash-border);
                font-weight: 700;
                text-decoration: none;
                color: var(--dash-text);
                font-size: 0.85rem;
            }
            .mobile-action-primary {
                background: var(--primary-gradient);
                color: white;
                border: none;
                grid-column: span 2;
            }

            /* Stats Grid - Mobile (2 columns) */
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
                margin-bottom: 1rem;
            }
            .stats-grid .stat-card:last-child {
                grid-column: 1 / -1;
            }
            .stat-card {
                padding: 0.9rem;
            }
            .stat-title {
                font-size: 0.7rem;
                margin-bottom: 0.3rem;
            }
            .stat-value {
                font-size: 1.4rem;
            }
            .stat-icon {
                font-size: 1.5rem;
                top: 0.7rem;
                right: 0.7rem;
            }

            /* Panels - Mobile (stacked) */
            .dashboard-panels {
                grid-template-columns: 1fr;
                gap: 0.85rem;
            }

            /* Chart & Recent Sections */
            .chart-container, .recent-section {
                padding: 1rem;
                border-radius: 1rem;
                margin-bottom: 0.85rem;
            }

            /* Transaction Table - Scrollable */
            .transaction-table {
                overflow-x: auto;
            }
            .transaction-table th, .transaction-table td {
                padding: 0.62rem 0.5rem;
                font-size: 0.8rem;
            }

            /* Product List - Column direction */
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
        <!-- Sidebar (works for both desktop and mobile) -->
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
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Mobile Top Bar (visible only on mobile) -->
            <div class="mobile-top-bar">
                <div class="menu-toggle-btn" id="mobileMenuToggle">
                    <div class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </div>
                <div class="top-bar-info">
                    <p>Store Dashboard</p>
                    <h2><?php echo htmlspecialchars($store['store_name']); ?></h2>
                </div>
                <div class="store-badge-mobile">
                    <?php echo date('M d'); ?>
                </div>
            </div>

            <!-- Mobile Hero Section (visible only on mobile) -->
            <div class="mobile-hero">
                <div class="hero-badge">📱 Mobile Ready</div>
                <div class="hero-title">Welcome back,<br><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="hero-sub">Sales, stock & alerts at a glance</div>
                <div class="action-buttons-grid">
                    <a href="pos.php" class="mobile-action mobile-action-primary">💵 Open POS</a>
                    <a href="products_management.php" class="mobile-action">📦 Products</a>
                    <a href="sales_report.php" class="mobile-action">📈 Reports</a>
                </div>
            </div>

            <!-- Desktop Hero Section (visible only on desktop) -->
            <div class="desktop-hero">
                <h2>👋 Hello, <?php echo htmlspecialchars($user['name']); ?></h2>
                <p>Manage <?php echo htmlspecialchars($store['store_name']); ?> — track sales, inventory, and low stock alerts.</p>
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
                    <div class="stat-value" style="color: #f59e0b;"><?php echo $expiring; ?></div>
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
                <div class="section-title">📈 Last 7 Days Sales</div>
                <canvas id="salesChart" height="100"></canvas>
            </div>

            <!-- Dashboard Panels (2 columns desktop, 1 column mobile) -->
            <div class="dashboard-panels">
                <!-- Recent Transactions -->
                <div class="recent-section">
                    <div class="section-title">🕒 Recent Transactions</div>
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
                                    <td><span class="badge badge-success"><?php echo htmlspecialchars($transaction['status']); ?></span></td>
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
                                <span class="product-stock">Stock: <?php echo $product['quantity']; ?> <?php echo htmlspecialchars($product['unit']); ?></span>
                                <a href="products_management.php?edit=<?php echo $product['id']; ?>">Restock →</a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($low_stock_products)): ?>
                        <li style="text-align: center; color: #10b981;">✓ All products have sufficient stock</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sales Chart
        const ctx = document.getElementById('salesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Daily Sales ($)',
                    data: <?php echo json_encode($chart_data); ?>,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#4f46e5',
                    pointBorderColor: '#fff',
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                }
            }
        });

        // Mobile Menu Toggle (pure JS, no conflict with app-nav.js)
        const sidebar = document.getElementById('dashboardSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuToggle = document.getElementById('mobileMenuToggle');

        function closeSidebar() {
            if (sidebar && backdrop) {
                sidebar.classList.remove('open');
                backdrop.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function openSidebar() {
            if (sidebar && backdrop) {
                sidebar.classList.add('open');
                backdrop.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        if (menuToggle) {
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function() {
                closeSidebar();
            });
        }

        // Close sidebar when clicking on a menu link (mobile only)
        if (sidebar) {
            sidebar.querySelectorAll('.sidebar-menu a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 900) {
                        closeSidebar();
                    }
                });
            });
        }

        // Close sidebar on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        // Handle window resize - close sidebar if desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 900) {
                closeSidebar();
            }
        });
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>