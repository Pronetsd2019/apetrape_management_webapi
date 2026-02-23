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
 * Get sourcing calls with status = 'found'
 * GET /sourcing/get_found.php
 * Optional URL params: order_id, type (sourcing|inhouse).
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

try {
    $order_id = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int)$_GET['order_id'] : null;
    $type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : null;

    if ($type !== null && $type !== '' && !in_array($type, ['sourcing', 'inhouse'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid type. Use: sourcing or inhouse.']);
        exit;
    }

    $sql = "SELECT sc.id, sc.order_id, sc.order_item_id, sc.type, sc.status, sc.created_at, sc.updated_at,
            oi.name AS item_name, oi.sku AS item_sku, oi.quantity AS item_quantity, oi.price AS item_price
            FROM sourcing_calls sc
            LEFT JOIN order_items oi ON sc.order_item_id = oi.id
            WHERE sc.status = 'found'";
    $params = [];

    if ($order_id !== null) {
        $sql .= " AND sc.order_id = ?";
        $params[] = $order_id;
    }

    if ($type !== null && $type !== '') {
        $sql .= " AND sc.type = ?";
        $params[] = $type;
    }

    $sql .= " ORDER BY sc.updated_at DESC, sc.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
        'message' => 'Found sourcing calls retrieved.',
        'data' => $data,
        'count' => count($data)
    ]);

} catch (PDOException $e) {
    logException('sourcing_get_found', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving found sourcing calls.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('sourcing_get_found', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving found sourcing calls.'
    ]);
}
