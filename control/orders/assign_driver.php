<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
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
 * Assign Order to Driver Endpoint
 * POST /orders/assign_driver.php
 * Body: { "order_id": <int>, "assigned_to": <int> } or { "order_ids": [<int>, ...], "assigned_to": <int> }
 * Supports single or multiple orders; each order gets its own row in order_assignments.
 * Updates each order status to 'assigned_for_delivery'.
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';
require_once __DIR__ . '/../util/order_tracker.php';

requireJwtAuth();

header('Content-Type: application/json');

$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

if (!checkUserPermission($userId, 'orders', 'update')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update orders.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['assigned_to'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Missing required field: assigned_to is required.'
        ]);
        exit;
    }

    $assigned_to = filter_var($input['assigned_to'], FILTER_VALIDATE_INT);
    if ($assigned_to === false || $assigned_to < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'assigned_to must be a positive integer.']);
        exit;
    }

    // Accept single order_id or array order_ids
    $order_ids = [];
    if (isset($input['order_ids']) && is_array($input['order_ids'])) {
        foreach ($input['order_ids'] as $id) {
            $id = filter_var($id, FILTER_VALIDATE_INT);
            if ($id !== false && $id >= 1) {
                $order_ids[] = (int) $id;
            }
        }
        $order_ids = array_values(array_unique($order_ids));
    } elseif (isset($input['order_id'])) {
        $id = filter_var($input['order_id'], FILTER_VALIDATE_INT);
        if ($id !== false && $id >= 1) {
            $order_ids[] = (int) $id;
        }
    }

    if (empty($order_ids)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Provide order_id (int) or order_ids (array of ints); at least one valid order is required.'
        ]);
        exit;
    }

    // Verify all orders exist
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $checkStmt = $pdo->prepare("SELECT id FROM orders WHERE id IN ($placeholders)");
    $checkStmt->execute($order_ids);
    $existingIds = [];
    while ($row = $checkStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingIds[] = (int) $row['id'];
    }
    $missing = array_diff($order_ids, $existingIds);
    if (!empty($missing)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Order(s) not found: ' . implode(', ', $missing)
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("UPDATE orders SET status = 'assigned_for_delivery', updated_at = NOW() WHERE id = ?");
    $insertStmt = $pdo->prepare("INSERT INTO order_assignments (order_id, assigned_to, assigned_by, status) VALUES (?, ?, ?, 'assigned')");

    $createdAt = date('Y-m-d H:i:s');
    $assignments = [];

    foreach ($order_ids as $order_id) {
        $updateStmt->execute([$order_id]);
        $insertStmt->execute([$order_id, $assigned_to, $userId]);
        trackOrderAction($pdo, $order_id, 'Order assigned for delivery');
        $assignments[] = [
            'order_id' => (int) $order_id,
            'assigned_to' => (int) $assigned_to,
            'assigned_by' => (int) $userId,
            'created_at' => $createdAt
        ];
    }

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => count($assignments) === 1
            ? 'Order assigned for delivery successfully.'
            : count($assignments) . ' orders assigned for delivery successfully.',
        'data' => [
            'assignments' => $assignments,
            'count' => count($assignments)
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_assign_driver', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error assigning order to driver: ' . $e->getMessage()
    ]);
}
