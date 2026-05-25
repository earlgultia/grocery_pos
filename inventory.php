<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;
$message = '';
$messageType = 'success';
$search = sanitizeInput($_GET['q'] ?? '');
$categoryFilter = sanitizeInput($_GET['category'] ?? '');
$statusFilter = sanitizeInput($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

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

if ($search !== '') {
    $where[] = '(name LIKE ? OR category LIKE ? OR unit LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
}

if ($statusFilter !== '') {
    if ($statusFilter === 'low_stock') {
        $where[] = 'quantity <= low_stock_threshold AND quantity > 0';
    } elseif ($statusFilter === 'out_of_stock') {
        $where[] = 'quantity = 0';
    } elseif ($statusFilter === 'expired') {
        $where[] = 'expiration_date IS NOT NULL AND expiration_date <> "" AND expiration_date < CURDATE()';
    } elseif ($statusFilter === 'in_stock') {
        $where[] = 'quantity > low_stock_threshold AND (expiration_date IS NULL OR expiration_date = "" OR expiration_date >= CURDATE())';
    }
}

$whereClause = implode(' AND ', $where);

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id = ?');
$stmt->execute([$store['id']]);
$totalProducts = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id = ? AND quantity <= low_stock_threshold AND quantity > 0');
$stmt->execute([$store['id']]);
$lowStockCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id = ? AND quantity = 0');
$stmt->execute([$store['id']]);
$outOfStockCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM products WHERE store_id = ? AND expiration_date IS NOT NULL AND expiration_date <> "" AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)');
$stmt->execute([$store['id']]);
$expiringSoonCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare('SELECT COALESCE(SUM(quantity * price), 0) FROM products WHERE store_id = ?');
$stmt->execute([$store['id']]);
$inventoryValue = (float)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE {$whereClause}");
$stmt->execute($params);
$filteredProducts = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredProducts / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$perPage = (int)$perPage;
$offset = (int)$offset;
$productSql = "SELECT * FROM products WHERE {$whereClause} ORDER BY name ASC LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($productSql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $db->prepare('SELECT DISTINCT category FROM products WHERE store_id = ? AND category <> "" ORDER BY category');
$categoryStmt->execute([$store['id']]);
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

function productStatusLabel(array $product): array
{
    if (!empty($product['expiration_date']) && strtotime($product['expiration_date']) < strtotime(date('Y-m-d'))) {
        return ['Expired', 'danger'];
    }

    if ((int)$product['quantity'] === 0) {
        return ['Out of stock', 'danger'];
    }

    if ((int)$product['quantity'] <= (int)$product['low_stock_threshold']) {
        return ['Low stock', 'warning'];
    }

    return ['In stock', 'success'];
}

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
    <title>Inventory - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
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
        .message-card,
        .stat-card {
            background: var(--app-surface);
            backdrop-filter: blur(14px);
            border: 1px solid var(--app-border);
            box-shadow: var(--app-shadow);
            border-radius: 1.2rem;
        }

        .hero-panel {
            display: grid;
            grid-template-columns: 1.4fr 0.9fr;
            gap: 1rem;
            padding: 1.4rem;
        }

        .hero-title {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.7rem, 3vw, 2.45rem);
            letter-spacing: -0.04em;
            color: var(--app-text);
        }

        .hero-subtitle {
            margin: 0.75rem 0 0;
            color: var(--app-muted);
            line-height: 1.65;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1.1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.1rem;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: #fff;
            box-shadow: 0 12px 24px rgba(79, 70, 229, 0.18);
        }

        .btn-outline {
            background: rgba(255,255,255,0.92);
            color: var(--app-text);
            border: 1px solid rgba(15, 23, 42, 0.12);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .stat-card {
            padding: 1.1rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--app-muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 0.35rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--app-text);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .panel-header h2 {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            color: var(--app-text);
            letter-spacing: -0.03em;
        }

        .panel-header p {
            margin: 0.35rem 0 0;
            color: var(--app-muted);
            line-height: 1.55;
        }

        .search-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            margin-bottom: 1rem;
        }

        .search-bar input,
        .search-bar select {
            min-width: 200px;
            padding: 0.85rem 1rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
            color: var(--app-text);
        }

        .inventory-table {
            width: 100%;
            overflow-x: auto;
        }

        .inventory-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 0.95rem 0.8rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .inventory-table th {
            background: rgba(79, 70, 229, 0.06);
            color: var(--app-text);
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .product-name {
            font-weight: 800;
            color: var(--app-text);
        }

        .product-meta {
            color: var(--app-muted);
            font-size: 0.88rem;
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

        @media (max-width: 1100px) {
            .stats-grid,
            .hero-panel {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .search-bar input,
            .search-bar select {
                min-width: 100%;
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
                <div class="sidebar-status">📅 <?php echo date('F j, Y'); ?> · Inventory</div>
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
                <a href="inventory.php" class="active">
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

        <div class="main-content">
            <div class="page-shell">
                <section class="panel hero-panel">
                    <div>
                        <span class="badge badge-success">Inventory overview</span>
                        <h1 class="hero-title">Track stock levels and expiry status</h1>
                        <p class="hero-subtitle">Review your current inventory and identify products that need attention before stockouts or expirations impact your store.</p>
                        <div class="hero-actions">
                            <a href="products_management.php" class="btn btn-primary">Manage products</a>
                            <a href="pos.php" class="btn btn-outline">Open POS</a>
                        </div>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <span class="stat-label">Total products</span>
                            <div class="stat-value"><?php echo number_format($totalProducts); ?></div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label">Low stock</span>
                            <div class="stat-value"><?php echo number_format($lowStockCount); ?></div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label">Out of stock</span>
                            <div class="stat-value"><?php echo number_format($outOfStockCount); ?></div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label">Expiring soon</span>
                            <div class="stat-value"><?php echo number_format($expiringSoonCount); ?></div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-label">Inventory value</span>
                            <div class="stat-value">$<?php echo number_format($inventoryValue, 2); ?></div>
                        </div>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Inventory list</h2>
                            <p>Search your catalog, filter by category or status, and monitor stock health in one place.</p>
                        </div>
                    </div>

                    <form class="search-bar" method="get" action="inventory.php">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search products...">
                        <select name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="">All statuses</option>
                            <option value="in_stock" <?php echo $statusFilter === 'in_stock' ? 'selected' : ''; ?>>In stock</option>
                            <option value="low_stock" <?php echo $statusFilter === 'low_stock' ? 'selected' : ''; ?>>Low stock</option>
                            <option value="out_of_stock" <?php echo $statusFilter === 'out_of_stock' ? 'selected' : ''; ?>>Out of stock</option>
                            <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="inventory.php" class="btn btn-outline">Reset</a>
                    </form>

                    <div class="inventory-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th>Value</th>
                                    <th>Status</th>
                                    <th>Expiry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; color:#64748b; padding: 1.5rem;">No products found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php [$statusLabel, $statusClass] = productStatusLabel($product); ?>
                                        <tr>
                                            <td>
                                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <div class="product-meta">Unit: <?php echo htmlspecialchars($product['unit']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($product['category']); ?></td>
                                            <td>
                                                <div class="product-name"><?php echo (int)$product['quantity']; ?></div>
                                                <div class="product-meta">Low: <?php echo (int)$product['low_stock_threshold']; ?></div>
                                            </td>
                                            <td>$<?php echo number_format((float)$product['price'], 2); ?></td>
                                            <td>$<?php echo number_format((float)$product['price'] * (int)$product['quantity'], 2); ?></td>
                                            <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                            <td><?php echo !empty($product['expiration_date']) ? htmlspecialchars($product['expiration_date']) : 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination" style="margin-top:1rem; display:flex; flex-wrap:wrap; gap:0.5rem;">
                            <?php if ($page > 1): ?>
                                <a href="inventory.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page - 1]); ?>" class="btn btn-outline">Previous</a>
                            <?php endif; ?>

                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <a href="inventory.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $p]); ?>" class="btn <?php echo $p === $page ? 'btn-primary' : 'btn-outline'; ?>"><?php echo $p; ?></a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="inventory.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page + 1]); ?>" class="btn btn-outline">Next</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
    <script src="js/app-nav.js"></script>
</body>
</html>
