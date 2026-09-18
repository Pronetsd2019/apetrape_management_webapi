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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Payment status for an order (mobile poll)
 * GET /mobile/v1/payment/status.php?order_id=123
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/peach_checkout.php';
require_once __DIR__ . '/../util/auth_middleware.php';

$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? 0);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required.']);
    exit;
}

try {
    ensurePeachCheckoutsTable($pdo);

    $orderStmt = $pdo->prepare("
        SELECT id, order_no, pay_method, pay_status
        FROM orders
        WHERE id = ? AND user_id = ?
        LIMIT 1
    ");
    $orderStmt->execute([$orderId, $user_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $checkoutStmt = $pdo->prepare("
        SELECT checkout_id, merchant_transaction_id, amount, currency, status, created_at, updated_at
        FROM peach_checkouts
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $checkoutStmt->execute([$orderId]);
    $checkout = $checkoutStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $payStatus = strtolower(trim((string)($order['pay_status'] ?? 'pending')));
    $isPaid = in_array($payStatus, ['paid', 'over paid', 'full_paid'], true);

    // Optional recovery: if still pending and we have a checkout, query Peach once
    if (!$isPaid && $checkout && !empty($checkout['checkout_id']) && peachIsConfigured()) {
        try {
            $peachStatus = getPeachCheckoutStatus((string)$checkout['checkout_id']);
            $code = $peachStatus['result']['code']
                ?? $peachStatus['result.code']
                ?? null;
            if (is_string($code) && preg_match('/^000\.(000|100)/', $code)) {
                $txId = $peachStatus['id']
                    ?? $peachStatus['transactionId']
                    ?? ('peach-status-' . $checkout['checkout_id']);
                $amount = isset($peachStatus['amount'])
                    ? (float)$peachStatus['amount']
                    : (float)$checkout['amount'];
                $ref = $checkout['merchant_transaction_id'] ?? ('peach-' . $checkout['checkout_id']);
                $recorded = recordPeachOrderPayment($pdo, $orderId, (string)$txId, (string)$ref, $amount);
                $payStatus = strtolower((string)$recorded['pay_status']);
                $isPaid = in_array($payStatus, ['paid', 'over paid'], true);
                $pdo->prepare("UPDATE peach_checkouts SET status = 'successful', updated_at = NOW() WHERE checkout_id = ?")
                    ->execute([$checkout['checkout_id']]);
                $checkout['status'] = 'successful';
            }
        } catch (Throwable $e) {
            // Polling must not fail the whole status endpoint
            error_log('Peach status recovery: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'order_id' => (int)$order['id'],
            'order_no' => $order['order_no'],
            'pay_method' => $order['pay_method'],
            'pay_status' => $order['pay_status'],
            'normalized_pay_status' => $payStatus,
            'is_paid' => $isPaid,
            'checkout' => $checkout,
        ],
    ]);
} catch (Throwable $e) {
    logException('payment_status', $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load payment status.']);
}
