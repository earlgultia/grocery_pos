<?php
require_once 'includes/functions.php';
require_once 'includes/db_connect.php';

redirectIfNotLoggedIn();

$db = getDB();
$user = null;
$store = null;
$search = sanitizeInput($_GET['q'] ?? '');
$categoryFilter = sanitizeInput($_GET['category'] ?? '');

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

$where = ['store_id = ?', 'quantity > 0'];
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

$whereClause = implode(' AND ', $where);

$productStmt = $db->prepare("SELECT * FROM products WHERE {$whereClause} ORDER BY name ASC");
$productStmt->execute($params);
$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Point of Sale - <?php echo htmlspecialchars($store['store_name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --app-text: #0f172a;
            --app-muted: #475569;
            --app-surface: rgba(255, 255, 255, 0.90);
            --app-border: rgba(15, 23, 42, 0.12);
            --app-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            --brand-blue: #4f46e5;
            --brand-green: #22c55e;
        }

        body {
            font-family: 'Inter', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(79, 70, 229, 0.12), transparent 30%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.10), transparent 26%),
                linear-gradient(180deg, #eef2ff 0%, #f8fafc 45%, #eef2ff 100%);
            color: var(--app-text);
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: linear-gradient(rgba(255,255,255,0.42) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.42) 1px, transparent 1px);
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
            background: linear-gradient(180deg, #0f172a 0%, #111827 44%, #1e293b 100%);
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 16px 0 40px rgba(15, 23, 42, 0.22);
        }

        .sidebar-header {
            padding: 1.7rem 1.4rem 1.3rem;
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
            margin: 0.4rem 0 0;
            font-size: 0.88rem;
            color: rgba(255,255,255,0.78);
        }

        .sidebar-status {
            margin-top: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.75rem;
            border-radius: 999px;
            background: rgba(79, 70, 229, 0.16);
            color: #e0e7ff;
            border: 1px solid rgba(129, 140, 248, 0.20);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .sidebar-menu {
            padding: 1rem 0.85rem 1.25rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 0.95rem 1rem;
            margin-bottom: 0.35rem;
            border-radius: 1.1rem;
            color: rgba(255,255,255,0.92);
            text-decoration: none;
            transition: all 0.18s ease;
            border: 1px solid transparent;
            font-weight: 600;
        }

        .sidebar-menu a span {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.9rem;
            background: rgba(255,255,255,0.08);
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.28), rgba(34, 197, 94, 0.16));
            border-color: rgba(129, 140, 248, 0.24);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.05);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 1.75rem;
            min-width: 0;
        }

        .page-shell {
            display: grid;
            gap: 1.35rem;
        }

        .panel {
            background: var(--app-surface);
            backdrop-filter: blur(14px);
            border: 1px solid var(--app-border);
            box-shadow: var(--app-shadow);
            border-radius: 1.25rem;
            padding: 1.25rem;
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
            font-size: 1.35rem;
        }

        .panel-header p {
            margin: 0.35rem 0 0;
            color: var(--app-muted);
            line-height: 1.7;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.85rem 1.2rem;
            border-radius: 999px;
            text-decoration: none;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--brand-blue), #4338ca);
            color: #fff;
            box-shadow: 0 14px 30px rgba(79, 70, 229, 0.16);
        }

        .btn-outline {
            background: rgba(255,255,255,0.96);
            color: var(--app-text);
            border: 1px solid rgba(15, 23, 42, 0.12);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            box-shadow: 0 14px 30px rgba(239, 68, 68, 0.16);
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
            padding: 0.95rem 1rem;
            border-radius: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
            color: var(--app-text);
            font-size: 0.95rem;
        }

        .layout-grid {
            display: grid;
            grid-template-columns: 1.45fr 0.95fr;
            gap: 1.25rem;
            align-items: start;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }

        .product-card {
            border-radius: 1.2rem;
            background: rgba(255,255,255,0.98);
            border: 1px solid rgba(15, 23, 42, 0.08);
            padding: 1.1rem;
            display: grid;
            gap: 1rem;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .product-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
            border-color: rgba(79, 70, 229, 0.16);
        }

        .product-card h3 {
            margin: 0;
            font-size: 1.03rem;
            color: var(--app-text);
        }

        .product-meta,
        .product-status {
            color: var(--app-muted);
            font-size: 0.88rem;
            margin: 0.22rem 0;
            line-height: 1.5;
        }

        .product-card .badge {
            margin-top: 0.2rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .badge-success { background: rgba(34, 197, 94, 0.12); color: #166534; }
        .badge-warning { background: rgba(245, 158, 11, 0.14); color: #92400e; }
        .badge-danger { background: rgba(239, 68, 68, 0.12); color: #991b1b; }

        .cart-card {
            display: grid;
            gap: 1rem;
            position: sticky;
            top: 1.75rem;
            align-self: start;
            max-height: calc(100vh - 3.5rem);
            overflow: auto;
        }

        .cart-card .panel-header {
            margin-bottom: 0.75rem;
        }

        .cart-summary {
            display: grid;
            gap: 0.85rem;
        }

        .cart-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 100%;
        }

        .cart-table th,
        .cart-table td {
            padding: 0.95rem 0.85rem;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
            text-align: left;
            vertical-align: middle;
        }

        .cart-table th {
            background: rgba(79, 70, 229, 0.06);
            color: var(--app-text);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .cart-table tbody tr:last-child td {
            border-bottom: none;
        }

        .product-name {
            font-weight: 700;
            color: var(--app-text);
            margin-bottom: 0.2rem;
        }

        .quantity-control {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            background: rgba(15, 23, 42, 0.04);
            padding: 0.35rem 0.45rem;
            border-radius: 0.9rem;
        }

        .quantity-control button {
            width: 30px;
            height: 30px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-radius: 0.75rem;
            background: #fff;
            cursor: pointer;
            font-weight: 700;
            color: var(--app-text);
        }

        .checkout-field {
            display: grid;
            gap: 0.5rem;
        }

        .checkout-field label {
            font-weight: 700;
            color: var(--app-text);
        }

        .checkout-field input,
        .checkout-field select {
            width: 100%;
            padding: 0.95rem 1rem;
            border-radius: 1rem;
            border: 1px solid rgba(15, 23, 42, 0.12);
            background: rgba(255,255,255,0.96);
            color: var(--app-text);
            font-size: 0.95rem;
        }

        .message-card {
            padding: 1rem 1.1rem;
            border-radius: 1rem;
            border: 1px solid transparent;
            color: #0f172a;
            font-weight: 600;
            display: none;
        }

        .message-card.success { border-color: rgba(34, 197, 94, 0.18); background: rgba(34, 197, 94, 0.08); }
        .message-card.error { border-color: rgba(239, 68, 68, 0.18); background: rgba(239, 68, 68, 0.08); }

        @media (max-width: 1100px) {
            .layout-grid {
                grid-template-columns: 1fr;
            }

            .cart-card {
                position: static;
                max-height: none;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .search-bar,
            .hero-actions {
                flex-direction: column;
            }

            .product-grid {
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
                <div class="sidebar-status">📅 <?php echo date('F j, Y'); ?> · POS</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>📊</span> Dashboard
                </a>
                <a href="pos.php" class="active">
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

        <div class="main-content">
            <div class="page-shell">
                <section class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Point of Sale</h2>
                            <p>Quickly add items to the cart, collect payment, and complete customer checkout from one intuitive workspace.</p>
                        </div>
                    </div>

                    <form class="search-bar" method="get" action="pos.php">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search products...">
                        <select name="category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>><?php echo htmlspecialchars($category); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="pos.php" class="btn btn-outline">Reset</a>
                    </form>

                    <div class="layout-grid">
                        <section>
                            <div class="product-grid">
                                <?php if (empty($products)): ?>
                                    <div class="product-card" style="grid-column: 1 / -1; text-align:center; color:#64748b;">
                                        <p>No products are available for sale right now.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php [$statusLabel, $statusClass] = productStatusLabel($product); ?>
                                        <article class="product-card" data-product-id="<?php echo (int)$product['id']; ?>" data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" data-price="<?php echo number_format((float)$product['price'], 2, '.', ''); ?>" data-quantity="<?php echo (int)$product['quantity']; ?>" data-unit="<?php echo htmlspecialchars($product['unit'], ENT_QUOTES); ?>" data-category="<?php echo htmlspecialchars($product['category'], ENT_QUOTES); ?>" data-expiration-date="<?php echo htmlspecialchars($product['expiration_date'], ENT_QUOTES); ?>">
                                            <div>
                                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                                <p class="product-meta"><?php echo htmlspecialchars($product['category']); ?> · <?php echo htmlspecialchars($product['unit']); ?></p>
                                                <p class="product-meta">Stock: <?php echo (int)$product['quantity']; ?></p>
                                                <p class="product-meta">Price: $<?php echo number_format((float)$product['price'], 2); ?></p>
                                            </div>
                                            <div>
                                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                                <p class="product-meta" style="margin-top:0.75rem;">Expiry: <?php echo !empty($product['expiration_date']) ? htmlspecialchars($product['expiration_date']) : 'N/A'; ?></p>
                                                <button type="button" class="btn btn-primary" onclick="addToCart(this.closest('.product-card'))">Add to cart</button>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </section>

                        <aside class="cart-card panel">
                            <div class="panel-header">
                                <div>
                                    <h2>Cart</h2>
                                    <p>Review cart items and collect payment before completing the sale.</p>
                                </div>
                            </div>

                            <div id="posMessage" class="message-card" style="display:none;"></div>

                            <div class="cart-summary">
                                <table class="cart-table" id="cartTable">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Qty</th>
                                            <th>Price</th>
                                            <th>Total</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="cartBody">
                                        <tr>
                                            <td colspan="5" style="text-align:center; color:#64748b; padding:1.25rem;">Add products to the cart to begin.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="checkout-field">
                                <label>Customer name</label>
                                <input type="text" id="customerName" placeholder="Walk-in customer">
                            </div>
                            <div class="checkout-field">
                                <label>Payment method</label>
                                <select id="paymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="checkout-field">
                                <label>Payment received</label>
                                <input type="number" id="paymentReceived" min="0" step="0.01" value="0">
                            </div>
                            <div class="checkout-field">
                                <label>Subtotal</label>
                                <input type="text" id="subtotalDisplay" readonly value="$0.00">
                            </div>
                            <div class="checkout-field">
                                <label>Total</label>
                                <input type="text" id="totalDisplay" readonly value="$0.00">
                            </div>
                            <div class="checkout-field">
                                <label>Change</label>
                                <input type="text" id="changeDisplay" readonly value="$0.00">
                            </div>
                            <button type="button" class="btn btn-primary" id="checkoutButton" onclick="completeSale()" style="width:100%;">Complete sale</button>
                        </aside>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <script>
        const cart = [];

        function formatCurrency(value) {
            return '$' + value.toFixed(2);
        }

        function findCartItem(productId) {
            return cart.find(item => item.product_id === productId);
        }

        function addToCart(card) {
            const productId = parseInt(card.dataset.productId, 10);
            const name = card.dataset.name;
            const price = parseFloat(card.dataset.price);
            const stock = parseInt(card.dataset.quantity, 10);
            const unit = card.dataset.unit;

            let cartItem = findCartItem(productId);
            if (cartItem) {
                if (cartItem.quantity < stock) {
                    cartItem.quantity += 1;
                }
            } else {
                cartItem = {
                    product_id: productId,
                    name,
                    price,
                    quantity: 1,
                    unit
                };
                cart.push(cartItem);
            }

            renderCart();
        }

        function renderCart() {
            const cartBody = document.getElementById('cartBody');
            const subtotalDisplay = document.getElementById('subtotalDisplay');
            const totalDisplay = document.getElementById('totalDisplay');
            const changeDisplay = document.getElementById('changeDisplay');
            const paymentReceived = parseFloat(document.getElementById('paymentReceived').value) || 0;

            if (cart.length === 0) {
                cartBody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#64748b; padding:1.25rem;">Add products to the cart to begin.</td></tr>';
                subtotalDisplay.value = '$0.00';
                totalDisplay.value = '$0.00';
                changeDisplay.value = '$0.00';
                return;
            }

            cartBody.innerHTML = '';
            let subtotal = 0;

            cart.forEach(item => {
                const lineTotal = item.price * item.quantity;
                subtotal += lineTotal;

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <div class="product-name">${item.name}</div>
                        <div class="product-meta">${item.unit}</div>
                    </td>
                    <td>
                        <div class="quantity-control">
                            <button type="button" onclick="updateQuantity(${item.product_id}, -1)">-</button>
                            <span>${item.quantity}</span>
                            <button type="button" onclick="updateQuantity(${item.product_id}, 1)">+</button>
                        </div>
                    </td>
                    <td>${formatCurrency(item.price)}</td>
                    <td>${formatCurrency(lineTotal)}</td>
                    <td><button type="button" class="btn btn-outline" onclick="removeFromCart(${item.product_id})">Remove</button></td>
                `;
                cartBody.appendChild(row);
            });

            const total = subtotal;
            const change = Math.max(0, paymentReceived - total);

            subtotalDisplay.value = formatCurrency(subtotal);
            totalDisplay.value = formatCurrency(total);
            changeDisplay.value = formatCurrency(change);
        }

        function updateQuantity(productId, delta) {
            const item = findCartItem(productId);
            if (!item) return;
            item.quantity = Math.max(1, item.quantity + delta);
            renderCart();
        }

        function removeFromCart(productId) {
            const index = cart.findIndex(item => item.product_id === productId);
            if (index !== -1) {
                cart.splice(index, 1);
                renderCart();
            }
        }

        document.getElementById('paymentReceived').addEventListener('input', renderCart);

        function showMessage(message, type = 'success') {
            const messageCard = document.getElementById('posMessage');
            messageCard.textContent = message;
            messageCard.className = `message-card ${type}`;
            messageCard.style.display = 'block';
        }

        async function completeSale() {
            if (cart.length === 0) {
                showMessage('Add at least one product to complete the sale.', 'error');
                return;
            }

            const customerName = document.getElementById('customerName').value.trim() || 'Walk-in customer';
            const paymentReceived = parseFloat(document.getElementById('paymentReceived').value) || 0;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
            const totalAmount = subtotal;
            const changeAmount = Math.max(0, paymentReceived - totalAmount);

            if (paymentReceived < totalAmount) {
                showMessage('Payment received must cover the total amount.', 'error');
                return;
            }

            const payload = {
                customer_name: customerName,
                tax: 0,
                discount: 0,
                total_amount: parseFloat(totalAmount.toFixed(2)),
                payment_received: parseFloat(paymentReceived.toFixed(2)),
                change_amount: parseFloat(changeAmount.toFixed(2)),
                payment_method: paymentMethod,
                items: cart.map(item => ({
                    product_id: item.product_id,
                    quantity: item.quantity
                }))
            };

            try {
                const response = await fetch('api/process_transaction.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(payload)
                });

                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    showMessage('Server error: ' + responseText, 'error');
                    return;
                }

                if (!data.success) {
                    showMessage(data.message || 'Payment failed. Please try again.', 'error');
                    return;
                }

                showMessage(`Sale completed successfully. Change: $${changeAmount.toFixed(2)}.`);
                cart.length = 0;
                renderCart();
                document.getElementById('customerName').value = '';
                document.getElementById('paymentReceived').value = '0';
            } catch (error) {
                showMessage(error.message || 'Unable to complete the sale. Please try again.', 'error');
            }
        }

        renderCart();
    </script>
</body>
</html>
