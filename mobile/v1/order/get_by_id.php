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
 * Mobile Order Get by ID Endpoint
 * GET /mobile/v1/order/get_by_id.php?order_id=123
 * Requires JWT authentication - returns single order with order items and images
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

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
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
    // Get order with validation (user ownership and not deleted)
    $orderStmt = $pdo->prepare("
        SELECT
            o.id,
            o.user_id,
            o.status,
            o.created_at,
            o.updated_at,
            o.order_no,
            o.confirm_date,
            o.pay_method,
            o.pay_status,
            o.delivery_method,
            o.delivery_address,
            o.pickup_address,
            o.delivery_date
        FROM orders o
        WHERE o.id = ? AND o.user_id = ? AND o.status != 'deleted'
    ");
    $orderStmt->execute([$order_id, $user_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found or does not belong to you.'
        ]);
        exit;
    }

    // Explicitly verify that the order's user_id matches the authenticated user from JWT
    $order_user_id = (int)$order['user_id'];
    if ($order_user_id !== $user_id) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'You do not have permission to access this order.'
        ]);
        exit;
    }

    // Get order items with item_id for image fetching
    $itemsStmt = $pdo->prepare("
        SELECT
            oi.id,
            oi.sku,
            oi.description,
            oi.quantity,
            oi.price,
            oi.total,
            oi.created_at,
            oi.updated_at,
            i.id as item_id
        FROM order_items oi
        LEFT JOIN items i ON oi.sku = i.sku
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ");
    $itemsStmt->execute([$order_id]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique item IDs for fetching images
    $itemIds = array_unique(array_column($orderItems, 'item_id'));
    $itemIds = array_values(array_filter($itemIds)); // Remove null values and reindex

    // Fetch images for all items
    $imagesByItem = [];
    if (!empty($itemIds)) {
        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));
        $imagesStmt = $pdo->prepare("
            SELECT
                item_id,
                id as image_id,
                src,
                alt as alt_text,
                created_at
            FROM item_images
            WHERE item_id IN ({$itemPlaceholders})
            ORDER BY item_id ASC, id ASC
        ");
        $imagesStmt->execute($itemIds);
        $allImages = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group images by item_id
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

    // Calculate order totals
    $total_quantity = array_sum(array_column($orderItems, 'quantity'));
    $items_total = array_sum(array_column($orderItems, 'total'));

    // Get payments for this order
    $paymentsStmt = $pdo->prepare("
        SELECT 
            `id`, 
            `order_id`, 
            `pay_method`, 
            `create_At`, 
            `amount`, 
            `pay_date`, 
            `ref`, 
            `transaction_id`
        FROM `payments`
        WHERE `order_id` = ?
        ORDER BY `create_At` ASC
    ");
    $paymentsStmt->execute([$order_id]);
    $payments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get delivery fee for this order
    $deliveryFeeStmt = $pdo->prepare("
        SELECT 
            `id`, 
            `fee`, 
            `order_id`, 
            `created_at`, 
            `updated_at`
        FROM `delivery_fee`
        WHERE `order_id` = ?
        ORDER BY `created_at` ASC
    ");
    $deliveryFeeStmt->execute([$order_id]);
    $deliveryFee = $deliveryFeeStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate totals including delivery fee
    $delivery_fee_amount = $deliveryFee ? (float)$deliveryFee['fee'] : 0;
    $total_amount = round($items_total + $delivery_fee_amount, 2);
    $total_paid = array_sum(array_column($payments, 'amount'));
    $due_amount = max(0, round($total_amount, 2) - round($total_paid, 2));

    // Format the complete order response
    $formatted_order = [
        'id' => (int)$order['id'],
        'user_id' => (int)$order['user_id'],
        'status' => $order['status'],
        'order_no' => $order['order_no'],
        'confirm_date' => $order['confirm_date'],
        'pay_method' => $order['pay_method'],
        'pay_status' => $order['pay_status'],
        'delivery_method' => $order['delivery_method'],
        'delivery_address' => $order['delivery_address'],
        'pickup_address' => $order['pickup_address'],
        'delivery_date' => $order['delivery_date'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'order_items' => $orderItems,
        'summary' => [
            'total_items' => count($orderItems),
            'total_quantity' => (int)$total_quantity,
            'total_amount' => round($total_amount, 2),
            'total_paid' => round($total_paid, 2),
            'due_amount' => round($due_amount, 2)
        ],
        'payments' => array_map(function($payment) {
            return [
                'id' => (int)$payment['id'],
                'order_id' => (int)$payment['order_id'],
                'pay_method' => $payment['pay_method'],
                'create_At' => $payment['create_At'],
                'amount' => round((float)$payment['amount'], 2),
                'pay_date' => $payment['pay_date'],
                'ref' => $payment['ref'],
                'transaction_id' => $payment['transaction_id']
            ];
        }, $payments),
        'delivery_fee' => $deliveryFee ? [
            'id' => (int)$deliveryFee['id'],
            'fee' => round((float)$deliveryFee['fee'], 2),
            'order_id' => (int)$deliveryFee['order_id'],
            'created_at' => $deliveryFee['created_at'],
            'updated_at' => $deliveryFee['updated_at']
        ] : null
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Order retrieved successfully.',
        'data' => $formatted_order
    ]);

} catch (PDOException $e) {
    logException('mobile_order_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving the order. Please try again later.',
        'error_details' => 'Error retrieving order: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_order_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving order: ' . $e->getMessage()
    ]);
}
?>
