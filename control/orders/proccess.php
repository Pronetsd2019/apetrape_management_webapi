<?php
ob_start();
// On fatal, show error on page
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e) return;
    if (!in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING], true)) return;
    if (ob_get_level()) ob_clean();
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Error: 1');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<h1>Fatal error</h1>';
    echo '<p><strong>' . htmlspecialchars($e['message']) . '</strong></p>';
    echo '<p>' . htmlspecialchars($e['file']) . ' on line ' . (int)$e['line'] . '</p>';
    echo '<pre>' . htmlspecialchars(print_r($e, true)) . '</pre></body></html>';
});

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once __DIR__ . '/../util/error_logger.php';


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
 * POST /orders/proccess.php
 * Body: { "order_id": <number>, "items": [ { "order_item_id": <number>, "type": "inhouse" | "sourcing" } ] }
 * Inserts one row per item into sourcing_calls (order_id, order_item_id, type, status pending).
 */

try {
require_once __DIR__ . '/../util/connect.php';
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

    $insertStmt = $pdo->prepare("
        INSERT INTO sourcing_calls (order_id, order_item_id, type, status)
        VALUES (?, ?, ?, 'pending')
    ");

    $rowsInserted = 0;
    foreach ($items as $item) {
        $insertStmt->execute([
            $order_id,
            (int)$item['order_item_id'],
            $item['type']
        ]);
        $rowsInserted++;
    }

    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Sourcing saved.',
        'data' => [
            'order_id' => $order_id,
            'rows_inserted' => $rowsInserted
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_proccess_sourcing', $e);
    if (ob_get_level()) ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<h1>Database error</h1><p><strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
    echo '<p>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></body></html>';
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_proccess_sourcing', $e);
    if (ob_get_level()) ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<h1>Error</h1><p><strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
    echo '<p>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></body></html>';
    exit;
}
} catch (Throwable $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('orders_proccess_sourcing', $e);
    if (ob_get_level()) ob_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>';
    echo '<h1>Error</h1><p><strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
    echo '<p>' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p><pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre></body></html>';
    exit;
}
