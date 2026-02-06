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
 * Mobile Order Create Endpoint
 * POST /mobile/v1/order/create.php
 * Body: {
 *   "delivery_method": "pickup|delivery",
 *   "delivery_address": "Address string (if delivery)",
 *   "pickup_point_id": 2 (integer - if pickup),
 *   "pay_method": "cash|card|bank_transfer",
 *   "cost_map_id": 4 (optional - from delivery_cost_map),
 *   "delivery_fee_id": null (optional - if null and cost_map_id provided, inserts into delivery_fee)
 * }
 * Requires JWT authentication - creates order from user's cart items
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';
require_once __DIR__ . '/../util/order_tracker.php';

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

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
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
$delivery_method = $input['delivery_method'] ?? null;
$delivery_address = $input['delivery_address'] ?? null;
$pickup_point_id = isset($input['pickup_point_id']) && is_numeric($input['pickup_point_id']) ? (int)$input['pickup_point_id'] : null;
$pay_method = $input['pay_method'] ?? null;
$cost_map_id = isset($input['cost_map_id']) && is_numeric($input['cost_map_id']) ? (int)$input['cost_map_id'] : null;
$delivery_fee_id = isset($input['delivery_fee_id']) && is_numeric($input['delivery_fee_id']) ? (int)$input['delivery_fee_id'] : null;

$errors = [];

if (!$delivery_method) {
    $errors[] = 'delivery_method is required';
} elseif (!in_array($delivery_method, ['pickup', 'delivery'])) {
    $errors[] = 'delivery_method must be either "pickup" or "delivery"';
}

if (!$pay_method) {
    $errors[] = 'pay_method is required';
}

if ($delivery_method === 'delivery' && !$delivery_address) {
    $errors[] = 'delivery_address is required when delivery_method is "delivery"';
}

if ($delivery_method === 'pickup' && !$pickup_point_id) {
    $errors[] = 'pickup_point_id is required when delivery_method is "pickup"';
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
    // Start transaction
    $pdo->beginTransaction();

    // Check if user has items in cart
    $cartStmt = $pdo->prepare("
        SELECT
            c.item_id,
            c.quantity,
            i.name,
            i.sku,
            i.price,
            i.description,
            i.cost_price
        FROM cart c
        JOIN items i ON c.item_id = i.id
        WHERE c.user_id = ?
    ");
    $cartStmt->execute([$user_id]);
    $cartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cartItems)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No items in cart. Cannot create order.'
        ]);
        exit;
    }

    // Generate order number
    $order_no = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

    // Check if order_no is unique
    $checkOrderStmt = $pdo->prepare("SELECT id FROM orders WHERE order_no = ?");
    $checkOrderStmt->execute([$order_no]);
    while ($checkOrderStmt->fetch()) {
        $order_no = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $checkOrderStmt->execute([$order_no]);
    }

    // Insert order
    $orderStmt = $pdo->prepare("
        INSERT INTO orders (
            user_id, status, created_at, updated_at, order_no,
            pay_method, pay_status, delivery_method, delivery_address, pickup_point
        ) VALUES (?, 'pending', NOW(), NOW(), ?, ?, 'pending', ?, ?, ?)
    ");

    $orderStmt->execute([
        $user_id,
        $order_no,
        $pay_method,
        $delivery_method,
        $delivery_address,
        $pickup_point_id
    ]);

    $order_id = $pdo->lastInsertId();

    // Track order creation
    trackOrderAction($pdo, $order_id, 'Order created');

    // Insert order items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (
            sku, description, quantity, price, total, created_at, updated_at, order_id, cost
        ) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)
    ");

    foreach ($cartItems as $item) {
        $total = $item['price'] * $item['quantity'];

        $itemStmt->execute([
            $item['sku'],
            $item['description'] ?: $item['name'], // Use name if no description
            $item['quantity'],
            $item['price'],
            $total,
            $order_id,
            $item['cost_price']
        ]);
    }

    // Handle delivery fee insertion if delivery_fee_id is null but cost_map_id is provided
    if ($delivery_fee_id === null && $cost_map_id !== null) {
        // Fetch the fee amount from delivery_cost_map
        $costMapStmt = $pdo->prepare("
            SELECT amount
            FROM delivery_cost_map
            WHERE id = ?
            LIMIT 1
        ");
        $costMapStmt->execute([$cost_map_id]);
        $costMapRow = $costMapStmt->fetch(PDO::FETCH_ASSOC);

        if ($costMapRow) {
            $fee_amount = (float)$costMapRow['amount'];

            // Insert into delivery_fee table
            $deliveryFeeStmt = $pdo->prepare("
                INSERT INTO delivery_fee (cost_map_id, fee, order_id, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
            ");
            $deliveryFeeStmt->execute([$cost_map_id, $fee_amount, $order_id]);
            $delivery_fee_id = $pdo->lastInsertId();
        }
    }

    // Handle pickup fee insertion if order is pickup
    if ($delivery_method === 'pickup' && $pickup_point_id !== null) {
        // Fetch the fee from pickup_points table
        $pickupPointStmt = $pdo->prepare("
            SELECT fee
            FROM pickup_points
            WHERE id = ? AND status = 1
            LIMIT 1
        ");
        $pickupPointStmt->execute([$pickup_point_id]);
        $pickupPointRow = $pickupPointStmt->fetch(PDO::FETCH_ASSOC);

        if ($pickupPointRow) {
            $pickup_fee = (float)$pickupPointRow['fee'];

            // Insert into pickup_order_fees table
            $pickupFeeStmt = $pdo->prepare("
                INSERT INTO pickup_order_fees (pickup_id, order_id, fee, create_At)
                VALUES (?, ?, ?, NOW())
            ");
            $pickupFeeStmt->execute([$pickup_point_id, $order_id, $pickup_fee]);
        }
    }

    // Clear user's cart
    $clearCartStmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $clearCartStmt->execute([$user_id]);

    // Commit transaction
    $pdo->commit();


    // Get order and order items with item_id
    $getOrderStmt = $pdo->prepare("
        SELECT
            o.id, o.user_id, o.status, o.created_at, o.updated_at, o.order_no,
            o.confirm_date, o.pay_method, o.pay_status, o.delivery_method,
            o.delivery_address, o.pickup_point, o.delivery_date
        FROM orders o
        WHERE o.id = ?
    ");
    $getOrderStmt->execute([$order_id]);
    $order = $getOrderStmt->fetch(PDO::FETCH_ASSOC);

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

    // Fetch delivery fee if exists
    $deliveryFeeStmt = $pdo->prepare("
        SELECT id, cost_map_id, fee, order_id, created_at, updated_at
        FROM delivery_fee
        WHERE order_id = ?
        LIMIT 1
    ");
    $deliveryFeeStmt->execute([$order_id]);
    $deliveryFeeRow = $deliveryFeeStmt->fetch(PDO::FETCH_ASSOC);

    $deliveryFee = null;
    $deliveryFeeAmount = 0;
    if ($deliveryFeeRow) {
        $deliveryFee = [
            'id' => (int)$deliveryFeeRow['id'],
            'cost_map_id' => (int)$deliveryFeeRow['cost_map_id'],
            'fee' => (float)$deliveryFeeRow['fee'],
            'order_id' => (int)$deliveryFeeRow['order_id'],
            'created_at' => $deliveryFeeRow['created_at'],
            'updated_at' => $deliveryFeeRow['updated_at']
        ];
        $deliveryFeeAmount = (float)$deliveryFeeRow['fee'];
    }

    // Fetch pickup fee if exists
    $pickupFeeStmt = $pdo->prepare("
        SELECT id, pickup_id, order_id, fee, create_At
        FROM pickup_order_fees
        WHERE order_id = ?
        LIMIT 1
    ");
    $pickupFeeStmt->execute([$order_id]);
    $pickupFeeRow = $pickupFeeStmt->fetch(PDO::FETCH_ASSOC);

    $pickupFee = null;
    $pickupFeeAmount = 0;
    if ($pickupFeeRow) {
        $pickupFee = [
            'id' => (int)$pickupFeeRow['id'],
            'pickup_id' => (int)$pickupFeeRow['pickup_id'],
            'order_id' => (int)$pickupFeeRow['order_id'],
            'fee' => (float)$pickupFeeRow['fee'],
            'create_At' => $pickupFeeRow['create_At']
        ];
        $pickupFeeAmount = (float)$pickupFeeRow['fee'];
    }

    // Calculate totals
    $total_quantity = array_sum(array_column($orderItems, 'quantity'));
    $items_total = array_sum(array_column($orderItems, 'total'));
    $total_amount = $items_total + $deliveryFeeAmount + $pickupFeeAmount;

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
        'pickup_point' => $order['pickup_point'] ? (int)$order['pickup_point'] : null,
        'delivery_date' => $order['delivery_date'],
        'created_at' => $order['created_at'],
        'updated_at' => $order['updated_at'],
        'order_items' => $orderItems,
        'delivery_fee' => $deliveryFee,
        'pickup_fee' => $pickupFee,
        'summary' => [
            'total_items' => count($orderItems),
            'total_quantity' => (int)$total_quantity,
            'items_total' => round($items_total, 2),
            'delivery_fee' => round($deliveryFeeAmount, 2),
            'pickup_fee' => round($pickupFeeAmount, 2),
            'total_amount' => round($total_amount, 2)
        ]
    ];

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Order created successfully.',
        'data' => $formatted_order
    ]);

} catch (PDOException $e) {
    $pdo->rollBack();
    logException('mobile_order_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while creating the order. Please try again later.',
        'error_details' => 'Error creating order: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    logException('mobile_order_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error creating order: ' . $e->getMessage()
    ]);
}
?>
