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
 * Get Orders Assigned to Me (current driver/admin)
 * GET /orders/get_assigned_to_me.php
 * Query: status (optional) — filter by assignment status (e.g. assigned, in_progress, delivered, cancelled).
 * Returns full order data for orders where assigned_to = current admin (JWT).
 * Same response shape as get_all.php.
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
    $statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : null;

    // Orders assigned to current admin; optional filter by assignment status
    if ($statusFilter !== null && $statusFilter !== '') {
        $stmtAssigned = $pdo->prepare("
            SELECT DISTINCT order_id
            FROM order_assignments
            WHERE assigned_to = ? AND status = ?
            ORDER BY order_id DESC
        ");
        $stmtAssigned->execute([$userId, $statusFilter]);
    } else {
        $stmtAssigned = $pdo->prepare("
            SELECT DISTINCT order_id
            FROM order_assignments
            WHERE assigned_to = ?
            ORDER BY order_id DESC
        ");
        $stmtAssigned->execute([$userId]);
    }
    $assignedOrderIds = array_column($stmtAssigned->fetchAll(PDO::FETCH_ASSOC), 'order_id');

    if (empty($assignedOrderIds)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Orders retrieved successfully.',
            'data' => [],
            'count' => 0
        ]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($assignedOrderIds), '?'));
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
        WHERE o.status != 'draft' AND o.id IN ($placeholders)
        ORDER BY o.confirm_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($assignedOrderIds);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    // Latest assignment (id, status) per order for current driver
    $stmtAssign = $pdo->prepare("
        SELECT id, order_id, status, updated_at
        FROM order_assignments
        WHERE order_id IN ($placeholders) AND assigned_to = ?
        ORDER BY order_id, id DESC
    ");
    $stmtAssign->execute(array_merge($orderIds, [$userId]));
    $assignRows = $stmtAssign->fetchAll(PDO::FETCH_ASSOC);
    $assignmentByOrder = [];
    foreach ($assignRows as $ar) {
        $oid = (int) $ar['order_id'];
        if (!isset($assignmentByOrder[$oid])) {
            $assignmentByOrder[$oid] = [
                'assignment_id' => (int) $ar['id'],
                'assignment_status' => $ar['status'],
                'assignment_updated_at' => $ar['updated_at']
            ];
        }
    }

    $stmtItems = $pdo->prepare("
        SELECT id, sku, name, quantity, price, total, created_at, updated_at, order_id
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY order_id ASC, id ASC
    ");
    $stmtItems->execute($orderIds);
    $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $itemsByOrder = [];
    foreach ($allItems as $item) {
        $orderId = $item['order_id'];
        if (!isset($itemsByOrder[$orderId])) {
            $itemsByOrder[$orderId] = [
                'items' => [],
                'unique_items_count' => 0,
                'total_quantity' => 0,
                'total_price' => 0.0,
                'unique_skus' => []
            ];
        }
        $itemsByOrder[$orderId]['items'][] = $item;
        $itemsByOrder[$orderId]['total_quantity'] += (int)$item['quantity'];
        $itemsByOrder[$orderId]['total_price'] += (float)$item['total'];
        if (!in_array($item['sku'], $itemsByOrder[$orderId]['unique_skus'])) {
            $itemsByOrder[$orderId]['unique_skus'][] = $item['sku'];
            $itemsByOrder[$orderId]['unique_items_count']++;
        }
    }

    $stmtPayments = $pdo->prepare("
        SELECT order_id, SUM(amount) as total_paid
        FROM payments
        WHERE order_id IN ($placeholders)
        GROUP BY order_id
    ");
    $stmtPayments->execute($orderIds);
    $paymentsByOrder = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);
    $paymentsMap = [];
    foreach ($paymentsByOrder as $p) {
        $paymentsMap[$p['order_id']] = (float)$p['total_paid'];
    }

    $stmtDeliveryFee = $pdo->prepare("
        SELECT order_id, id, cost_map_id, fee, created_at, updated_at
        FROM delivery_fee
        WHERE order_id IN ($placeholders)
    ");
    $stmtDeliveryFee->execute($orderIds);
    $deliveryFeesByOrder = $stmtDeliveryFee->fetchAll(PDO::FETCH_ASSOC);
    $deliveryFeeMap = [];
    $deliveryFeeRowByOrder = [];
    foreach ($deliveryFeesByOrder as $row) {
        $deliveryFeeMap[$row['order_id']] = (float)$row['fee'];
        $deliveryFeeRowByOrder[$row['order_id']] = $row;
    }

    $stmtPickupFee = $pdo->prepare("
        SELECT order_id, id, pickup_id, fee, create_At
        FROM pickup_order_fees
        WHERE order_id IN ($placeholders)
    ");
    $stmtPickupFee->execute($orderIds);
    $pickupFeesByOrder = $stmtPickupFee->fetchAll(PDO::FETCH_ASSOC);
    $pickupFeeMap = [];
    $pickupFeeRowByOrder = [];
    foreach ($pickupFeesByOrder as $row) {
        $pickupFeeMap[$row['order_id']] = (float)$row['fee'];
        $pickupFeeRowByOrder[$row['order_id']] = $row;
    }

    foreach ($orders as &$order) {
        $orderId = $order['id'];
        $totalPrice = 0.0;
        $paidAmount = $paymentsMap[$orderId] ?? 0.0;
        $deliveryFeeAmount = $deliveryFeeMap[$orderId] ?? 0.0;
        $pickupFeeAmount = $pickupFeeMap[$orderId] ?? 0.0;

        $dbDeliveryMethod = $order['delivery_method'] ?? null;
        $order['address'] = ($dbDeliveryMethod === 'delivery')
            ? ($order['delivery_address'] ?? '')
            : trim(($order['pickup_point_name'] ?? '') . ($order['pickup_point_address'] ? ' - ' . $order['pickup_point_address'] : ''));
        $order['delivery_method'] = ($dbDeliveryMethod === 'pickup') ? 'collection' : ($dbDeliveryMethod ?: 'delivery');

        $order['assignment_id'] = $assignmentByOrder[$orderId]['assignment_id'] ?? null;
        $order['assignment_status'] = $assignmentByOrder[$orderId]['assignment_status'] ?? null;
        $order['assignment_updated_at'] = $assignmentByOrder[$orderId]['assignment_updated_at'] ?? null;

        if (isset($itemsByOrder[$orderId])) {
            $order['order_items'] = $itemsByOrder[$orderId]['items'];
            $totalPrice = $itemsByOrder[$orderId]['total_price'];
            $totalAmount = $totalPrice + $deliveryFeeAmount + $pickupFeeAmount;
            $order['delivery_fee'] = isset($deliveryFeeRowByOrder[$orderId])
                ? [
                    'id' => (int)$deliveryFeeRowByOrder[$orderId]['id'],
                    'cost_map_id' => (int)$deliveryFeeRowByOrder[$orderId]['cost_map_id'],
                    'fee' => (float)$deliveryFeeRowByOrder[$orderId]['fee'],
                    'order_id' => (int)$deliveryFeeRowByOrder[$orderId]['order_id'],
                    'created_at' => $deliveryFeeRowByOrder[$orderId]['created_at'],
                    'updated_at' => $deliveryFeeRowByOrder[$orderId]['updated_at']
                ]
                : null;
            $order['pickup_fee'] = isset($pickupFeeRowByOrder[$orderId])
                ? [
                    'id' => (int)$pickupFeeRowByOrder[$orderId]['id'],
                    'pickup_id' => (int)$pickupFeeRowByOrder[$orderId]['pickup_id'],
                    'order_id' => (int)$pickupFeeRowByOrder[$orderId]['order_id'],
                    'fee' => (float)$pickupFeeRowByOrder[$orderId]['fee'],
                    'create_At' => $pickupFeeRowByOrder[$orderId]['create_At']
                ]
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
                ? [
                    'id' => (int)$deliveryFeeRowByOrder[$orderId]['id'],
                    'cost_map_id' => (int)$deliveryFeeRowByOrder[$orderId]['cost_map_id'],
                    'fee' => (float)$deliveryFeeRowByOrder[$orderId]['fee'],
                    'order_id' => (int)$deliveryFeeRowByOrder[$orderId]['order_id'],
                    'created_at' => $deliveryFeeRowByOrder[$orderId]['created_at'],
                    'updated_at' => $deliveryFeeRowByOrder[$orderId]['updated_at']
                ]
                : null;
            $order['pickup_fee'] = isset($pickupFeeRowByOrder[$orderId])
                ? [
                    'id' => (int)$pickupFeeRowByOrder[$orderId]['id'],
                    'pickup_id' => (int)$pickupFeeRowByOrder[$orderId]['pickup_id'],
                    'order_id' => (int)$pickupFeeRowByOrder[$orderId]['order_id'],
                    'fee' => (float)$pickupFeeRowByOrder[$orderId]['fee'],
                    'create_At' => $pickupFeeRowByOrder[$orderId]['create_At']
                ]
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
        'message' => 'Orders retrieved successfully.',
        'data' => $orders,
        'count' => count($orders)
    ]);

} catch (PDOException $e) {
    logException('orders_get_assigned_to_me', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving assigned orders: ' . $e->getMessage()
    ]);
}
