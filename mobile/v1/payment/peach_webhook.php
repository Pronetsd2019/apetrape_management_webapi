<?php
/**
 * Peach Checkout webhook
 * POST /mobile/v1/payment/peach_webhook.php
 *
 * Receives form-urlencoded (or initial JSON) notifications from Peach.
 * On successful debit: insert payments row + update orders.pay_status (idempotent).
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/peach_checkout.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

// Prefer configured public webhook URL for signature verification
$configuredUrl = peachEnv('PEACH_WEBHOOK_URL');
if ($configuredUrl) {
    $fullUrl = $configuredUrl;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'apetrape.com';
    $fullUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/mobile/v1/payment/peach_webhook.php');
    // Strip query string for signature URL matching
    $fullUrl = strtok($fullUrl, '?') ?: $fullUrl;
}

$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? null;
$webhookId = $_SERVER['HTTP_X_WEBHOOK_ID'] ?? null;
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? null;

if (!verifyPeachWebhookSignature($rawBody, $fullUrl, $timestamp, $webhookId, $signature)) {
    // Also try without trailing path quirks: some setups use embed base path
    $altUrl = rtrim(peachEmbedBaseUrl(), '/') . '/mobile/v1/payment/peach_webhook.php';
    if ($altUrl !== $fullUrl && !verifyPeachWebhookSignature($rawBody, $altUrl, $timestamp, $webhookId, $signature)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid webhook signature']);
        exit;
    }
}

// Parse body: form-urlencoded is default for Checkout; JSON for config webhook
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
$payload = [];
if (stripos($contentType, 'application/json') !== false) {
    $decoded = json_decode($rawBody, true);
    $payload = is_array($decoded) ? $decoded : [];
} else {
    parse_str($rawBody, $payload);
    if (empty($payload) && !empty($_POST)) {
        $payload = $_POST;
    }
}

// Flatten nested keys like result.code if present as array
$resultCode = $payload['result.code']
    ?? ($payload['result']['code'] ?? null)
    ?? null;
$resultDescription = $payload['result.description']
    ?? ($payload['result']['description'] ?? '')
    ?? '';

$checkoutId = $payload['checkoutId'] ?? null;
$merchantTransactionId = $payload['merchantTransactionId'] ?? null;
$amount = isset($payload['amount']) ? (float)$payload['amount'] : 0.0;
$transactionId = $payload['id']
    ?? $payload['transactionId']
    ?? $payload['uniqueId']
    ?? null;

// Determine success from result code (Peach success codes typically start with 000.000 or 000.100)
$isSuccess = false;
if (is_string($resultCode)) {
    $isSuccess = (bool)preg_match('/^000\.(000|100)/', $resultCode);
}

try {
    ensurePeachCheckoutsTable($pdo);

    $orderId = null;
    if ($checkoutId) {
        $stmt = $pdo->prepare("SELECT order_id, status FROM peach_checkouts WHERE checkout_id = ? LIMIT 1");
        $stmt->execute([$checkoutId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $orderId = (int)$row['order_id'];
        }
    }
    if (!$orderId && $merchantTransactionId) {
        $stmt = $pdo->prepare("SELECT order_id FROM peach_checkouts WHERE merchant_transaction_id = ? LIMIT 1");
        $stmt->execute([$merchantTransactionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $orderId = (int)$row['order_id'];
        }
    }

    $newStatus = 'pending';
    if ($isSuccess) {
        $newStatus = 'successful';
    } elseif (is_string($resultCode) && (strpos($resultCode, '000.200') === 0)) {
        $newStatus = 'pending';
    } elseif (stripos((string)$resultDescription, 'cancel') !== false) {
        $newStatus = 'cancelled';
    }

    if ($checkoutId) {
        $upd = $pdo->prepare("UPDATE peach_checkouts SET status = ?, updated_at = NOW() WHERE checkout_id = ?");
        $upd->execute([$newStatus, $checkoutId]);
    }

    if ($isSuccess && $orderId && $transactionId) {
        $ref = $merchantTransactionId ?: ('peach-' . $checkoutId);
        $result = recordPeachOrderPayment(
            $pdo,
            $orderId,
            (string)$transactionId,
            (string)$ref,
            $amount > 0 ? $amount : calculateOrderPayableTotal($pdo, $orderId)
        );

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Webhook processed',
            'order_id' => $orderId,
            'pay_status' => $result['pay_status'],
            'inserted' => $result['inserted'],
        ]);
        exit;
    }

    // Acknowledge non-success / incomplete payloads so Peach stops retrying soft events
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Webhook acknowledged',
        'order_id' => $orderId,
        'status' => $newStatus,
        'result_code' => $resultCode,
    ]);
} catch (Throwable $e) {
    logException('peach_webhook', $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Webhook processing failed']);
}
