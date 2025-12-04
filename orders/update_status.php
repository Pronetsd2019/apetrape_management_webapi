<?php
/**
 * Update Order Status Endpoint
 * POST /orders/update_status.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

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
    if (!isset($input['order_id']) || !isset($input['status'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: order_no and status are required.'
        ]);
        exit;
    }

    $orderNo = trim($input['order_id']);
    $status = trim($input['status']);

    // Validate input
    if (empty($orderNo)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Order number cannot be empty.'
        ]);
        exit;
    }

    if (empty($status)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Status cannot be empty.'
        ]);
        exit;
    }

    // Check if order exists
    $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE id = ?");
    $checkStmt->execute([$orderNo]);
    $existingOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingOrder) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found with order number: ' . $orderNo
        ]);
        exit;
    }

    // Update the order status
    $updateStmt = $pdo->prepare("
        UPDATE orders
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$status, $orderNo]);

    if ($result && $updateStmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully.',
            'data' => [
                'order_no' => $orderNo,
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update order status. No rows were affected.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating order status: ' . $e->getMessage()
    ]);
}
?>
