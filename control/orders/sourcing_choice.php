<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get sourcing choice data for an order (data saved by process.php)
 * GET /orders/sourcing_choice.php?order_id=<number>
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'orders', 'read')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to read orders.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$order_id = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int)$_GET['order_id'] : null;

if ($order_id === null || $order_id < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required and must be a positive number.']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT sc.id, sc.order_id, sc.order_item_id, sc.type, sc.status, sc.created_at, sc.updated_at,
               oi.name AS item_name, oi.sku AS item_sku, oi.quantity AS item_quantity, oi.price AS item_price
        FROM sourcing_calls sc
        LEFT JOIN order_items oi ON sc.order_item_id = oi.id
        WHERE sc.order_id = ?
        ORDER BY sc.created_at ASC, sc.id ASC
    ");
    $stmt->execute([$order_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($row) {
        return [
            'id' => (int)$row['id'],
            'order_id' => (int)$row['order_id'],
            'order_item_id' => (int)$row['order_item_id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'item_name' => $row['item_name'] ?? null,
            'item_sku' => $row['item_sku'] ?? null,
            'item_quantity' => isset($row['item_quantity']) ? (int)$row['item_quantity'] : null,
            'item_price' => isset($row['item_price']) ? (float)$row['item_price'] : null
        ];
    }, $rows);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Sourcing choice retrieved.',
        'data' => [
            'order_id' => $order_id,
            'items' => $data,
            'count' => count($data)
        ]
    ]);

} catch (PDOException $e) {
    logException('orders_sourcing_choice', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving sourcing choice.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('orders_sourcing_choice', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving sourcing choice.'
    ]);
}
?>
