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
 * Get order_track rows (all or for a specific order)
 * GET /orders/get_track.php
 * Required: ?order_id=<number> — filter by order.
 * Returns id, order_id, action, create_At ordered by create_At DESC.
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
    $order_id = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int) $_GET['order_id'] : null;

    if ($order_id === null || $order_id < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'order_id is required and must be a positive integer.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, order_id, action, create_At
        FROM order_track
        WHERE order_id = ?
        ORDER BY create_At DESC, id DESC
    ");
    $stmt->execute([$order_id]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data = array_map(function ($row) {
        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'action' => $row['action'],
            'create_At' => $row['create_At']
        ];
    }, $rows);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Order track retrieved successfully.',
        'data' => $data,
        'count' => count($data)
    ]);

} catch (PDOException $e) {
    logException('orders_get_track', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving order track: ' . $e->getMessage()
    ]);
}
