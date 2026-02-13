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
    $status = $_GET['status'] ?? null;
    $user_id = $_GET['user_id'] ?? null;
    $sort = strtolower($_GET['sort'] ?? 'desc');

    // Validate sort parameter
    if (!in_array($sort, ['asc', 'desc'])) {
        $sort = 'desc';
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
            u.name,
            u.surname,
            u.email,
            u.cell
        FROM orders o
        INNER JOIN users u ON o.user_id = u.id
    ";

    $params = [];
    $conditions = [];

    // Always exclude draft orders
    $conditions[] = "o.status != 'draft'";

    if ($status) {
        $conditions[] = "o.status = ?";
        $params[] = $status;
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

        // Attach items, aggregations, and payment info to each order
        foreach ($orders as &$order) {
            $orderId = $order['id'];
            $totalPrice = 0.0;
            $paidAmount = $paymentsMap[$orderId] ?? 0.0;

            if (isset($itemsByOrder[$orderId])) {
                $order['order_items'] = $itemsByOrder[$orderId]['items'];
                $totalPrice = $itemsByOrder[$orderId]['total_price'];
                $order['aggregations'] = [
                    'unique_items_count' => $itemsByOrder[$orderId]['unique_items_count'],
                    'total_quantity' => $itemsByOrder[$orderId]['total_quantity'],
                    'total_price' => $totalPrice,
                    'paid_amount' => $paidAmount,
                    'due_amount' => $totalPrice - $paidAmount
                ];
            } else {
                $order['order_items'] = [];
                $order['aggregations'] = [
                    'unique_items_count' => 0,
                    'total_quantity' => 0,
                    'total_price' => 0.0,
                    'paid_amount' => $paidAmount,
                    'due_amount' => 0.0 - $paidAmount
                ];
            }
        }
        unset($order);
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
