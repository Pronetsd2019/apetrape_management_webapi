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
 * Mobile Order Get Endpoint
 * GET /mobile/v1/order/get.php
 * Requires JWT authentication - returns all user's orders (excluding deleted) with order items
 * Orders are sorted by: pending first, then by confirm_date, with cancelled orders last
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

try {
    // Get all orders that are not deleted (simple query, no pagination, no status filter)
    $stmt = $pdo->prepare("
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
            o.pickup_point,
            o.delivery_date,
            pp.id AS pickup_point_id,
            pp.name AS pickup_point_name,
            pp.address AS pickup_point_address,
            pp.entry AS pickup_point_entry,
            pp.status AS pickup_point_status,
            pp.fee AS pickup_point_fee
        FROM orders o
        LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
        WHERE o.user_id = ? AND o.status != ?
        ORDER BY 
            CASE o.status
                WHEN 'pending' THEN 1
                WHEN 'cancelled' THEN 3
                ELSE 2
            END,
            COALESCE(o.confirm_date, o.created_at) DESC,
            o.created_at DESC
    ");
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, 'deleted', PDO::PARAM_STR);
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No orders found.',
            'data' => []
        ]);
        exit;
    }

    // Get order IDs for fetching order items
    $orderIds = array_column($orders, 'id');
    $orderItems = [];
    
    if (!empty($orderIds)) {
        $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        // Fetch order items for all retrieved orders with item_id
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
                oi.order_id,
                oi.cost,
                i.id as item_id
            FROM order_items oi
            LEFT JOIN items i ON oi.sku = i.sku
            WHERE oi.order_id IN ({$orderPlaceholders})
            ORDER BY oi.order_id ASC, oi.id ASC
        ");
        $itemsStmt->execute($orderIds);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }

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

    // Get payments for all orders
    $paymentsByOrder = [];
    if (!empty($orderIds)) {
        $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
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
            WHERE `order_id` IN ({$orderPlaceholders})
            ORDER BY `order_id` ASC, `create_At` ASC
        ");
        $paymentsStmt->execute($orderIds);
        $allPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group payments by order_id
        foreach ($allPayments as $payment) {
            $paymentsByOrder[$payment['order_id']][] = $payment;
        }
    }

    // Get delivery fees for all orders
    $deliveryFeeByOrder = [];
    if (!empty($orderIds)) {
        $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        $deliveryFeeStmt = $pdo->prepare("
            SELECT 
                `id`, 
                `cost_map_id`, 
                `fee`, 
                `order_id`, 
                `created_at`, 
                `updated_at`
            FROM `delivery_fee`
            WHERE `order_id` IN ({$orderPlaceholders})
            ORDER BY `order_id` ASC, `created_at` ASC
        ");
        $deliveryFeeStmt->execute($orderIds);
        $allDeliveryFees = $deliveryFeeStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group delivery fees by order_id (assuming one fee per order, using first if multiple)
        foreach ($allDeliveryFees as $fee) {
            if (!isset($deliveryFeeByOrder[$fee['order_id']])) {
                $deliveryFeeByOrder[$fee['order_id']] = $fee;
            }
        }
    }

    // Group order items by order_id and add images
    $itemsByOrder = [];
    foreach ($orderItems as $item) {
        $orderId = $item['order_id'];
        $itemId = $item['item_id'];
        unset($item['order_id']);
        unset($item['item_id']);

        // Add images for this item
        $item['images'] = $imagesByItem[$itemId] ?? [];

        $itemsByOrder[$orderId][] = $item;
    }

    // Format orders with their items
    $formatted_orders = [];
    foreach ($orders as $order) {
        $orderId = $order['id'];

        // Calculate order totals
        $orderItems = $itemsByOrder[$orderId] ?? [];
        $total_quantity = array_sum(array_column($orderItems, 'quantity'));
        $items_total = array_sum(array_column($orderItems, 'total'));

        // Get delivery fee for this order
        $deliveryFee = $deliveryFeeByOrder[$orderId] ?? null;
        
        // Calculate totals including delivery fee
        $delivery_fee_amount = $deliveryFee ? (float)$deliveryFee['fee'] : 0;
        $total_amount = round($items_total + $delivery_fee_amount, 2);

        // Get payments for this order and calculate totals
        $payments = $paymentsByOrder[$orderId] ?? [];
        $total_paid = array_sum(array_column($payments, 'amount'));
        $due_amount = max(0, round($total_amount, 2) - round($total_paid, 2));

        // Format pickup point details if available
        $pickup_point_data = null;
        if ($order['pickup_point']) {
            $pickup_point_data = [
                'id' => (int)$order['pickup_point_id'],
                'name' => $order['pickup_point_name'],
                'address' => $order['pickup_point_address'],
                'entry' => $order['pickup_point_entry'],
                'status' => $order['pickup_point_status'],
                'fee' => $order['pickup_point_fee'] ? round((float)$order['pickup_point_fee'], 2) : null
            ];
        }

        $formatted_orders[] = [
            'id' => (int)$order['id'],
            'user_id' => (int)$order['user_id'],
            'status' => $order['status'],
            'order_no' => $order['order_no'],
            'confirm_date' => $order['confirm_date'],
            'pay_method' => $order['pay_method'],
            'pay_status' => $order['pay_status'],
            'delivery_method' => $order['delivery_method'],
            'delivery_address' => $order['delivery_address'],
            'pickup_point' => $pickup_point_data,
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
                'cost_map_id' => $deliveryFee['cost_map_id'] ? (int)$deliveryFee['cost_map_id'] : null,
                'fee' => round((float)$deliveryFee['fee'], 2),
                'order_id' => (int)$deliveryFee['order_id'],
                'created_at' => $deliveryFee['created_at'],
                'updated_at' => $deliveryFee['updated_at']
            ] : null
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Orders retrieved successfully.',
        'data' => $formatted_orders
    ]);

} catch (PDOException $e) {
    logException('mobile_order_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving orders. Please try again later.',
        'error_details' => 'Error retrieving orders: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_order_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving orders: ' . $e->getMessage()
    ]);
}
?>
