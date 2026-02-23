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
 * Get orders by sourcing readiness (ready / not_ready / failed)
 * GET /orders/get_by_readiness.php
 * Optional: ?readiness=ready or ?readiness=ready,failed (comma-separated). Omitted = all buckets.
 * Returns full order data in data[] with each order including a "readiness" field.
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

$validReadiness = ['ready', 'not_ready', 'failed'];
$readinessParam = $_GET['readiness'] ?? null;
$requestedBuckets = [];
if ($readinessParam !== null && $readinessParam !== '') {
    $raw = is_array($readinessParam) ? implode(',', $readinessParam) : (string)$readinessParam;
    $candidates = array_map('trim', explode(',', $raw));
    foreach ($candidates as $r) {
        if ($r !== '' && in_array($r, $validReadiness)) {
            $requestedBuckets[$r] = true;
        }
    }
    if (!empty($candidates) && empty($requestedBuckets)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid readiness. Use one or more (comma-separated): ' . implode(', ', $validReadiness)
        ]);
        exit;
    }
}
if (empty($requestedBuckets)) {
    $requestedBuckets = array_fill_keys($validReadiness, true);
}

try {
    $stmtOrders = $pdo->query("SELECT id FROM orders WHERE status != 'draft'");
    $orderIds = $stmtOrders ? array_column($stmtOrders->fetchAll(PDO::FETCH_ASSOC), 'id') : [];

    $stmtSc = $pdo->query("SELECT order_id, type, status FROM sourcing_calls");
    $sourcingRows = $stmtSc ? $stmtSc->fetchAll(PDO::FETCH_ASSOC) : [];

    $byOrder = [];
    foreach ($sourcingRows as $row) {
        $oid = (int)$row['order_id'];
        if (!isset($byOrder[$oid])) {
            $byOrder[$oid] = ['has_not_found' => false, 'has_sourcing_not_received' => false];
        }
        $type = trim((string)($row['type'] ?? ''));
        $status = trim((string)($row['status'] ?? ''));
        if ($status === 'not found') {
            $byOrder[$oid]['has_not_found'] = true;
        }
        if ($type === 'sourcing' && $status !== 'received' && $status !== 'not found') {
            $byOrder[$oid]['has_sourcing_not_received'] = true;
        }
    }

    $ready = [];
    $not_ready = [];
    $failed = [];
    $readinessByOrderId = [];

    foreach ($orderIds as $oid) {
        if (!isset($byOrder[$oid])) {
            $not_ready[] = $oid;
            $readinessByOrderId[$oid] = 'not_ready';
            continue;
        }
        $info = $byOrder[$oid];
        if ($info['has_not_found']) {
            $failed[] = $oid;
            $readinessByOrderId[$oid] = 'failed';
        } elseif ($info['has_sourcing_not_received']) {
            $not_ready[] = $oid;
            $readinessByOrderId[$oid] = 'not_ready';
        } else {
            $ready[] = $oid;
            $readinessByOrderId[$oid] = 'ready';
        }
    }

    $counts = [];
    if (!empty($requestedBuckets['ready'])) $counts['ready'] = count($ready);
    if (!empty($requestedBuckets['not_ready'])) $counts['not_ready'] = count($not_ready);
    if (!empty($requestedBuckets['failed'])) $counts['failed'] = count($failed);

    $idsToFetch = [];
    if (!empty($requestedBuckets['ready'])) $idsToFetch = array_merge($idsToFetch, $ready);
    if (!empty($requestedBuckets['not_ready'])) $idsToFetch = array_merge($idsToFetch, $not_ready);
    if (!empty($requestedBuckets['failed'])) $idsToFetch = array_merge($idsToFetch, $failed);
    $idsToFetch = array_values(array_unique($idsToFetch));

    if (empty($idsToFetch)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Orders by readiness retrieved.',
            'data' => [],
            'counts' => $counts
        ]);
    } else {
        $placeholders = implode(',', array_fill(0, count($idsToFetch), '?'));
        $sql = "
            SELECT
                o.id,
                o.user_id,
                o.status,
                o.pay_status,
                o.created_at,
                o.updated_at,
                o.order_no,
                o.confirm_date,
                o.pay_method,
                o.delivery_method,
                o.delivery_address,
                o.pickup_point,
                u.name,
                u.surname,
                u.email,
                u.cell,
                pp.name AS pickup_point_name,
                pp.address AS pickup_point_address
            FROM orders o
            INNER JOIN users u ON o.user_id = u.id
            LEFT JOIN pickup_points pp ON o.pickup_point = pp.id
            WHERE o.id IN ($placeholders)
            ORDER BY o.confirm_date DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($idsToFetch);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtItems = $pdo->prepare("
            SELECT id, sku, name, quantity, price, total, created_at, updated_at, order_id
            FROM order_items
            WHERE order_id IN ($placeholders)
            ORDER BY order_id ASC, id ASC
        ");
        $stmtItems->execute($idsToFetch);
        $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $itemsByOrder = [];
        foreach ($allItems as $item) {
            $orderId = $item['order_id'];
            if (!isset($itemsByOrder[$orderId])) {
                $itemsByOrder[$orderId] = ['items' => [], 'unique_items_count' => 0, 'total_quantity' => 0, 'total_price' => 0.0, 'unique_skus' => []];
            }
            $itemsByOrder[$orderId]['items'][] = $item;
            $itemsByOrder[$orderId]['total_quantity'] += (int)$item['quantity'];
            $itemsByOrder[$orderId]['total_price'] += (float)$item['total'];
            if (!in_array($item['sku'], $itemsByOrder[$orderId]['unique_skus'])) {
                $itemsByOrder[$orderId]['unique_skus'][] = $item['sku'];
                $itemsByOrder[$orderId]['unique_items_count']++;
            }
        }

        $stmtPayments = $pdo->prepare("SELECT order_id, SUM(amount) as total_paid FROM payments WHERE order_id IN ($placeholders) GROUP BY order_id");
        $stmtPayments->execute($idsToFetch);
        $paymentsByOrder = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
        $paymentsMap = [];
        foreach ($paymentsByOrder as $p) { $paymentsMap[$p['order_id']] = (float)$p['total_paid']; }

        $stmtDeliveryFee = $pdo->prepare("SELECT order_id, id, cost_map_id, fee, created_at, updated_at FROM delivery_fee WHERE order_id IN ($placeholders)");
        $stmtDeliveryFee->execute($idsToFetch);
        $deliveryFeesByOrder = $stmtDeliveryFee->fetchAll(PDO::FETCH_ASSOC);
        $deliveryFeeMap = [];
        $deliveryFeeRowByOrder = [];
        foreach ($deliveryFeesByOrder as $row) {
            $deliveryFeeMap[$row['order_id']] = (float)$row['fee'];
            $deliveryFeeRowByOrder[$row['order_id']] = $row;
        }

        $stmtPickupFee = $pdo->prepare("SELECT order_id, id, pickup_id, fee, create_At FROM pickup_order_fees WHERE order_id IN ($placeholders)");
        $stmtPickupFee->execute($idsToFetch);
        $pickupFeesByOrder = $stmtPickupFee->fetchAll(PDO::FETCH_ASSOC);
        $pickupFeeMap = [];
        $pickupFeeRowByOrder = [];
        foreach ($pickupFeesByOrder as $row) {
            $pickupFeeMap[$row['order_id']] = (float)$row['fee'];
            $pickupFeeRowByOrder[$row['order_id']] = $row;
        }

        foreach ($orders as &$order) {
            $orderId = $order['id'];
            $order['readiness'] = $readinessByOrderId[$orderId] ?? 'not_ready';
            $paidAmount = $paymentsMap[$orderId] ?? 0.0;
            $deliveryFeeAmount = $deliveryFeeMap[$orderId] ?? 0.0;
            $pickupFeeAmount = $pickupFeeMap[$orderId] ?? 0.0;
            $dbDeliveryMethod = $order['delivery_method'] ?? null;
            $order['address'] = ($dbDeliveryMethod === 'delivery')
                ? ($order['delivery_address'] ?? '')
                : trim(($order['pickup_point_name'] ?? '') . ($order['pickup_point_address'] ? ' - ' . $order['pickup_point_address'] : ''));
            $order['delivery_method'] = ($dbDeliveryMethod === 'pickup') ? 'collection' : ($dbDeliveryMethod ?: 'delivery');

            if (isset($itemsByOrder[$orderId])) {
                $order['order_items'] = $itemsByOrder[$orderId]['items'];
                $totalPrice = $itemsByOrder[$orderId]['total_price'];
                $totalAmount = $totalPrice + $deliveryFeeAmount + $pickupFeeAmount;
                $order['delivery_fee'] = isset($deliveryFeeRowByOrder[$orderId])
                    ? ['id' => (int)$deliveryFeeRowByOrder[$orderId]['id'], 'cost_map_id' => (int)$deliveryFeeRowByOrder[$orderId]['cost_map_id'], 'fee' => (float)$deliveryFeeRowByOrder[$orderId]['fee'], 'order_id' => (int)$deliveryFeeRowByOrder[$orderId]['order_id'], 'created_at' => $deliveryFeeRowByOrder[$orderId]['created_at'], 'updated_at' => $deliveryFeeRowByOrder[$orderId]['updated_at']]
                    : null;
                $order['pickup_fee'] = isset($pickupFeeRowByOrder[$orderId])
                    ? ['id' => (int)$pickupFeeRowByOrder[$orderId]['id'], 'pickup_id' => (int)$pickupFeeRowByOrder[$orderId]['pickup_id'], 'order_id' => (int)$pickupFeeRowByOrder[$orderId]['order_id'], 'fee' => (float)$pickupFeeRowByOrder[$orderId]['fee'], 'create_At' => $pickupFeeRowByOrder[$orderId]['create_At']]
                    : null;
                $order['aggregations'] = [
                    'unique_items_count' => $itemsByOrder[$orderId]['unique_items_count'],
                    'total_quantity' => $itemsByOrder[$orderId]['total_quantity'],
                    'total_price' => round($totalPrice, 2),
                    'delivery_fee' => round($deliveryFeeAmount, 2),
                    'pickup_fee' => round($pickupFeeAmount, 2),
                    'total_amount' => round($totalAmount, 2),
                    'paid_amount' => $paidAmount,
                    'due_amount' => round($totalAmount - $paidAmount, 2)
                ];
            } else {
                $order['order_items'] = [];
                $order['delivery_fee'] = isset($deliveryFeeRowByOrder[$orderId])
                    ? ['id' => (int)$deliveryFeeRowByOrder[$orderId]['id'], 'cost_map_id' => (int)$deliveryFeeRowByOrder[$orderId]['cost_map_id'], 'fee' => (float)$deliveryFeeRowByOrder[$orderId]['fee'], 'order_id' => (int)$deliveryFeeRowByOrder[$orderId]['order_id'], 'created_at' => $deliveryFeeRowByOrder[$orderId]['created_at'], 'updated_at' => $deliveryFeeRowByOrder[$orderId]['updated_at']]
                    : null;
                $order['pickup_fee'] = isset($pickupFeeRowByOrder[$orderId])
                    ? ['id' => (int)$pickupFeeRowByOrder[$orderId]['id'], 'pickup_id' => (int)$pickupFeeRowByOrder[$orderId]['pickup_id'], 'order_id' => (int)$pickupFeeRowByOrder[$orderId]['order_id'], 'fee' => (float)$pickupFeeRowByOrder[$orderId]['fee'], 'create_At' => $pickupFeeRowByOrder[$orderId]['create_At']]
                    : null;
                $totalAmount = $deliveryFeeAmount + $pickupFeeAmount;
                $order['aggregations'] = [
                    'unique_items_count' => 0,
                    'total_quantity' => 0,
                    'total_price' => 0.0,
                    'delivery_fee' => round($deliveryFeeAmount, 2),
                    'pickup_fee' => round($pickupFeeAmount, 2),
                    'total_amount' => round($totalAmount, 2),
                    'paid_amount' => $paidAmount,
                    'due_amount' => round($totalAmount - $paidAmount, 2)
                ];
            }
        }
        unset($order);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Orders by readiness retrieved.',
            'data' => array_values($orders),
            'counts' => $counts
        ]);
    }

} catch (PDOException $e) {
    logException('orders_get_by_readiness', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders by readiness.',
        'error_details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('orders_get_by_readiness', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders by readiness.'
    ]);
}
?>
