<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile Order Delete/Cancel Endpoint
 * DELETE /mobile/v1/order/delete.php?order_id=123
 * Requires JWT authentication - cancels user's order (soft delete)
 * Only allowed if order status is 'pending'
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Validate order_id parameter
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id parameter is required.']);
    exit;
}

try {
    // Check if order exists and belongs to user
    $checkStmt = $pdo->prepare("
        SELECT o.id, o.status, o.order_no
        FROM orders o
        WHERE o.id = ? AND o.user_id = ?
    ");
    $checkStmt->execute([$order_id, $user_id]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found or does not belong to you.'
        ]);
        exit;
    }

    // Only allow cancellation if order is still pending
    if ($order['status'] !== 'pending') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Order cannot be cancelled because it is no longer pending.'
        ]);
        exit;
    }

    // Update order status to cancelled
    $updateStmt = $pdo->prepare("
        UPDATE orders
        SET status = 'cancelled', updated_at = NOW()
        WHERE id = ?
    ");
    $updateStmt->execute([$order_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Order cancelled successfully.',
        'data' => [
            'order_id' => $order_id,
            'order_no' => $order['order_no'],
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_order_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while cancelling the order. Please try again later.',
        'error_details' => 'Error cancelling order: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_order_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error cancelling order: ' . $e->getMessage()
    ]);
}
?>
