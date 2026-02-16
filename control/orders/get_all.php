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
 * Get All Orders Endpoint
 * GET /orders/get_all.php
 *
 * Query parameters (all optional):
 * - status    Order status filter. Single value or comma-separated for multiple.
 *             Valid: pending, confirmed, processed, delivered, cancelled, deleted.
 *             Example: ?status=processed or ?status=processed,pending
 * - user_id   Filter by order owner (user id).
 * - sort      Sort by confirm_date: asc | desc (default: desc).
 * - pay_status  Filter by payment state (applied after fetch):
 *               full_paid, partial, full_and_partial, no_payment.
 *
 * Draft orders are always excluded.
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'orders', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read orders.']);
     exit;
 }

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get optional query parameters for filtering
    $statusParam = $_GET['status'] ?? null;
    $user_id = $_GET['user_id'] ?? null;
    $sort = strtolower($_GET['sort'] ?? 'desc');
    $pay_status = isset($_GET['pay_status']) ? strtolower(trim($_GET['pay_status'])) : null;

    // Validate sort parameter
    if (!in_array($sort, ['asc', 'desc'])) {
        $sort = 'desc';
    }

    // Parse status filter: single value or comma-separated (e.g. ?status=processed or ?status=processed,pending,cancelled)
    $validOrderStatuses = ['pending', 'confirmed', 'processed', 'delivered', 'cancelled', 'deleted'];
    $statuses = [];
    if ($statusParam !== null && $statusParam !== '') {
        $raw = is_array($statusParam) ? implode(',', $statusParam) : (string)$statusParam;
        $candidates = array_map('trim', explode(',', $raw));
        foreach ($candidates as $s) {
            if ($s !== '' && in_array($s, $validOrderStatuses)) {
                $statuses[] = $s;
            }
        }
        if (!empty($candidates) && empty($statuses)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status. Use one or more (comma-separated): ' . implode(', ', $validOrderStatuses)
            ]);
            exit;
        }
    }

    // Validate pay_status (payment status filter)
    $validPayStatuses = ['full_paid', 'partial', 'full_and_partial', 'no_payment'];
    if ($pay_status !== null && !in_array($pay_status, $validPayStatuses)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid pay_status. Use: full_paid, partial, full_and_partial, no_payment'
        ]);
        exit;
    }

    // Build query for orders with user information
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
    ";

    $params = [];
    $conditions = [];

    // Always exclude draft orders
    $conditions[] = "o.status != 'draft'";

    if (!empty($statuses)) {
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $conditions[] = "o.status IN ($statusPlaceholders)";
        $params = array_merge($params, $statuses);
    }

    if ($user_id) {
        $conditions[] = "o.user_id = ?";
        $params[] = $user_id;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY o.confirm_date " . strtoupper($sort);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If we have orders, get their items and calculate aggregations
    if (!empty($orders)) {
        // Get all order IDs
        $orderIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        // Fetch all order items for these orders
        $stmtItems = $pdo->prepare("
            SELECT
                id,
                sku,
                name,
                quantity,
                price,
                total,
                created_at,
                updated_at,
                order_id
            FROM order_items
            WHERE order_id IN ($placeholders)
            ORDER BY order_id ASC, id ASC
        ");
        $stmtItems->execute($orderIds);
        $allItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Group items by order_id and calculate aggregations
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

            // Track unique SKUs
            if (!in_array($item['sku'], $itemsByOrder[$orderId]['unique_skus'])) {
                $itemsByOrder[$orderId]['unique_skus'][] = $item['sku'];
                $itemsByOrder[$orderId]['unique_items_count']++;
            }
        }

        // Get payment information for all orders
        $stmtPayments = $pdo->prepare("
            SELECT
                order_id,
                SUM(amount) as total_paid
            FROM payments
            WHERE order_id IN ($placeholders)
            GROUP BY order_id
        ");
        $stmtPayments->execute($orderIds);
        $paymentsByOrder = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

        // Group payments by order_id
        $paymentsMap = [];
        foreach ($paymentsByOrder as $payment) {
            $paymentsMap[$payment['order_id']] = (float)$payment['total_paid'];
        }

        // Get delivery fees (from delivery_fee table, as in mobile order create)
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

        // Get pickup fees (from pickup_order_fees table, as in mobile order create)
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

        // Attach items, aggregations, payment info, delivery/pickup fees, and total after fees to each order
        foreach ($orders as &$order) {
            $orderId = $order['id'];
            $totalPrice = 0.0;
            $paidAmount = $paymentsMap[$orderId] ?? 0.0;
            $deliveryFeeAmount = $deliveryFeeMap[$orderId] ?? 0.0;
            $pickupFeeAmount = $pickupFeeMap[$orderId] ?? 0.0;

            // Normalize delivery_method to "collection" or "delivery"; set single address string
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

        // Filter by payment status only when pay_status is in URL; no param = return all orders
        if ($pay_status !== null) {
            $orders = array_values(array_filter($orders, function ($order) use ($pay_status) {
                $paid = (float)($order['aggregations']['paid_amount'] ?? 0);
                $total = (float)($order['aggregations']['total_amount'] ?? 0);
                switch ($pay_status) {
                    case 'full_paid':
                        return $total > 0 && $paid >= $total;
                    case 'partial':
                        return $paid > 0 && $paid < $total;
                    case 'full_and_partial':
                        return $paid > 0;
                    case 'no_payment':
                        return $paid == 0;
                    default:
                        return true;
                }
            }));
        }
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Orders retrieved successfully.',
        'data' => $orders,
        'count' => count($orders)
    ]);

} catch (PDOException $e) {
    logException('orders_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving orders: ' . $e->getMessage()
    ]);
}
?>
