<?php
require_once '../includes/db_connect.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'store_user') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);

try {
    $db = getDB();
    
    // Start transaction
    $db->beginTransaction();
    
    // Get store_id
    $stmt = $db->prepare("SELECT store_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $store_id = $stmt->fetchColumn();
    
    // Calculate subtotal and attach product metadata
    $subtotal = 0;
    foreach ($data['items'] as $index => $item) {
        $stmt = $db->prepare("SELECT price, name FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new Exception('Product not found for item ID: ' . $item['product_id']);
        }

        $subtotal += $product['price'] * $item['quantity'];
        $data['items'][$index]['product_name'] = $product['name'];
        $data['items'][$index]['unit_price'] = $product['price'];
    }
    
    // Create transaction
    $stmt = $db->prepare("
        INSERT INTO transactions (store_id, user_id, customer_name, subtotal, tax, discount, total_amount, payment_received, change_amount, payment_method, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ");
    
    $stmt->execute([
        $store_id,
        $_SESSION['user_id'],
        $data['customer_name'],
        $subtotal,
        $data['tax'],
        $data['discount'],
        $data['total_amount'],
        $data['payment_received'],
        $data['change_amount'],
        $data['payment_method']
    ]);
    
    $transaction_id = $db->lastInsertId();
    
    // Get invoice number
    $stmt = $db->prepare("SELECT invoice_number FROM transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $invoice_number = $stmt->fetchColumn();
    
    // Insert transaction items and update stock
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
        
        // Update stock
        $stmt = $db->prepare("UPDATE products SET quantity = quantity - ? WHERE id = ?");
        $stmt->execute([$item['quantity'], $item['product_id']]);
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'invoice_number' => $invoice_number,
        'change' => $data['change_amount']
    ]);
    
} catch(Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>