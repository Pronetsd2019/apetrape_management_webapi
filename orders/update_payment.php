<?php
/**
 * Update Payment Endpoint
 * POST /orders/update_payment.php
 */

 require_once __DIR__ . '/../util/connect.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'orders', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update orders.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    if (!isset($input['order_id']) || !isset($input['payment_method_id']) || !isset($input['reference']) || !isset($input['amount']) || !isset($input['transaction_id']) || !isset($input['payment_date'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: order Id, payment method, referrance, amount, and transaction Id pay date are required.'
        ]);
        exit;
    }

    $orderId = trim($input['order_id']);
    $payMethod = trim($input['payment_method_id']);
    $ref = trim($input['reference']);
    $amount = trim($input['amount']);
    $transactionId = trim($input['transaction_id']);
    $payDate = isset($input['payment_date']) ? trim($input['payment_date']) : date('Y-m-d H:i:s');

    // Validate input
    if (empty($orderId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Order ID cannot be empty.'
        ]);
        exit;
    }

    if (empty($payMethod)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Payment method cannot be empty.'
        ]);
        exit;
    }

    if (empty($ref)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Reference cannot be empty.'
        ]);
        exit;
    }

    if (!is_numeric($amount) || $amount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Amount must be a positive number.'
        ]);
        exit;
    }

    if (empty($transactionId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Transaction ID cannot be empty.'
        ]);
        exit;
    }

    // Check if order exists
    $checkOrderStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ?");
    $checkOrderStmt->execute([$orderId]);
    $existingOrder = $checkOrderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOrder) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found with ID: ' . $orderId
        ]);
        exit;
    }

    // Check for duplicate transaction_id
    $checkTransactionStmt = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ?");
    $checkTransactionStmt->execute([$transactionId]);
    $existingTransaction = $checkTransactionStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingTransaction) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Payment already exists with the same transaction ID.'
        ]);
        exit;
    }

    // Insert the payment
    $insertStmt = $pdo->prepare("
        INSERT INTO payments (order_id, ref, pay_method, amount, pay_date, transaction_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $result = $insertStmt->execute([$orderId, $ref, $payMethod, $amount, $payDate, $transactionId]);

    if ($result) {
        $paymentId = $pdo->lastInsertId();

        // Calculate total paid for this order
        $totalPaidStmt = $pdo->prepare("
            SELECT SUM(amount) as total_paid
            FROM payments
            WHERE order_id = ?
        ");
        $totalPaidStmt->execute([$orderId]);
        $totalPaidResult = $totalPaidStmt->fetch(PDO::FETCH_ASSOC);
        $totalPaid = (float)($totalPaidResult['total_paid'] ?? 0);

        // Calculate order total from order_items
        $orderTotalStmt = $pdo->prepare("
            SELECT SUM(quantity * price) as order_total
            FROM order_items
            WHERE order_id = ?
        ");
        $orderTotalStmt->execute([$orderId]);
        $orderTotalResult = $orderTotalStmt->fetch(PDO::FETCH_ASSOC);
        $orderTotal = (float)($orderTotalResult['order_total'] ?? 0);

        // Determine payment status
        $payStatus = 'unpaid';
        if ($totalPaid == 0) {
            $payStatus = 'unpaid';
        } elseif ($totalPaid > 0 && $totalPaid < $orderTotal) {
            $payStatus = 'partial paid';
        } elseif ($totalPaid == $orderTotal) {
            $payStatus = 'paid';
        } elseif ($totalPaid > $orderTotal) {
            $payStatus = 'over paid';
        }

        // Update order payment status
        $updateStatusStmt = $pdo->prepare("
            UPDATE orders
            SET pay_status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $updateStatusStmt->execute([$payStatus, $orderId]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully.',
            'data' => [
                'id' => $paymentId,
                'order_id' => $orderId,
                'ref' => $ref,
                'pay_method' => $payMethod,
                'amount' => (float)$amount,
                'pay_date' => $payDate,
                'transaction_id' => $transactionId,
                'payment_status' => $payStatus,
                'total_paid' => $totalPaid,
                'order_total' => $orderTotal,
                'due_amount' => $orderTotal - $totalPaid
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to record payment.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error recording payment: ' . $e->getMessage()
    ]);
}
?>
