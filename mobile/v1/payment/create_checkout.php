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
 * Create Peach Embedded Checkout for an order
 * POST /mobile/v1/payment/create_checkout.php
 * Body: { "order_id": 123 }
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/peach_checkout.php';
require_once __DIR__ . '/../util/auth_middleware.php';

$authUser = requireMobileJwtAuth();
$user_id = (int)($authUser['user_id'] ?? 0);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

if (!peachIsConfigured()) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Online payments are not configured yet. Please use another payment method or try again later.',
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
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'order_id is required.']);
    exit;
}

try {
    ensurePeachCheckoutsTable($pdo);

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

    // Charge only the outstanding balance so part-paid orders aren't overcharged.
    $amount = round($orderTotal - $totalPaid, 2);
    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $orderTotal > 0 ? 'Order is already paid.' : 'Order total must be greater than zero.',
        ]);
        exit;
    }

    $merchantTxId = peachMerchantTransactionId($orderId);
    $embedBase = peachEmbedBaseUrl();
    $entityId = peachEnv('PEACH_ENTITY_ID');

    $shopperResultUrl = $embedBase . '/mobile/v1/payment/result.php?order_id=' . $orderId;
    $notificationUrl = $embedBase . '/mobile/v1/payment/peach_webhook.php';
    $checkoutUrl = $embedBase . '/mobile/v1/payment/embed.php?checkoutId=';

    // Always start a fresh checkout: Peach can only render a checkout once, so a
    // previously opened (then closed or cancelled) checkout would cancel immediately.
    $checkTx = $pdo->prepare("SELECT id FROM peach_checkouts WHERE merchant_transaction_id = ?");
    $checkTx->execute([$merchantTxId]);
    $attempts = 0;
    while ($checkTx->fetch()) {
        if (++$attempts > 5) {
            throw new RuntimeException('Unable to allocate a unique merchantTransactionId for order ' . $orderId);
        }
        // "AP" + base36 order id + 6 hex chars stays within Peach's 8–16 char limit.
        $merchantTxId = substr('AP' . strtoupper(base_convert((string)$orderId, 10, 36)) . bin2hex(random_bytes(3)), 0, 16);
        $checkTx->execute([$merchantTxId]);
    }

    // Older open checkouts for this order are abandoned once a new one starts.
    $pdo->prepare("
        UPDATE peach_checkouts
        SET status = 'superseded', updated_at = NOW()
        WHERE order_id = ? AND status IN ('created', 'pending')
    ")->execute([$orderId]);

    // Only this user's own tokens may ever be offered as saved cards.
    $cardTokens = [];
    $customer = [];
    if (peachTokenisationEnabled()) {
        try {
            $cardTokens = getPeachSavedCardTokens($pdo, $user_id);
        } catch (Throwable $e) {
            logException('payment_create_checkout_saved_cards', $e);
        }

        $userStmt = $pdo->prepare("SELECT name, surname, email FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$user_id]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $customer = [
            'merchantCustomerId' => (string)$user_id,
            'givenName' => trim((string)($userRow['name'] ?? '')),
            'surname' => trim((string)($userRow['surname'] ?? '')),
            'email' => trim((string)($userRow['email'] ?? '')),
        ];
    }

    $peachResponse = createPeachCheckout([
        'amount' => $amount,
        'merchantTransactionId' => $merchantTxId,
        'shopperResultUrl' => $shopperResultUrl,
        'notificationUrl' => $notificationUrl,
        'cardTokens' => $cardTokens,
        'customer' => $customer,
    ]);

    $checkoutId = (string)$peachResponse['checkoutId'];

    $insert = $pdo->prepare("
        INSERT INTO peach_checkouts (
            order_id, checkout_id, merchant_transaction_id, amount, currency, status, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, 'created', NOW(), NOW())
    ");
    $insert->execute([
        $orderId,
        $checkoutId,
        $merchantTxId,
        $amount,
        peachCurrency(),
    ]);

    // Align order pay_method label for reporting
    $pdo->prepare("UPDATE orders SET pay_method = 'peach', updated_at = NOW() WHERE id = ?")
        ->execute([$orderId]);

    echo json_encode([
        'success' => true,
        'message' => 'Checkout created',
        'data' => [
            'order_id' => $orderId,
            'order_no' => $order['order_no'],
            'checkoutId' => $checkoutId,
            'entityId' => $entityId,
            'checkoutUrl' => $checkoutUrl . rawurlencode($checkoutId),
            'amount' => $amount,
            'currency' => peachCurrency(),
        ],
    ]);
} catch (Throwable $e) {
    logException('payment_create_checkout', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to start online payment. Please try again or choose another method.',
        'error' => $e->getMessage(),
    ]);
}
