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
 * MoMo payment status poll
 * GET /mobile/v1/payment/momo/status.php?order_id=123
 * GET /mobile/v1/payment/momo/status.php?external_id=ORD12_abcd
 */

require_once __DIR__ . '/../../../../control/util/connect.php';
require_once __DIR__ . '/../../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../../control/util/momo_collection.php';
require_once __DIR__ . '/../../util/auth_middleware.php';

$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? 0);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$externalId = isset($_GET['external_id']) ? trim((string)$_GET['external_id']) : '';

if ($orderId <= 0 && $externalId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id or external_id is required.']);
    exit;
}

try {
    ensureMomoTransactionsTable($pdo);

    if ($externalId !== '') {
        $txnStmt = $pdo->prepare("
            SELECT mt.*, o.order_no, o.pay_status, o.user_id AS order_user_id
            FROM momo_transactions mt
            INNER JOIN orders o ON o.id = mt.order_id
            WHERE mt.external_id = ?
            LIMIT 1
        ");
        $txnStmt->execute([$externalId]);
    } else {
        $txnStmt = $pdo->prepare("
            SELECT mt.*, o.order_no, o.pay_status, o.user_id AS order_user_id
            FROM momo_transactions mt
            INNER JOIN orders o ON o.id = mt.order_id
            WHERE mt.order_id = ?
            ORDER BY mt.id DESC
            LIMIT 1
        ");
        $txnStmt->execute([$orderId]);
    }

    $txn = $txnStmt->fetch(PDO::FETCH_ASSOC);
    if (!$txn || (int)$txn['order_user_id'] !== $user_id) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'MoMo transaction not found.']);
        exit;
    }

    $status = momoNormalizeStatus($txn['status']);
    $payStatus = strtolower(trim((string)($txn['pay_status'] ?? 'pending')));
    $isPaid = in_array($payStatus, ['paid', 'over paid', 'full_paid'], true);
    $recorded = null;

    if (!$isPaid && !momoIsTerminalStatus($status) && momoIsConfigured()) {
        try {
            $peachLike = getMomoPaymentStatus((string)$txn['reference_id']);
            if (($peachLike['http_code'] ?? 0) === 200 && is_array($peachLike['json'])) {
                $applied = applyMomoStatusPayload($pdo, $txn, $peachLike['json']);
                $status = $applied['status'];
                $recorded = $applied['recorded'];
                if ($recorded) {
                    $payStatus = strtolower((string)$recorded['pay_status']);
                    $isPaid = in_array($payStatus, ['paid', 'over paid'], true);
                }
                // Refresh row fields for response
                $refresh = $pdo->prepare("SELECT status, reason, financial_transaction_id, amount FROM momo_transactions WHERE id = ?");
                $refresh->execute([(int)$txn['id']]);
                $fresh = $refresh->fetch(PDO::FETCH_ASSOC) ?: [];
                $txn = array_merge($txn, $fresh);
            }
        } catch (Throwable $e) {
            error_log('MoMo status poll: ' . $e->getMessage());
        }
    }

    $failureReason = null;
    if (in_array($status, ['failed', 'rejected', 'timeout', 'cancelled', 'canceled'], true)) {
        $description = trim((string)($txn['reason'] ?? ''));
        $failureReason = $description !== '' ? $description : null;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'order_id' => (int)$txn['order_id'],
            'order_no' => $txn['order_no'],
            'pay_status' => $payStatus,
            'is_paid' => $isPaid,
            'external_id' => $txn['external_id'],
            'reference_id' => $txn['reference_id'],
            'amount' => round((float)$txn['amount'], 2),
            'currency' => $txn['currency'],
            'cell' => $txn['cell'],
            'status' => $status,
            'reason' => $txn['reason'],
            'failure_reason' => $failureReason,
            'financial_transaction_id' => $txn['financial_transaction_id'],
            'created_at' => $txn['created_at'],
            'updated_at' => $txn['updated_at'] ?? null,
        ],
    ]);
} catch (Throwable $e) {
    logException('momo_status', $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load MoMo payment status.']);
}
