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
 * Mobile Order Get Actions Endpoint
 * GET /mobile/v1/order/get_actions.php?order_id=123
 * Requires JWT authentication - returns action history for a specific order
 * Enforces order ownership
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
    // First, verify order exists and belongs to authenticated user
    // Also get delivery/pickup information
    $orderCheckStmt = $pdo->prepare("
        SELECT 
            o.id, 
            o.order_no, 
            o.status, 
            o.user_id,
            o.delivery_method,
            o.delivery_address,
            o.pickup_point,
            pp.id AS pickup_point_id,
            pp.name AS pickup_point_name,
            pp.address AS pickup_point_address
        FROM orders o
        LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
        WHERE o.id = ?
    ");
    $orderCheckStmt->execute([$order_id]);
    $order = $orderCheckStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order not found.'
        ]);
        exit;
    }

    // Enforce ownership - verify order belongs to authenticated user
    if ((int)$order['user_id'] !== $user_id) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'You do not have permission to view actions for this order.'
        ]);
        exit;
    }

    // Get order action history
    $actionsStmt = $pdo->prepare("
        SELECT
            id,
            order_id,
            action,
            create_At
        FROM order_track
        WHERE order_id = ?
        ORDER BY create_At ASC, id ASC
    ");
    $actionsStmt->execute([$order_id]);
    $actions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine "from" and "to" addresses
    $from_address = "Apetrape warehouse";
    $to_address = null;
    
    // Determine "to" address based on delivery method
    if ($order['delivery_method'] === 'delivery' && $order['delivery_address']) {
        $to_address = $order['delivery_address'];
    } elseif ($order['delivery_method'] === 'pickup' && $order['pickup_point_address']) {
        $to_address = $order['pickup_point_name'] . ' - ' . $order['pickup_point_address'];
    }

    // Format the response
    $formatted_actions = array_map(function($action) {
        return [
            'id' => (int)$action['id'],
            'order_id' => (int)$action['order_id'],
            'action' => $action['action'],
            'create_At' => $action['create_At']
        ];
    }, $actions);

    $message = empty($formatted_actions) 
        ? 'No actions found for this order.' 
        : 'Order actions retrieved successfully.';

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => [
            'order_id' => $order_id,
            'order_no' => $order['order_no'],
            'order_status' => $order['status'],
            'delivery_method' => $order['delivery_method'],
            'from' => $from_address,
            'to' => $to_address,
            'actions' => $formatted_actions,
            'total_actions' => count($formatted_actions)
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_order_get_actions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving order actions. Please try again later.',
        'error_details' => 'Error retrieving order actions: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_order_get_actions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving order actions: ' . $e->getMessage()
    ]);
}
?>
