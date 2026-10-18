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
 * MoMo Collection request-to-pay
 * POST /mobile/v1/payment/momo/request_pay.php
 * Body: { "order_id": 123, "cell": "76123456" }
 */

require_once __DIR__ . '/../../../../control/util/connect.php';
require_once __DIR__ . '/../../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../../control/util/momo_collection.php';
require_once __DIR__ . '/../../../../control/util/peach_checkout.php'; // calculateOrderPayableTotal
require_once __DIR__ . '/../../util/auth_middleware.php';

$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? 0);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

if (!momoIsConfigured()) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Mobile money is not configured yet. Please use another payment method or try again later.',
    ]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

$orderId = isset($input['order_id']) && is_numeric($input['order_id']) ? (int)$input['order_id'] : 0;
$cellRaw = isset($input['cell']) ? trim((string)$input['cell']) : '';
if ($cellRaw === '' && isset($input['msisdn'])) {
    $cellRaw = trim((string)$input['msisdn']);
}

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required.']);
    exit;
}

$msisdn = momoNormalizeMsisdn($cellRaw);
if ($msisdn === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'A valid MTN MoMo number is required (76 or 78, with or without 268).',
    ]);
    exit;
}

try {
    ensureMomoTransactionsTable($pdo);

    $orderStmt = $pdo->prepare("
        SELECT id, user_id, order_no, pay_method, pay_status
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

    $payStatus = strtolower(trim((string)($order['pay_status'] ?? '')));
    if (in_array($payStatus, ['paid', 'over paid', 'full_paid'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Order is already paid.']);
        exit;
    }

    $orderTotal = calculateOrderPayableTotal($pdo, $orderId);
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total_paid FROM payments WHERE order_id = ?");
    $paidStmt->execute([$orderId]);
    $totalPaid = (float)($paidStmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0);
    $amount = round($orderTotal - $totalPaid, 2);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nothing left to pay on this order.']);
        exit;
    }

    // Reuse an open pending request for the same order if still waiting
    $existingStmt = $pdo->prepare("
        SELECT id, external_id, reference_id, amount, currency, status, cell
        FROM momo_transactions
        WHERE order_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $existingStmt->execute([$orderId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing && in_array(momoNormalizeStatus($existing['status']), ['new', 'pending'], true)) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment already requested. Please approve on your phone.',
            'data' => [
                'order_id' => $orderId,
                'order_no' => $order['order_no'],
                'external_id' => $existing['external_id'],
                'reference_id' => $existing['reference_id'],
                'amount' => round((float)$existing['amount'], 2),
                'currency' => $existing['currency'],
                'status' => momoNormalizeStatus($existing['status']),
                'cell' => $existing['cell'],
            ],
        ]);
        exit;
    }

    $externalId = momoExternalId($orderId);
    $referenceId = momoReferenceId();
    $currency = momoCurrency();
    $amountStr = number_format($amount, 2, '.', '');

    $insert = $pdo->prepare("
        INSERT INTO momo_transactions (
            order_id, user_id, external_id, reference_id, cell,
            amount, currency, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())
    ");
    $insert->execute([
        $orderId,
        $user_id,
        $externalId,
        $referenceId,
        $msisdn['local'],
        $amount,
        $currency,
    ]);
    $txnId = (int)$pdo->lastInsertId();

    $response = createMomoRequestToPay(
        $referenceId,
        $externalId,
        $amountStr,
        $msisdn['party_id'],
        (string)$order['order_no']
    );

    $httpCode = (int)($response['http_code'] ?? 0);
    if ($httpCode !== 202) {
        updateMomoTransactionStatus($pdo, $txnId, 'failed', 'requesttopay HTTP ' . $httpCode);
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to start MoMo payment. Please try again.',
            'error_details' => 'HTTP ' . $httpCode . ' ' . substr((string)($response['raw'] ?? ''), 0, 300),
        ]);
        exit;
    }

    updateMomoTransactionStatus($pdo, $txnId, 'pending');
    $pdo->prepare("UPDATE orders SET pay_method = 'momo', updated_at = NOW() WHERE id = ?")
        ->execute([$orderId]);

    echo json_encode([
        'success' => true,
        'message' => 'Payment requested. Please approve on your phone.',
        'data' => [
            'order_id' => $orderId,
            'order_no' => $order['order_no'],
            'external_id' => $externalId,
            'reference_id' => $referenceId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'cell' => $msisdn['local'],
        ],
    ]);
} catch (Throwable $e) {
    logException('momo_request_pay', $e);

    $detail = $e->getMessage();
    $userMessage = 'Unable to start MoMo payment. Please try again later.';
    $status = 500;

    // Map known MTN/auth failures to actionable (non-secret) messages.
    if (stripos($detail, 'subscription key') !== false
        || (stripos($detail, 'MoMo token failed') !== false && stripos($detail, '401') !== false)
    ) {
        $userMessage = 'Mobile money credentials were rejected by MTN. Please check MOMO_SUBSCRIPTION_KEY and API user/key in server .env.';
        $status = 502;
    } elseif (stripos($detail, 'MoMo token') !== false) {
        $userMessage = 'Unable to authenticate with MTN MoMo. Please verify MoMo API credentials and try again.';
        $status = 502;
    } elseif (stripos($detail, 'not configured') !== false) {
        $userMessage = 'Mobile money is not configured yet. Please use another payment method or try again later.';
        $status = 503;
    }

    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $userMessage,
        'error_details' => substr($detail, 0, 300),
    ]);
}
