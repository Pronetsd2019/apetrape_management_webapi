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
 * GET /mobile/v1/order/get.php?page=1&page_size=10&status=pending
 * Requires JWT authentication - returns user's orders with order items
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

// Parse and validate parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
$status = isset($_GET['status']) ? $_GET['status'] : null;

// Validate pagination parameters
if ($page < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Page must be 1 or greater.']);
    exit;
}

if ($page_size < 1 || $page_size > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Page size must be between 1 and 100.']);
    exit;
}

$offset = ($page - 1) * $page_size;

try {
    // Build WHERE conditions
    $where_conditions = ['o.user_id = ?', 'o.status != ?'];
    $params = [$user_id, 'deleted'];

    if ($status) {
        $where_conditions[] = 'o.status = ?';
        $params[] = $status;
    }

    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

    // First, get total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders o {$where_clause}");
    $countStmt->execute($params);
    $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $total_items = (int)$totalResult['total'];
    $total_pages = ceil($total_items / $page_size);

    // Get orders with pagination
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
            o.pickup_address,
            o.delivery_date
        FROM orders o
        {$where_clause}
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");

    $stmt->execute(array_merge($params, [$page_size, $offset]));
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No orders found.',
            'data' => [],
            'pagination' => [
                'current_page' => $page,
                'page_size' => $page_size,
                'total_items' => $total_items,
                'total_pages' => $total_pages,
                'has_more' => false
            ]
        ]);
        exit;
    }

    // Get order IDs for fetching order items
    $orderIds = array_column($orders, 'id');
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

    // Get unique item IDs for fetching images
    $itemIds = array_unique(array_column($orderItems, 'item_id'));
    $itemIds = array_filter($itemIds); // Remove null values

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
        $total_amount = array_sum(array_column($orderItems, 'total'));

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
            'pickup_address' => $order['pickup_address'],
            'delivery_date' => $order['delivery_date'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'order_items' => $orderItems,
            'summary' => [
                'total_items' => count($orderItems),
                'total_quantity' => (int)$total_quantity,
                'total_amount' => round($total_amount, 2)
            ]
        ];
    }

    $has_more = ($page * $page_size) < $total_items;

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Orders retrieved successfully.',
        'data' => $formatted_orders,
        'pagination' => [
            'current_page' => $page,
            'page_size' => $page_size,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'has_more' => $has_more
        ],
        'filters' => [
            'status' => $status
        ]
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
