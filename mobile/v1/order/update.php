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
 * Mobile Order Update Endpoint
 * PUT /mobile/v1/order/update.php
 * Body: {
 *   "order_id": 123,
 *   "delivery_method": "pickup|delivery", // optional
 *   "delivery_address": "Address string", // optional
 *   "pickup_address": "Address string", // optional
 *   "pay_method": "cash|card|bank_transfer" // optional
 * }
 * Requires JWT authentication - updates user's order details
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

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

// Validate required fields
$order_id = (int)($input['order_id'] ?? 0);

if (!$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required.']);
    exit;
}

// Optional fields to update
$delivery_method = $input['delivery_method'] ?? null;
$delivery_address = $input['delivery_address'] ?? null;
$pickup_address = $input['pickup_address'] ?? null;
$pay_method = $input['pay_method'] ?? null;

$errors = [];

// Validate delivery_method if provided
if ($delivery_method !== null && !in_array($delivery_method, ['pickup', 'delivery'])) {
    $errors[] = 'delivery_method must be either "pickup" or "delivery"';
}

// Validate pay_method if provided
if ($pay_method !== null && !in_array($pay_method, ['cash', 'card', 'bank_transfer'])) {
    $errors[] = 'pay_method must be one of: cash, card, bank_transfer';
}

// Validate address requirements
if ($delivery_method === 'delivery' && !$delivery_address && !isset($input['delivery_address'])) {
    $errors[] = 'delivery_address is required when delivery_method is "delivery"';
}

if ($delivery_method === 'pickup' && !$pickup_address && !isset($input['pickup_address'])) {
    $errors[] = 'pickup_address is required when delivery_method is "pickup"';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Validation errors',
        'errors' => $errors
    ]);
    exit;
}

try {
    // Check if order exists and belongs to user
    $checkStmt = $pdo->prepare("
        SELECT
            o.id, o.status, o.delivery_method, o.delivery_address,
            o.pickup_address, o.pay_method
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

    // Only allow updates if order is still pending
    if ($order['status'] !== 'pending') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Order cannot be updated because it is no longer pending.'
        ]);
        exit;
    }

    // Build update query dynamically
    $updateFields = [];
    $params = [];

    if ($delivery_method !== null) {
        $updateFields[] = 'delivery_method = ?';
        $params[] = $delivery_method;
    }

    if ($delivery_address !== null) {
        $updateFields[] = 'delivery_address = ?';
        $params[] = $delivery_address;
    }

    if ($pickup_address !== null) {
        $updateFields[] = 'pickup_address = ?';
        $params[] = $pickup_address;
    }

    if ($pay_method !== null) {
        $updateFields[] = 'pay_method = ?';
        $params[] = $pay_method;
    }

    // If no fields to update, return success
    if (empty($updateFields)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No changes to update.'
        ]);
        exit;
    }

    // Add updated_at and order_id to params
    $updateFields[] = 'updated_at = NOW()';
    $params[] = $order_id;

    $updateStmt = $pdo->prepare("
        UPDATE orders
        SET " . implode(', ', $updateFields) . "
        WHERE id = ?
    ");

    $updateStmt->execute($params);

    // Get updated order
    $getOrderStmt = $pdo->prepare("
        SELECT
            o.id, o.user_id, o.status, o.created_at, o.updated_at, o.order_no,
            o.confirm_date, o.pay_method, o.pay_status, o.delivery_method,
            o.delivery_address, o.pickup_address, o.delivery_date
        FROM orders o
        WHERE o.id = ?
    ");
    $getOrderStmt->execute([$order_id]);
    $updatedOrder = $getOrderStmt->fetch(PDO::FETCH_ASSOC);

    // Get order items with item_id
    $getItemsStmt = $pdo->prepare("
        SELECT
            oi.id, oi.sku, oi.description, oi.quantity, oi.price, oi.total,
            oi.created_at, oi.updated_at, oi.cost, i.id as item_id
        FROM order_items oi
        LEFT JOIN items i ON oi.sku = i.sku
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $getItemsStmt->execute([$order_id]);
    $orderItems = $getItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique item IDs and fetch images
    $itemIds = array_unique(array_column($orderItems, 'item_id'));
    $itemIds = array_values(array_filter($itemIds)); // Remove null values and reindex

    $imagesByItem = [];
    if (!empty($itemIds)) {
        $imagesStmt = $pdo->prepare("
            SELECT
                item_id,
                id as image_id,
                src,
                alt as alt_text,
                created_at
            FROM item_images
            WHERE item_id IN (" . implode(',', array_fill(0, count($itemIds), '?')) . ")
            ORDER BY item_id ASC, id ASC
        ");
        $imagesStmt->execute($itemIds);
        $allImages = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($allImages as $image) {
            $imagesByItem[$image['item_id']][] = [
                'image_id' => (int)$image['image_id'],
                'src' => $image['src'],
                'alt_text' => $image['alt_text'],
                'created_at' => $image['created_at']
            ];
        }
    }

    // Add images to order items
    foreach ($orderItems as &$item) {
        $itemId = $item['item_id'];
        unset($item['item_id']);
        $item['images'] = $imagesByItem[$itemId] ?? [];
    }
    unset($item);

    // Calculate totals
    $total_quantity = array_sum(array_column($orderItems, 'quantity'));
    $total_amount = array_sum(array_column($orderItems, 'total'));

    $formatted_order = [
        'id' => (int)$updatedOrder['id'],
        'user_id' => (int)$updatedOrder['user_id'],
        'status' => $updatedOrder['status'],
        'order_no' => $updatedOrder['order_no'],
        'confirm_date' => $updatedOrder['confirm_date'],
        'pay_method' => $updatedOrder['pay_method'],
        'pay_status' => $updatedOrder['pay_status'],
        'delivery_method' => $updatedOrder['delivery_method'],
        'delivery_address' => $updatedOrder['delivery_address'],
        'pickup_address' => $updatedOrder['pickup_address'],
        'delivery_date' => $updatedOrder['delivery_date'],
        'created_at' => $updatedOrder['created_at'],
        'updated_at' => $updatedOrder['updated_at'],
        'order_items' => $orderItems,
        'summary' => [
            'total_items' => count($orderItems),
            'total_quantity' => (int)$total_quantity,
            'total_amount' => round($total_amount, 2)
        ]
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Order updated successfully.',
        'data' => $formatted_order
    ]);

} catch (PDOException $e) {
    logException('mobile_order_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating the order. Please try again later.',
        'error_details' => 'Error updating order: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_order_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error updating order: ' . $e->getMessage()
    ]);
}
?>
