<?php
/**
 * MTN MoMo Collection callback
 * POST /mobile/v1/payment/momo/callback.php
 *
 * Public endpoint — configure this URL in the MoMo developer portal.
 */

require_once __DIR__ . '/../../../../control/util/connect.php';
require_once __DIR__ . '/../../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../../control/util/momo_collection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    // Fallback: form-urlencoded
    if (!empty($_POST)) {
        $payload = $_POST;
    } else {
        parse_str($rawBody, $parsed);
        $payload = is_array($parsed) ? $parsed : [];
    }
}

try {
    ensureMomoTransactionsTable($pdo);

    $externalId = $payload['externalId'] ?? ($payload['external_id'] ?? null);
    $referenceId = $payload['referenceId']
        ?? ($payload['X-Reference-Id'] ?? ($payload['reference_id'] ?? null));

    $txn = null;
    if (is_string($externalId) && $externalId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM momo_transactions WHERE external_id = ? LIMIT 1");
        $stmt->execute([$externalId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$txn && is_string($referenceId) && $referenceId !== '') {
        $stmt = $pdo->prepare("SELECT * FROM momo_transactions WHERE reference_id = ? LIMIT 1");
        $stmt->execute([$referenceId]);
        $txn = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$txn) {
        // Acknowledge so MTN stops retrying unknown payloads
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'Transaction not found; acknowledged']);
        exit;
    }

    $applied = applyMomoStatusPayload($pdo, $txn, $payload);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Callback processed',
        'order_id' => (int)$txn['order_id'],
        'status' => $applied['status'],
        'inserted' => $applied['recorded']['inserted'] ?? false,
    ]);
} catch (Throwable $e) {
    logException('momo_callback', $e);
    // Still 200 when possible to avoid endless MTN retries on soft errors
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Callback processing failed']);
}
