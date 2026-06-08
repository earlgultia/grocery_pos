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

        .sidebar-menu a span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 1.6rem;
        }

        .sidebar-footer {
            margin: 0.5rem 0.75rem 0.9rem;
            padding: 0.95rem;
            border-radius: 1rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
        }

        .sidebar-footer-label {
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.82);
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .sidebar-footer-actions {
            display: grid;
            gap: 0.45rem;
        }

        .sidebar-footer-actions a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.7rem 0.8rem;
            border-radius: 0.85rem;
            text-decoration: none;
            color: rgba(255,255,255,0.96);
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            font-size: 0.88rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .sidebar-footer-actions a:hover {
            background: rgba(255,255,255,0.12);
            transform: translateX(2px);
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

        .page-hero,
        .panel,
        .stats-card,
        .message-card {
            background: var(--app-surface);
            backdrop-filter: blur(14px);
            border: 1px solid var(--app-border);
            box-shadow: var(--app-shadow);
            border-radius: 1.2rem;
        }

        .page-hero {
            padding: 1.4rem;
            display: grid;
            grid-template-columns: 1.3fr 0.7fr;
            gap: 1rem;
            align-items: center;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.85rem;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.08);
            color: #4338ca;
            font-weight: 700;
            font-size: 0.82rem;
        }

        .page-hero h1 {
            margin: 0.85rem 0 0.35rem;
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(1.7rem, 3vw, 2.45rem);
            letter-spacing: -0.04em;
            color: var(--app-text);
        }

        .page-hero p {
            margin: 0;
            color: var(--app-muted);
            line-height: 1.65;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.7rem;
            margin-top: 1rem;
        }

        .hero-stats {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .hero-stat {
            padding: 0.95rem;
            border-radius: 1rem;
            background: rgba(255,255,255,0.76);
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .hero-stat span {
            display: block;
        }

        .hero-stat .label {
            color: var(--app-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.25rem;
        }

        .hero-stat .value {
            color: var(--app-text);
            font-weight: 800;
            font-size: 1.15rem;
        }

        .message-card {
            padding: 1rem 1.1rem;
            color: #0f172a;
            font-weight: 600;
        }

        .message-card.success { border-color: rgba(34, 197, 94, 0.18); background: rgba(34, 197, 94, 0.08); }
        .message-card.error { border-color: rgba(239, 68, 68, 0.18); background: rgba(239, 68, 68, 0.08); }

        .layout-grid {
            display: grid;
            grid-template-columns: 0.95fr 1.05fr;
            gap: 1.25rem;
            align-items: start;
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

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.9rem;
        }

        .form-group {
            display: grid;
            gap: 0.45rem;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 700;
            color: var(--app-text);
            font-size: 0.92rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.9rem 1rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
            color: var(--app-text);
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: rgba(79, 70, 229, 0.45);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.7rem;
            margin-top: 1rem;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            box-shadow: 0 12px 24px rgba(239, 68, 68, 0.16);
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .section-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .search-bar {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-bar input,
        .search-bar select {
            min-width: 220px;
            padding: 0.85rem 1rem;
            border-radius: 0.95rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
        }

        .table-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .page-meta {
            color: var(--app-muted);
            font-size: 0.95rem;
        }

        .pagination {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
            align-items: center;
        }

        .pagination a {
            padding: 0.65rem 0.95rem;
            border-radius: 0.95rem;
            color: var(--app-text);
            text-decoration: none;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.94);
        }

        .pagination a.active {
            background: rgba(79, 70, 229, 0.16);
            border-color: rgba(79, 70, 229, 0.24);
            font-weight: 700;
        }

        .checkbox-cell {
            width: 56px;
            text-align: center;
        }

        .product-checkbox {
            width: 16px;
            height: 16px;
        }

        .products-table {
            width: 100%;
            overflow-x: auto;
        }

        .products-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .products-table th,
        .products-table td {
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: top;
        }

        .products-table th {
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

        .product-meta,
        .product-subtle {
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

        .action-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-group a,
        .action-group button {
            padding: 0.55rem 0.85rem;
            border-radius: 0.8rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: #fff;
            text-decoration: none;
            color: var(--app-text);
            font-weight: 700;
            cursor: pointer;
        }

        .action-group button {
            background: rgba(239, 68, 68, 0.08);
            color: #b91c1c;
            border-color: rgba(239, 68, 68, 0.18);
        }

        .quick-grid {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .quick-card {
            padding: 1rem;
            border-radius: 1rem;
            background: rgba(255,255,255,0.76);
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .quick-card span {
            display: block;
        }

        .quick-card .label {
            color: var(--app-muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.25rem;
        }

        .quick-card .value {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--app-text);
        }

        .category-dropdown-wrapper {
            position: relative;
        }

        .category-dropdown-panel {
            position: absolute;
            top: calc(100% + 0.75rem);
            left: 0;
            right: 0;
            z-index: 50;
            background: #ffffff;
            border-radius: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
            padding: 1rem 1rem 0.85rem;
            min-width: 320px;
        }

        .category-dropdown-panel::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 24px;
            width: 16px;
            height: 16px;
            background: #ffffff;
            transform: rotate(45deg);
            border-left: 1px solid rgba(15, 23, 42, 0.12);
            border-top: 1px solid rgba(15, 23, 42, 0.12);
            z-index: -1;
        }

        .category-dropdown-panel .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .category-dropdown-panel .modal-header h3 {
            margin: 0;
            font-size: 1rem;
            color: var(--app-text);
        }

        .category-dropdown-panel .modal-close {
            border: none;
            background: transparent;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--app-text);
            cursor: pointer;
        }

        .category-dropdown-panel .category-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 0.65rem;
        }

        .category-dropdown-panel .category-chip {
            width: 100%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.2rem;
            color: var(--app-text);
        }

        .modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--app-text);
            cursor: pointer;
        }

        .category-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0.75rem;
        }

        .category-chip {
            border: 1px solid rgba(79, 70, 229, 0.18);
            background: rgba(79, 70, 229, 0.08);
            color: #3730a3;
            padding: 0.85rem 1rem;
            border-radius: 999px;
            cursor: pointer;
            font-weight: 700;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .category-chip:hover {
            transform: translateY(-1px);
            background: rgba(79, 70, 229, 0.14);
        }

        @media (max-width: 1100px) {
            .layout-grid,
            .page-hero {
                grid-template-columns: 1fr;
            }
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

            .form-grid,
            .quick-grid {
                grid-template-columns: 1fr;
            }

            .search-bar input,
            .search-bar select {
                min-width: 100%;
            }

            .hero-stats {
                grid-template-columns: 1fr;
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
                <div class="sidebar-status">📅 <?php echo date('F j, Y'); ?> · Products</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>📊</span> Dashboard
                </a>
                <a href="pos.php">
                    <span>💰</span> Point of Sale
                </a>
                <a href="products_management.php" class="active">
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
            <div class="sidebar-footer">
                <div class="sidebar-footer-label">Quick Actions</div>
                <div class="sidebar-footer-actions">
                    <a href="pos.php">
                        <span>Open POS</span>
                        <span>↗</span>
                    </a>
                    <a href="store_dashboard.php">
                        <span>View dashboard</span>
                        <span>↗</span>
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
                                    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;position:relative;">
                                        <input id="categoryInput" type="text" name="category" required value="<?php echo htmlspecialchars($editingProduct['category'] ?? ''); ?>" placeholder="Dairy" style="flex:1;">
                                        <button type="button" class="btn btn-outline" onclick="toggleCategoryDropdown()">Choose</button>
                                        <div id="categoryModal" class="category-dropdown-panel" style="display:none;">
                                            <div class="modal-header">
                                                <h3>Choose category</h3>
                                                <button type="button" class="modal-close" onclick="closeCategoryDropdown()">×</button>
                                            </div>
                                            <p style="margin:0 0 1rem;color:#64748b;">Select a category or type a custom one.</p>
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
                                <span id="bulkSelectionCount" style="color: var(--app-muted);">0 selected</span>
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
                                        <td colspan="8" style="text-align:center; color:#64748b; padding: 1.5rem;">No products found.</td>
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
                                                    <form method="post" action="products_management.php" onsubmit="return confirm('Delete this product?');" style="display:inline;">
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
