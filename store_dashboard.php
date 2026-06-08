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
    
</head>
<body class="store-dashboard-page">
    <div class="dashboard-container">
        <!-- Sidebar (works for both desktop and mobile) -->
        <div class="sidebar" id="dashboardSidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ“… <?php echo date('F j, Y'); ?> Â· Dashboard</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php" class="active">
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
                <a href="profile.php">
                    <span>ðŸ‘¤</span> Profile
                </a>
                <a href="logout.php">
                    <span>ðŸšª</span> Logout
                </a>
            </div>
        </div>
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Mobile Top Bar (visible only on mobile) -->
            <div class="mobile-top-bar">
                <button type="button" class="menu-toggle-btn" id="mobileMenuToggle" aria-label="Open sidebar menu" aria-controls="dashboardSidebar" aria-expanded="false">
                    <div class="menu-icon">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>
                </button>
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
                <div class="hero-badge">ðŸ“± Mobile Ready</div>
                <div class="hero-title">Welcome back,<br><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="hero-sub">Sales, stock & alerts at a glance</div>
                <div class="action-buttons-grid">
                    <a href="pos.php" class="mobile-action mobile-action-primary">ðŸ’µ Open POS</a>
                    <a href="products_management.php" class="mobile-action">ðŸ“¦ Products</a>
                    <a href="sales_report.php" class="mobile-action">ðŸ“ˆ Reports</a>
                </div>
            </div>

            <!-- Desktop Hero Section (visible only on desktop) -->
            <div class="desktop-hero">
                <h2>ðŸ‘‹ Hello, <?php echo htmlspecialchars($user['name']); ?></h2>
                <p>Manage <?php echo htmlspecialchars($store['store_name']); ?> â€” track sales, inventory, and low stock alerts.</p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">ðŸ’°</div>
                    <div class="stat-title">Today's Sales</div>
                    <div class="stat-value">$<?php echo number_format($today_stats['today_sales'], 2); ?></div>
                    <small><?php echo $today_stats['today_transactions']; ?> transactions</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">ðŸ“¦</div>
                    <div class="stat-title">Total Products</div>
                    <div class="stat-value"><?php echo number_format($total_products); ?></div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">âš ï¸</div>
                    <div class="stat-title">Low Stock Items</div>
                    <div class="stat-value danger"><?php echo $low_stock; ?></div>
                    <small>Need restocking</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">ðŸ“…</div>
                    <div class="stat-title">Expiring Soon</div>
                    <div class="stat-value warning"><?php echo $expiring; ?></div>
                    <small>Within 7 days</small>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon">ðŸ“Š</div>
                    <div class="stat-title">Monthly Sales</div>
                    <div class="stat-value">$<?php echo number_format($monthly_sales, 2); ?></div>
                </div>
            </div>

            <!-- Chart -->
            <div class="chart-container">
                <div class="section-title">ðŸ“ˆ Last 7 Days Sales</div>
                <canvas id="salesChart" height="100"></canvas>
            </div>

            <!-- Dashboard Panels (2 columns desktop, 1 column mobile) -->
            <div class="dashboard-panels">
                <!-- Recent Transactions -->
                <div class="recent-section">
                    <div class="section-title">ðŸ•’ Recent Transactions</div>
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
                                    <td colspan="4" class="empty-state-cell">No transactions yet</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Low Stock Alerts -->
                <div class="recent-section">
                    <div class="section-title">âš ï¸ Low Stock Alerts</div>
                    <ul class="product-list">
                        <?php foreach ($low_stock_products as $product): ?>
                        <li>
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <small><?php echo htmlspecialchars($product['category']); ?></small>
                            </div>
                            <div>
                                <span class="product-stock">Stock: <?php echo $product['quantity']; ?> <?php echo htmlspecialchars($product['unit']); ?></span>
                                <a href="products_management.php?edit=<?php echo $product['id']; ?>">Restock â†’</a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                        <?php if (empty($low_stock_products)): ?>
                        <li class="list-empty">âœ“ All products have sufficient stock</li>
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

        // Mobile menu toggle for the store dashboard sidebar.
        const sidebar = document.getElementById('dashboardSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const menuToggle = document.getElementById('mobileMenuToggle');

        function closeSidebar() {
            if (sidebar && backdrop) {
                sidebar.classList.remove('open');
                backdrop.classList.remove('active');
                if (menuToggle) {
                    menuToggle.setAttribute('aria-expanded', 'false');
                    menuToggle.setAttribute('aria-label', 'Open sidebar menu');
                }
                document.body.style.overflow = '';
            }
        }

        function openSidebar() {
            if (sidebar && backdrop) {
                sidebar.classList.add('open');
                backdrop.classList.add('active');
                if (menuToggle) {
                    menuToggle.setAttribute('aria-expanded', 'true');
                    menuToggle.setAttribute('aria-label', 'Close sidebar menu');
                }
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
</body>
</html>

