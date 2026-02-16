<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Sourcing call endpoint
 * POST /orders/process.php
 * Body: { "order_id": <number>, "items": [ { "order_item_id": <number>, "type": "inhouse" | "sourcing" } ] }
 * Inserts one row per item into sourcing_calls (order_id, order_item_id, type, status pending).
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

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

if (!isset($input['order_id']) || !is_numeric($input['order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required and must be a number.']);
    exit;
}

if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'items is required and must be a non-empty array.']);
    exit;
}

$order_id = (int)$input['order_id'];
$items = $input['items'];

$seen = [];
foreach ($items as $idx => $item) {
    if (!isset($item['order_item_id']) || !is_numeric($item['order_item_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "items[{$idx}]: order_item_id is required and must be a number."]);
        exit;
    }
    if (!isset($item['type']) || !in_array($item['type'], ['inhouse', 'sourcing'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "items[{$idx}]: type must be 'inhouse' or 'sourcing'."]);
        exit;
    }
    $oid = (int)$item['order_item_id'];
    if (isset($seen[$oid])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Duplicate order_item_id in items.']);
        exit;
    }
    $seen[$oid] = true;
}

try {
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }
  
    foreach ($items as $item) {
        $order_item_id = (int)$item['order_item_id'];
        $check = $pdo->prepare("SELECT id FROM order_items WHERE id = ? AND order_id = ? LIMIT 1");
        $check->execute([$order_item_id, $order_id]);
        if (!$check->fetch()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Order item {$order_item_id} not found or does not belong to order {$order_id}."]);
            exit;
        }
    }

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare("
        UPDATE sourcing_calls SET type = ?, updated_at = NOW() WHERE order_id = ? AND order_item_id = ?
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO sourcing_calls (order_id, order_item_id, type, status)
        VALUES (?, ?, ?, 'pending')
    ");

    $rowsInserted = 0;
    $rowsUpdated = 0;
    foreach ($items as $item) {
        $order_item_id = (int)$item['order_item_id'];
        $type = $item['type'];
        $updateStmt->execute([$type, $order_id, $order_item_id]);
        if ($updateStmt->rowCount() > 0) {
            $rowsUpdated++;
        } else {
            $insertStmt->execute([$order_id, $order_item_id, $type]);
            $rowsInserted++;
        }
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Sourcing saved.',
        'data' => [
            'order_id' => $order_id,
            'rows_inserted' => $rowsInserted,
            'rows_updated' => $rowsUpdated
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_process_sourcing', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving sourcing.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_process_sourcing', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while saving sourcing.'
    ]);
}
?>
