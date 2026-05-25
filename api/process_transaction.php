<?php
require_once '../includes/functions.php';
require_once '../includes/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'], $_SESSION['user_role']) || $_SESSION['user_role'] !== 'store_user') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$allowedPaymentMethods = ['cash', 'card', 'other', 'gcash', 'maya', 'bank_transfer'];

try {
    if (!is_array($data)) {
        throw new Exception('Invalid transaction data.');
    }

    if (empty($data['items']) || !is_array($data['items'])) {
        throw new Exception('Add at least one product to complete the sale.');
    }

    $paymentMethod = $data['payment_method'] ?? '';
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        throw new Exception('Invalid payment method.');
    }

    $db = getDB();
    
    $db->beginTransaction();
    
    $stmt = $db->prepare("SELECT store_id FROM users WHERE id = ? AND role = 'store_user'");
    $stmt->execute([$_SESSION['user_id']]);
    $store_id = $stmt->fetchColumn();

    if (!$store_id) {
        throw new Exception('Store account not found.');
    }
    
    $subtotal = 0;
    foreach ($data['items'] as $index => $item) {
        $productId = isset($item['product_id']) ? (int)$item['product_id'] : 0;
        $quantity = isset($item['quantity']) ? (float)$item['quantity'] : 0;

        if ($productId <= 0 || $quantity <= 0) {
            throw new Exception('Invalid cart item.');
        }

        $stmt = $db->prepare("SELECT id, price, name, quantity FROM products WHERE id = ? AND store_id = ? FOR UPDATE");
        $stmt->execute([$productId, $store_id]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new Exception('Product not found for item ID: ' . $productId);
        }

        if ((float)$product['quantity'] < $quantity) {
            throw new Exception('Insufficient stock for ' . $product['name'] . '.');
        }

        $subtotal += (float)$product['price'] * $quantity;
        $data['items'][$index]['product_id'] = $productId;
        $data['items'][$index]['quantity'] = $quantity;
        $data['items'][$index]['product_name'] = $product['name'];
        $data['items'][$index]['unit_price'] = (float)$product['price'];
    }

    $tax = max(0, (float)($data['tax'] ?? 0));
    $discount = max(0, (float)($data['discount'] ?? 0));
    $totalAmount = max(0, $subtotal + $tax - $discount);
    $paymentReceived = (float)($data['payment_received'] ?? 0);

    if ($paymentReceived < $totalAmount) {
        throw new Exception('Payment received must cover the total amount.');
    }

    $changeAmount = $paymentReceived - $totalAmount;
    
    $stmt = $db->prepare("
        INSERT INTO transactions (store_id, user_id, customer_name, subtotal, tax, discount, total_amount, payment_received, change_amount, payment_method, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ");
    
    $stmt->execute([
        $store_id,
        $_SESSION['user_id'],
        sanitizeInput($data['customer_name'] ?? 'Walk-in customer') ?: 'Walk-in customer',
        $subtotal,
        $tax,
        $discount,
        $totalAmount,
        $paymentReceived,
        $changeAmount,
        $paymentMethod
    ]);
    
    $transaction_id = $db->lastInsertId();
    
    // Get invoice number
    $stmt = $db->prepare("SELECT invoice_number FROM transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $invoice_number = $stmt->fetchColumn();
    
    foreach ($data['items'] as $item) {
        $stmt = $db->prepare("
            INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $transaction_id,
            $item['product_id'],
            $item['product_name'],
            $item['quantity'],
            $item['unit_price'],
            $item['unit_price'] * $item['quantity']
        ]);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'invoice_number' => $invoice_number,
        'change' => $changeAmount
    ]);
    
} catch(Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
