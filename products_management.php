<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;
$message = '';
$messageType = 'success';
$editingProduct = null;
$search = sanitizeInput($_REQUEST['q'] ?? '');
$categoryFilter = sanitizeInput($_REQUEST['category'] ?? '');
$statusFilter = sanitizeInput($_REQUEST['status'] ?? '');
$page = max(1, (int)($_REQUEST['page'] ?? 1));
$perPage = 10;

function buildQueryString(array $params): string
{
    return http_build_query(array_filter($params, fn($value) => $value !== '' && $value !== null));
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_message_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'store_user') {
    header('Location: login.php');
    exit();
}

try {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $message = 'Invalid security token.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            if ($action === 'save_product') {
                $productId = (int)($_POST['product_id'] ?? 0);
                $name = sanitizeInput($_POST['name'] ?? '');
                $category = sanitizeInput($_POST['category'] ?? '');
                $price = (float)($_POST['price'] ?? 0);
                $quantity = (int)($_POST['quantity'] ?? 0);
                $unit = sanitizeInput($_POST['unit'] ?? 'pcs');
                $lowStockThreshold = 5;
                $expirationDate = trim($_POST['expiration_date'] ?? '');
                $expirationDate = $expirationDate !== '' ? $expirationDate : null;

                if ($name === '' || $category === '' || $price < 0 || $quantity < 0 || $unit === '') {
                    throw new Exception('Please complete all required fields.');
                }

                if ($productId > 0) {
                    $stmt = $db->prepare('SELECT id FROM products WHERE id = ? AND store_id = ?');
                    $stmt->execute([$productId, $store['id']]);
                    if (!$stmt->fetchColumn()) {
                        throw new Exception('Product not found.');
                    }

                    $stmt = $db->prepare('UPDATE products SET name = ?, category = ?, price = ?, quantity = ?, unit = ?, low_stock_threshold = ?, expiration_date = ? WHERE id = ? AND store_id = ?');
                    $stmt->execute([
                        $name,
                        $category,
                        $price,
                        $quantity,
                        $unit,
                        $lowStockThreshold,
                        $expirationDate,
                        $productId,
                        $store['id']
                    ]);

                    logActivity($_SESSION['user_id'], 'update_product', "Updated product: {$name}");
                    $_SESSION['flash_message'] = 'Product updated successfully.';
                } else {
                    $stmt = $db->prepare('INSERT INTO products (store_id, name, category, price, quantity, unit, low_stock_threshold, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $store['id'],
                        $name,
                        $category,
                        $price,
                        $quantity,
                        $unit,
                        $lowStockThreshold,
                        $expirationDate
                    ]);

                    logActivity($_SESSION['user_id'], 'create_product', "Created product: {$name}");
                    $_SESSION['flash_message'] = 'Product added successfully.';
                }

                $_SESSION['flash_message_type'] = 'success';
                header('Location: products_management.php?' . buildQueryString([
                    'q' => $search,
                    'category' => $categoryFilter,
                    'status' => $statusFilter,
                    'page' => $page,
                ]));
                exit();
            } elseif ($action === 'delete_product') {
                $productId = (int)($_POST['product_id'] ?? 0);
                $stmt = $db->prepare('SELECT name FROM products WHERE id = ? AND store_id = ?');
                $stmt->execute([$productId, $store['id']]);
                $productName = $stmt->fetchColumn();

                if (!$productName) {
                    throw new Exception('Product not found.');
                }

                try {
                    $stmt = $db->prepare('DELETE FROM products WHERE id = ? AND store_id = ?');
                    $stmt->execute([$productId, $store['id']]);
                } catch (PDOException $e) {
                    if (isset($e->errorInfo[1]) && $e->errorInfo[1] === 1451) {
                        throw new Exception('Cannot delete this product because it is referenced by past transactions.');
                    }
                    throw $e;
                }

                logActivity($_SESSION['user_id'], 'delete_product', "Deleted product: {$productName}");
                $_SESSION['flash_message'] = 'Product deleted successfully.';
                $_SESSION['flash_message_type'] = 'success';
                header('Location: products_management.php?' . buildQueryString([
                    'q' => $search,
                    'category' => $categoryFilter,
                    'status' => $statusFilter,
                    'page' => $page,
                ]));
                exit();
            } elseif ($action === 'bulk_delete_products') {
                $productIds = array_filter(array_map('intval', $_POST['selected_products'] ?? []));
                if (empty($productIds)) {
                    throw new Exception('Select at least one product to delete.');
                }

                $placeholders = implode(',', array_fill(0, count($productIds), '?'));
                $stmt = $db->prepare("DELETE FROM products WHERE id IN ({$placeholders}) AND store_id = ?");
                $stmt->execute(array_merge($productIds, [$store['id']]));

                logActivity($_SESSION['user_id'], 'bulk_delete_products', 'Deleted products: ' . implode(', ', $productIds));
                $_SESSION['flash_message'] = 'Selected products were deleted successfully.';
                $_SESSION['flash_message_type'] = 'success';
                header('Location: products_management.php?' . buildQueryString([
                    'q' => $search,
                    'category' => $categoryFilter,
                    'status' => $statusFilter,
                    'page' => $page,
                ]));
                exit();
            }
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (PDOException $e) {
            $message = 'Database error. Please try again.';
            $messageType = 'error';
        }
    }
}

$editingId = isset($_REQUEST['edit']) ? (int)$_REQUEST['edit'] : 0;
if ($editingId === 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_product') {
    $editingId = (int)($_POST['product_id'] ?? 0);
}

if ($editingId > 0) {
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? AND store_id = ?');
    $stmt->execute([$editingId, $store['id']]);
    $editingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$editingProduct) {
        $message = 'Product not found.';
        $messageType = 'error';
    }
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
        $where[] = 'quantity > low_stock_threshold';
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

$defaultCategoryChoices = ['Dairy', 'Produce', 'Bakery', 'Beverages', 'Snacks', 'Frozen', 'Household', 'Personal Care'];
$categoryChoices = array_values(array_unique(array_merge($defaultCategoryChoices, $categories)));

$csrfToken = generateCSRFToken();

function productStatusLabel(array $product): array
{
    if ((int)$product['quantity'] === 0) {
        return ['Out of stock', 'danger'];
    }

    if ((int)$product['quantity'] <= (int)$product['low_stock_threshold']) {
        return ['Low stock', 'warning'];
    }

    return ['In stock', 'success'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ“… <?php echo date('F j, Y'); ?> Â· Products</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>ðŸ“Š</span> Dashboard
                </a>
                <a href="pos.php">
                    <span>ðŸ’°</span> Point of Sale
                </a>
                <a href="products_management.php" class="active">
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
            <div class="sidebar-footer">
                <div class="sidebar-footer-label">Quick Actions</div>
                <div class="sidebar-footer-actions">
                    <a href="pos.php">
                        <span>Open POS</span>
                        <span>â†—</span>
                    </a>
                    <a href="store_dashboard.php">
                        <span>View dashboard</span>
                        <span>â†—</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="main-content">
            <div class="page-shell">
                <section class="page-hero">
                    <div>
                        <div class="hero-kicker">Product management</div>
                        <h1>Manage your store catalog</h1>
                        <p>Add new products, update stock levels, and keep pricing organized from one clean workspace built for the same dashboard style.</p>
                        <div class="hero-actions">
                            <a href="#productForm" class="btn btn-primary">Add Product</a>
                            <a href="pos.php" class="btn btn-outline">Open POS</a>
                        </div>
                    </div>
                    <div class="hero-stats">
                        <div class="hero-stat">
                            <span class="label">Products</span>
                            <span class="value"><?php echo number_format($totalProducts); ?></span>
                        </div>
                        <div class="hero-stat">
                            <span class="label">Low stock</span>
                            <span class="value"><?php echo number_format($lowStockCount); ?></span>
                        </div>
                        <div class="hero-stat">
                            <span class="label">Out of stock</span>
                            <span class="value"><?php echo number_format($outOfStockCount); ?></span>
                        </div>
                        <div class="hero-stat">
                            <span class="label">Inventory value</span>
                            <span class="value">$<?php echo number_format($inventoryValue, 2); ?></span>
                        </div>
                    </div>
                </section>

                <?php if ($message): ?>
                    <div class="message-card <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <div class="layout-grid">
                    <section class="panel" id="productForm">
                        <div class="panel-header">
                            <div>
                                <h2><?php echo $editingProduct ? 'Edit product' : 'Add product'; ?></h2>
                                <p><?php echo $editingProduct ? 'Update the current item details and save changes.' : 'Create a new product record for this store.'; ?></p>
                            </div>
                        </div>

                        <form method="post" action="products_management.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="save_product">
                            <input type="hidden" name="product_id" value="<?php echo $editingProduct ? (int)$editingProduct['id'] : 0; ?>">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                            <input type="hidden" name="page" value="<?php echo $page; ?>">

                            <div class="form-grid">
                                <div class="form-group full">
                                    <label>Product name</label>
                                    <input type="text" name="name" required value="<?php echo htmlspecialchars($editingProduct['name'] ?? ''); ?>" placeholder="Fresh milk">
                                </div>
                                <div class="form-group category-dropdown-wrapper">
                                    <label>Category</label>
                                    <div class="category-field">
                                        <input id="categoryInput" type="text" name="category" required value="<?php echo htmlspecialchars($editingProduct['category'] ?? ''); ?>" placeholder="Dairy">
                                        <button type="button" class="btn btn-outline category-toggle" onclick="toggleCategoryDropdown()">Choose</button>
                                        <div id="categoryModal" class="category-dropdown-panel">
                                            <div class="modal-header">
                                                <h3>Choose category</h3>
                                                <button type="button" class="modal-close" onclick="closeCategoryDropdown()">×</button>
                                            </div>
                                            <p class="form-note">Select a category or type a custom one.</p>
                                            <div class="category-list">
                                                <?php foreach ($categoryChoices as $choice): ?>
                                                    <button type="button" class="category-chip" onclick="selectCategory('<?php echo htmlspecialchars($choice, ENT_QUOTES); ?>')"><?php echo htmlspecialchars($choice); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Price</label>
                                    <input type="number" name="price" required min="0" step="0.01" value="<?php echo htmlspecialchars($editingProduct['price'] ?? '0.00'); ?>" placeholder="0.00">
                                </div>
                                <div class="form-group">
                                    <label>Quantity</label>
                                    <input type="number" name="quantity" required min="0" step="1" value="<?php echo htmlspecialchars($editingProduct['quantity'] ?? '0'); ?>" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label>Unit</label>
                                    <input type="text" name="unit" required value="<?php echo htmlspecialchars($editingProduct['unit'] ?? 'pcs'); ?>" placeholder="pcs">
                                </div>
                                <div class="form-group full">
                                    <label>Expiration date</label>
                                    <input type="date" name="expiration_date" value="<?php echo htmlspecialchars($editingProduct['expiration_date'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary"><?php echo $editingProduct ? 'Update Product' : 'Save Product'; ?></button>
                                <?php if ($editingProduct): ?>
                                    <a href="products_management.php<?php echo (buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page]) ? '?' . buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page]) : ''); ?>" class="btn btn-outline">Cancel Edit</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <aside class="panel">
                        <div class="panel-header">
                            <div>
                                <h2>Quick insights</h2>
                                <p>Use these stats to spot inventory pressure before it reaches the checkout lane.</p>
                            </div>
                        </div>

                        <div class="quick-grid">
                            <div class="quick-card">
                                <span class="label">Categories</span>
                                <span class="value"><?php echo number_format(count($categories)); ?></span>
                            </div>
                            <div class="quick-card">
                                <span class="label">Catalog status</span>
                                <span class="value"><?php echo $lowStockCount > 0 ? 'Needs review' : 'Healthy'; ?></span>
                            </div>
                            <div class="quick-card">
                                <span class="label">Store</span>
                                <span class="value"><?php echo htmlspecialchars($store['store_name']); ?></span>
                            </div>
                            <div class="quick-card">
                                <span class="label">User</span>
                                <span class="value"><?php echo htmlspecialchars($user['name']); ?></span>
                            </div>
                        </div>
                    </aside>
                </div>

                <section class="panel">
                    <div class="section-toolbar">
                        <div>
                            <h2 class="section-title">Product list</h2>
                            <p class="section-subtitle">Search, filter, edit, or remove items from the catalog.</p>
                        </div>
                        <form class="search-bar" method="get" action="products_management.php">
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
                            <a href="products_management.php" class="btn btn-outline">Reset</a>
                        </form>
                    </div>

                    <div class="table-toolbar">
                        <div class="bulk-actions">
                            <form method="post" action="products_management.php" id="bulkDeleteForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="bulk_delete_products">
                                <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                                <input type="hidden" name="page" value="<?php echo $page; ?>">
                                <div id="bulkProductInputs"></div>
                                <button type="button" class="btn btn-danger" id="bulkDeleteButton" disabled onclick="submitBulkDelete()">Delete selected</button>
                                <span id="bulkSelectionCount" class="form-note">0 selected</span>
                            </form>
                        </div>
                        <div class="page-meta">
                            <span><?php echo number_format($filteredProducts); ?> result<?php echo $filteredProducts === 1 ? '' : 's'; ?></span>
                            <?php if ($filteredProducts !== $totalProducts): ?>
                                <span>&middot; filtered from <?php echo number_format($totalProducts); ?> total</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="products-table">
                        <table>
                            <thead>
                                <tr>
                                    <th class="checkbox-cell"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)"></th>
                                    <th>Product</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Expiry</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state-cell">No products found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php [$statusLabel, $statusClass] = productStatusLabel($product); ?>
                                        <tr>
                                            <td><input type="checkbox" class="product-checkbox" value="<?php echo (int)$product['id']; ?>" onchange="updateBulkSelection()"></td>
                                            <td>
                                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                                <div class="product-meta">Unit: <?php echo htmlspecialchars($product['unit']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($product['category']); ?></td>
                                            <td>
                                                <div class="product-name"><?php echo (int)$product['quantity']; ?> <?php echo htmlspecialchars($product['unit']); ?></div>
                                            </td>
                                            <td>$<?php echo number_format((float)$product['price'], 2); ?></td>
                                            <td><span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span></td>
                                            <td><?php echo !empty($product['expiration_date']) ? htmlspecialchars($product['expiration_date']) : 'N/A'; ?></td>
                                            <td>
                                                <div class="action-group">
                                                    <a href="products_management.php?edit=<?php echo (int)$product['id']; ?><?php echo (buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page]) ? '&' . buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page]) : ''); ?>">Edit</a>
                                                    <form method="post" action="products_management.php" onsubmit="return confirm('Delete this product?');" class="inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                                                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($categoryFilter); ?>">
                                                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                                                        <input type="hidden" name="page" value="<?php echo $page; ?>">
                                                        <button type="submit" class="btn btn-danger">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if ($totalPages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="products_management.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page - 1]); ?>">Previous</a>
                                <?php endif; ?>

                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                    <a href="products_management.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $p]); ?>" class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <a href="products_management.php?<?php echo buildQueryString(['q' => $search, 'category' => $categoryFilter, 'status' => $statusFilter, 'page' => $page + 1]); ?>">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('open');
            }
        }

        function updateBulkSelection() {
            const selected = document.querySelectorAll('.product-checkbox:checked');
            const bulkDeleteButton = document.getElementById('bulkDeleteButton');
            const bulkSelectionCount = document.getElementById('bulkSelectionCount');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const rowCheckboxes = document.querySelectorAll('.product-checkbox');

            if (bulkDeleteButton) {
                bulkDeleteButton.disabled = selected.length === 0;
            }

            if (bulkSelectionCount) {
                bulkSelectionCount.textContent = `${selected.length} selected`;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.checked = rowCheckboxes.length > 0 && selected.length === rowCheckboxes.length;
            }
        }

        function toggleSelectAll(source) {
            document.querySelectorAll('.product-checkbox').forEach((checkbox) => {
                checkbox.checked = source.checked;
            });
            updateBulkSelection();
        }

        async function submitBulkDelete() {
            const selected = Array.from(document.querySelectorAll('.product-checkbox:checked')).map((checkbox) => checkbox.value);
            const inputsContainer = document.getElementById('bulkProductInputs');
            const bulkDeleteForm = document.getElementById('bulkDeleteForm');

            if (!inputsContainer || !bulkDeleteForm) {
                return;
            }

            inputsContainer.innerHTML = '';

            if (!selected.length) {
                return;
            }

            if (window.AppUI && typeof window.AppUI.confirm === 'function') {
                const confirmed = await window.AppUI.confirm(`Delete ${selected.length} selected product${selected.length === 1 ? '' : 's'}?`, {
                    confirmText: 'Delete selected'
                });
                if (!confirmed) {
                    return;
                }
            } else if (!confirm(`Delete ${selected.length} selected product${selected.length === 1 ? '' : 's'}?`)) {
                return;
            }

            selected.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_products[]';
                input.value = id;
                inputsContainer.appendChild(input);
            });

            bulkDeleteForm.submit();
        }

        function toggleCategoryDropdown() {
            const dropdown = document.getElementById('categoryModal');
            if (!dropdown) {
                return;
            }
            dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
        }

        function openCategoryDropdown() {
            const dropdown = document.getElementById('categoryModal');
            if (dropdown) {
                dropdown.style.display = 'block';
            }
        }

        function closeCategoryDropdown() {
            const dropdown = document.getElementById('categoryModal');
            if (dropdown) {
                dropdown.style.display = 'none';
            }
        }

        function selectCategory(value) {
            const input = document.getElementById('categoryInput');
            if (input) {
                input.value = value;
            }
            closeCategoryDropdown();
        }

        document.addEventListener('click', (event) => {
            const dropdown = document.getElementById('categoryModal');
            const wrapper = document.querySelector('.category-dropdown-wrapper');
            if (!dropdown || !wrapper) {
                return;
            }
            if (dropdown.style.display !== 'block') {
                return;
            }
            if (!wrapper.contains(event.target)) {
                closeCategoryDropdown();
            }
        });
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>

