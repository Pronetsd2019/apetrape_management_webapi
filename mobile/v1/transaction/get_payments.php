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
 * Mobile Transaction Get Payments Endpoint
 * GET /mobile/v1/transaction/get_payments.php
 * Requires JWT authentication - returns all payments for user's orders
 * Optional query parameter: order_id (int)
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

// Get optional query parameter
$order_id = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int)$_GET['order_id'] : null;

// Validate order_id if provided
if (isset($_GET['order_id']) && !$order_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order_id parameter. Must be a numeric value.']);
    exit;
}

try {
    // Build query - JOIN to orders to enforce ownership, LEFT JOIN to pay_method for method details
    $sql = "
        SELECT
            p.id,
            p.order_id,
            p.pay_method,
            p.create_At,
            p.amount,
            p.pay_date,
            p.ref,
            p.transaction_id,
            o.order_no,
            pm.name AS pay_method_name,
            pm.status AS pay_method_status
        FROM payments p
        INNER JOIN orders o ON p.order_id = o.id
        LEFT JOIN pay_method pm ON pm.id = p.pay_method
        WHERE o.user_id = ?
    ";

    $params = [$user_id];

    // Add order_id filter if provided
    if ($order_id) {
        $sql .= " AND p.order_id = ?";
        $params[] = $order_id;
    }

    $sql .= " ORDER BY p.create_At DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the response - ensure proper type casting
    $formatted_payments = array_map(function($payment) {
        return [
            'id' => (int)$payment['id'],
            'order_id' => (int)$payment['order_id'],
            'order_no' => $payment['order_no'],
            'pay_method' => $payment['pay_method'],
            'pay_method_name' => $payment['pay_method_name'] ?: $payment['pay_method'],
            'pay_method_status' => $payment['pay_method_status'],
            'create_At' => $payment['create_At'],
            'amount' => round((float)$payment['amount'], 2),
            'pay_date' => $payment['pay_date'],
            'ref' => $payment['ref'],
            'pay_status' => 'SUCCESS',
            'transaction_id' => $payment['transaction_id'],
        ];
    }, $payments);

    $message = empty($formatted_payments) 
        ? 'No payments found.' 
        : 'Payments retrieved successfully.';

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $formatted_payments,
        'count' => count($formatted_payments)
    ]);

} catch (PDOException $e) {
    logException('mobile_transaction_get_payments', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving payments. Please try again later.',
        'error_details' => 'Error retrieving payments: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_transaction_get_payments', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving payments: ' . $e->getMessage()
    ]);
}
?>
