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
    
</head>
<body>
    <div class="dashboard-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h3>ðŸ›’ <?php echo htmlspecialchars($store['store_name']); ?></h3>
                <p><?php echo htmlspecialchars($user['name']); ?></p>
                <div class="sidebar-status">ðŸ“… <?php echo date('F j, Y'); ?> Â· POS</div>
            </div>
            <div class="sidebar-menu">
                <a href="store_dashboard.php">
                    <span>ðŸ“Š</span> Dashboard
                </a>
                <a href="pos.php" class="active">
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
                                    <div class="product-card empty-state">
                                        <p>No products are available for sale right now.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php [$statusLabel, $statusClass] = productStatusLabel($product); ?>
                                        <article class="product-card" data-product-id="<?php echo (int)$product['id']; ?>" data-name="<?php echo htmlspecialchars($product['name'], ENT_QUOTES); ?>" data-price="<?php echo number_format((float)$product['price'], 2, '.', ''); ?>" data-quantity="<?php echo (int)$product['quantity']; ?>" data-unit="<?php echo htmlspecialchars($product['unit'], ENT_QUOTES); ?>" data-category="<?php echo htmlspecialchars($product['category'], ENT_QUOTES); ?>" data-expiration-date="<?php echo htmlspecialchars($product['expiration_date'], ENT_QUOTES); ?>">
                                            <div>
                                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                                <p class="product-meta"><?php echo htmlspecialchars($product['category']); ?> Â· <?php echo htmlspecialchars($product['unit']); ?></p>
                                                <p class="product-meta">Stock: <?php echo (int)$product['quantity']; ?></p>
                                                <p class="product-meta">Price: $<?php echo number_format((float)$product['price'], 2); ?></p>
                                            </div>
                                            <div>
                                                <span class="badge badge-<?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                                                <p class="product-meta spaced">Expiry: <?php echo !empty($product['expiration_date']) ? htmlspecialchars($product['expiration_date']) : 'N/A'; ?></p>
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

                            <div id="posMessage" class="message-card"></div>

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
                                            <td colspan="5" class="empty-state-cell">Add products to the cart to begin.</td>
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
                                    <option value="gcash">GCash</option>
                                    <option value="maya">Maya</option>
                                    <option value="bank_transfer">Bank transfer</option>
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
                            <button type="button" class="btn btn-primary btn-block" id="checkoutButton" onclick="completeSale()">Complete sale</button>
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

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value;
            return div.innerHTML;
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
                } else {
                    showMessage(`Only ${stock} ${unit} available for ${name}.`, 'warning');
                }
            } else {
                cartItem = {
                    product_id: productId,
                    name,
                    price,
                    quantity: 1,
                    unit,
                    stock
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
            const checkoutButton = document.getElementById('checkoutButton');
            const paymentReceived = parseFloat(document.getElementById('paymentReceived').value) || 0;

            if (cart.length === 0) {
                cartBody.innerHTML = '<tr><td colspan="5" class="empty-state-cell">Add products to the cart to begin.</td></tr>';
                subtotalDisplay.value = '$0.00';
                totalDisplay.value = '$0.00';
                changeDisplay.value = '$0.00';
                if (checkoutButton) {
                    checkoutButton.disabled = true;
                }
                return;
            }

            if (checkoutButton) {
                checkoutButton.disabled = false;
            }

            cartBody.innerHTML = '';
            let subtotal = 0;

            cart.forEach(item => {
                const lineTotal = item.price * item.quantity;
                subtotal += lineTotal;
                const itemName = escapeHtml(item.name);
                const itemUnit = escapeHtml(item.unit);

                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <div class="product-name">${itemName}</div>
                        <div class="product-meta">${itemUnit}</div>
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
            item.quantity = Math.min(item.stock, Math.max(1, item.quantity + delta));
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
            if (window.AppUI && typeof window.AppUI.notify === 'function') {
                window.AppUI.notify(message, type);
            }
        }

        async function completeSale() {
            if (cart.length === 0) {
                showMessage('Add at least one product to complete the sale.', 'error');
                return;
            }

            const customerName = document.getElementById('customerName').value.trim() || 'Walk-in customer';
            const paymentReceived = parseFloat(document.getElementById('paymentReceived').value) || 0;
            const paymentMethod = document.getElementById('paymentMethod').value;
            const checkoutButton = document.getElementById('checkoutButton');
            const resetLoading = window.AppUI && typeof window.AppUI.setButtonLoading === 'function'
                ? window.AppUI.setButtonLoading(checkoutButton, 'Completing sale...')
                : () => {};
            const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
            const totalAmount = subtotal;
            const changeAmount = Math.max(0, paymentReceived - totalAmount);

            if (paymentReceived < totalAmount) {
                resetLoading();
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
            } finally {
                resetLoading();
                renderCart();
            }
        }

        renderCart();
    </script>
    <script src="js/app-nav.js"></script>
</body>
</html>

