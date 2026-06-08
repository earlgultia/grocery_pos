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
        $where[] = "expiration_date IS NOT NULL AND expiration_date <> '0000-00-00' AND expiration_date < CURDATE()";
    } elseif ($statusFilter === 'in_stock') {
        $where[] = "quantity > low_stock_threshold AND (expiration_date IS NULL OR expiration_date = '0000-00-00' OR expiration_date >= CURDATE())";
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

$stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE store_id = ? AND expiration_date IS NOT NULL AND expiration_date >= CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
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
    
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ“… <?php echo date('F j, Y'); ?> Â· Inventory</div>
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
                <a href="inventory.php" class="active">
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
                                        <td colspan="7" class="empty-state-cell">No products found.</td>
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
                        <div class="pagination">
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

