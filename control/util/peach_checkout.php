<?php
/**
 * Peach Payments Checkout V2 helpers.
 * Credentials stay server-side — never expose to mobile clients.
 * Caller must load control/util/connect.php first when DB access is needed.
 */

/**
 * Read a Peach env var (supports PEACH_* names from .env).
 */
function peachEnv(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function peachIsConfigured(): bool
{
    return peachEnv('PEACH_CLIENT_ID')
        && peachEnv('PEACH_CLIENT_SECRET')
        && peachEnv('PEACH_MERCHANT_ID')
        && peachEnv('PEACH_ENTITY_ID');
}

function peachAuthBaseUrl(): string
{
    return rtrim(peachEnv('PEACH_AUTH_URL', 'https://sandbox-dashboard.peachpayments.com'), '/');
}

function peachCheckoutBaseUrl(): string
{
    return rtrim(peachEnv('PEACH_CHECKOUT_URL', 'https://testsecure.peachpayments.com'), '/');
}

function peachCheckoutJsUrl(): string
{
    $explicit = peachEnv('PEACH_CHECKOUT_JS_URL');
    if ($explicit) {
        return $explicit;
    }
    $checkout = peachCheckoutBaseUrl();
    if (strpos($checkout, 'testsecure') !== false || strpos($checkout, 'sandbox') !== false) {
        return 'https://sandbox-checkout.peachpayments.com/js/checkout.js';
    }
    return 'https://checkout.peachpayments.com/js/checkout.js';
}

function peachOrigin(): string
{
    return rtrim(peachEnv('PEACH_ORIGIN', peachEnv('PEACH_EMBED_BASE_URL', 'https://apetrape.com')), '/');
}

function peachEmbedBaseUrl(): string
{
    return rtrim(peachEnv('PEACH_EMBED_BASE_URL', peachOrigin()), '/');
}

function peachCurrency(): string
{
    return strtoupper(peachEnv('PEACH_CURRENCY', 'ZAR'));
}

/**
 * OAuth access token with simple file cache.
 */
function getPeachAccessToken(): string
{
    if (!peachIsConfigured()) {
        throw new RuntimeException('Peach Payments is not configured. Set PEACH_* env vars.');
    }

    $cacheFile = sys_get_temp_dir() . '/apetrape_peach_token_cache.json';
    if (is_readable($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)
            && !empty($cached['access_token'])
            && !empty($cached['expires_at'])
            && (int)$cached['expires_at'] > (time() + 60)
        ) {
            return (string)$cached['access_token'];
        }
    }

    $url = peachAuthBaseUrl() . '/api/oauth/token';
    $payload = [
        'clientId' => peachEnv('PEACH_CLIENT_ID'),
        'clientSecret' => peachEnv('PEACH_CLIENT_SECRET'),
        'merchantId' => peachEnv('PEACH_MERCHANT_ID'),
    ];

    $response = peachHttpJson('POST', $url, $payload);
    if (($response['http_code'] ?? 0) < 200 || ($response['http_code'] ?? 0) >= 300) {
        throw new RuntimeException(
            'Peach auth failed: HTTP ' . ($response['http_code'] ?? 0) . ' ' . ($response['raw'] ?? '')
        );
    }

    $body = $response['json'] ?? [];
    $token = $body['access_token'] ?? null;
    if (!$token) {
        throw new RuntimeException('Peach auth response missing access_token');
    }

    $expiresIn = (int)($body['expires_in'] ?? 3600);
    @file_put_contents($cacheFile, json_encode([
        'access_token' => $token,
        'expires_at' => time() + max(60, $expiresIn - 30),
    ]));

    return (string)$token;
}

/**
 * Create a unique merchantTransactionId (8–16 chars) for Peach.
 */
function peachMerchantTransactionId(int $orderId): string
{
    // e.g. AP00001234 (10 chars) — unique per order id
    $id = 'AP' . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT);
    if (strlen($id) > 16) {
        $id = 'AP' . substr((string)$orderId, -14);
    }
    return $id;
}

function peachGenerateNonce(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * POST /v2/checkout — returns decoded JSON body (includes checkoutId).
 *
 * @param array{
 *   amount: float|string,
 *   merchantTransactionId: string,
 *   shopperResultUrl: string,
 *   notificationUrl?: string,
 *   currency?: string,
 *   paymentType?: string
 * } $params
 */
function createPeachCheckout(array $params): array
{
    $token = getPeachAccessToken();
    $origin = peachOrigin();
    $entityId = peachEnv('PEACH_ENTITY_ID');

    $body = [
        'authentication' => [
            'entityId' => $entityId,
        ],
        'merchantTransactionId' => $params['merchantTransactionId'],
        'amount' => round((float)$params['amount'], 2),
        'currency' => $params['currency'] ?? peachCurrency(),
        'paymentType' => $params['paymentType'] ?? 'DB',
        'nonce' => peachGenerateNonce(),
        'shopperResultUrl' => $params['shopperResultUrl'],
    ];

    if (!empty($params['notificationUrl'])) {
        $body['notificationUrl'] = $params['notificationUrl'];
    }

    $url = peachCheckoutBaseUrl() . '/v2/checkout';
    $response = peachHttpJson('POST', $url, $body, [
        'Authorization: Bearer ' . $token,
        'Origin: ' . $origin,
        'Referer: ' . $origin,
    ]);

    if (($response['http_code'] ?? 0) < 200 || ($response['http_code'] ?? 0) >= 300) {
        throw new RuntimeException(
            'Peach create checkout failed: HTTP ' . ($response['http_code'] ?? 0) . ' ' . ($response['raw'] ?? '')
        );
    }

    $json = $response['json'] ?? [];
    if (empty($json['checkoutId'])) {
        throw new RuntimeException('Peach create checkout response missing checkoutId: ' . ($response['raw'] ?? ''));
    }

    return $json;
}

/**
 * Query checkout status by checkout ID.
 */
function getPeachCheckoutStatus(string $checkoutId): array
{
    $token = getPeachAccessToken();
    $origin = peachOrigin();
    $url = peachCheckoutBaseUrl() . '/v2/checkout/' . rawurlencode($checkoutId) . '/status';

    $response = peachHttpJson('GET', $url, null, [
        'Authorization: Bearer ' . $token,
        'Origin: ' . $origin,
        'Referer: ' . $origin,
    ]);

    if (($response['http_code'] ?? 0) < 200 || ($response['http_code'] ?? 0) >= 300) {
        throw new RuntimeException(
            'Peach status failed: HTTP ' . ($response['http_code'] ?? 0) . ' ' . ($response['raw'] ?? '')
        );
    }

    return $response['json'] ?? [];
}

/**
 * Verify Peach webhook HMAC signature headers.
 * message = "{timestamp}.{webhookId}.{url}.{rawBody}"
 */
function verifyPeachWebhookSignature(
    string $rawBody,
    string $fullUrl,
    ?string $timestamp,
    ?string $webhookId,
    ?string $receivedSignature
): bool {
    $secret = peachEnv('PEACH_WEBHOOK_SECRET');
    // If signing is not configured, allow in sandbox only when explicitly opted in
    if (!$secret) {
        return peachEnv('PEACH_WEBHOOK_SKIP_VERIFY', '0') === '1';
    }

    if (!$timestamp || !$webhookId || !$receivedSignature) {
        return false;
    }

    $message = $timestamp . '.' . $webhookId . '.' . $fullUrl . '.' . $rawBody;
    $calculated = hash_hmac('sha256', $message, $secret);

    return hash_equals($calculated, $receivedSignature);
}

/**
 * Ensure a pay_method row named "Peach" exists; return its id.
 */
function ensurePeachPayMethodId(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM pay_method WHERE LOWER(name) = 'peach' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $insert = $pdo->prepare("INSERT INTO pay_method (name, status, create_At) VALUES ('Peach', 1, NOW())");
    $insert->execute();
    return (int)$pdo->lastInsertId();
}

/**
 * Ensure peach_checkouts table exists (idempotent).
 */
function ensurePeachCheckoutsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS peach_checkouts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            checkout_id VARCHAR(64) NOT NULL,
            merchant_transaction_id VARCHAR(16) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'created',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_peach_checkout_id (checkout_id),
            UNIQUE KEY uk_peach_merchant_tx (merchant_transaction_id),
            KEY idx_peach_order_id (order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Record a successful Peach payment against an order (idempotent by transaction_id).
 * Returns ['inserted' => bool, 'pay_status' => string, 'payment_id' => ?int]
 */
function recordPeachOrderPayment(
    PDO $pdo,
    int $orderId,
    string $transactionId,
    string $reference,
    float $amount,
    ?string $payDate = null
): array {
    require_once __DIR__ . '/order_tracker.php';

    $payDate = $payDate ?: date('Y-m-d H:i:s');
    $payMethodId = ensurePeachPayMethodId($pdo);

    $checkTransactionStmt = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ?");
    $checkTransactionStmt->execute([$transactionId]);
    $existing = $checkTransactionStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $statusStmt = $pdo->prepare("SELECT pay_status FROM orders WHERE id = ?");
        $statusStmt->execute([$orderId]);
        $order = $statusStmt->fetch(PDO::FETCH_ASSOC);
        return [
            'inserted' => false,
            'pay_status' => $order['pay_status'] ?? 'pending',
            'payment_id' => (int)$existing['id'],
        ];
    }

    $checkOrderStmt = $pdo->prepare("SELECT id, user_id, order_no FROM orders WHERE id = ?");
    $checkOrderStmt->execute([$orderId]);
    $existingOrder = $checkOrderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingOrder) {
        throw new RuntimeException('Order not found: ' . $orderId);
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO payments (order_id, ref, pay_method, amount, pay_date, transaction_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->execute([$orderId, $reference, $payMethodId, $amount, $payDate, $transactionId]);
    $paymentId = (int)$pdo->lastInsertId();

    $totalPaidStmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE order_id = ?");
    $totalPaidStmt->execute([$orderId]);
    $totalPaid = round((float)($totalPaidStmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0), 2);

    $orderTotalStmt = $pdo->prepare("SELECT SUM(quantity * price) as order_total FROM order_items WHERE order_id = ?");
    $orderTotalStmt->execute([$orderId]);
    $itemsTotal = (float)($orderTotalStmt->fetch(PDO::FETCH_ASSOC)['order_total'] ?? 0);

    $deliveryFeeStmt = $pdo->prepare("SELECT fee FROM delivery_fee WHERE order_id = ? LIMIT 1");
    $deliveryFeeStmt->execute([$orderId]);
    $deliveryFee = (float)($deliveryFeeStmt->fetch(PDO::FETCH_ASSOC)['fee'] ?? 0);

    $pickupFeeStmt = $pdo->prepare("SELECT fee FROM pickup_order_fees WHERE order_id = ? LIMIT 1");
    $pickupFeeStmt->execute([$orderId]);
    $pickupFee = (float)($pickupFeeStmt->fetch(PDO::FETCH_ASSOC)['fee'] ?? 0);

    $orderTotal = round($itemsTotal + $deliveryFee + $pickupFee, 2);

    if ($totalPaid <= 0) {
        $payStatus = 'unpaid';
    } elseif ($totalPaid >= $orderTotal) {
        $payStatus = ($totalPaid > $orderTotal) ? 'over paid' : 'paid';
    } else {
        $payStatus = 'partial paid';
    }

    $updateStatusStmt = $pdo->prepare("UPDATE orders SET pay_status = ?, updated_at = NOW() WHERE id = ?");
    $updateStatusStmt->execute([$payStatus, $orderId]);

    $trackAction = 'Payment recived: Peach payment';
    if ($payStatus === 'partial paid') {
        $trackAction = 'Payment recived: Partial payment';
    } elseif ($payStatus === 'paid') {
        $trackAction = 'Payment recived: Full payment';
    } elseif ($payStatus === 'over paid') {
        $trackAction = 'Payment recived: Overpaid';
    }
    trackOrderAction($pdo, $orderId, $trackAction);
    if ($payStatus === 'paid' || $payStatus === 'over paid') {
        trackOrderAction($pdo, $orderId, 'order being processed');
    }

    // Best-effort push/email notifications (match admin update_payment pattern lightly)
    try {
        require_once __DIR__ . '/firebase_messaging.php';
        require_once __DIR__ . '/notification_logger.php';

        $orderOwnerUserId = (int)($existingOrder['user_id'] ?? 0);
        $orderNo = $existingOrder['order_no'] ?? (string)$orderId;
        if ($orderOwnerUserId > 0) {
            $notifTitle = 'Payment Received';
            $notifBody = "Payment received for order {$orderNo}.";
            if (function_exists('logUserNotification')) {
                $loggedIds = logUserNotification(
                    $pdo,
                    $orderOwnerUserId,
                    'payment_received',
                    $notifTitle,
                    $notifBody,
                    'order',
                    $orderId,
                    [
                        'order_id' => (string)$orderId,
                        'payment_status' => (string)$payStatus,
                        'amount' => (string)$amount,
                        'transaction_id' => (string)$transactionId,
                    ],
                    'push'
                );
            }
            if (function_exists('sendPushNotificationToUser')) {
                sendPushNotificationToUser(
                    $orderOwnerUserId,
                    $notifTitle,
                    $notifBody,
                    [
                        'route' => 'order',
                        'order_id' => (string)$orderId,
                        'payment_status' => (string)$payStatus,
                        'amount' => (string)$amount,
                        'transaction_id' => (string)$transactionId,
                    ],
                    null,
                    $pdo
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Peach payment notification error: ' . $e->getMessage());
    }

    return [
        'inserted' => true,
        'pay_status' => $payStatus,
        'payment_id' => $paymentId,
    ];
}

/**
 * HTTP helper for JSON APIs.
 *
 * @return array{http_code:int, raw:string, json:?array}
 */
function peachHttpJson(string $method, string $url, ?array $body = null, array $extraHeaders = []): array
{
    $ch = curl_init($url);
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ]);

    if ($body !== null && strtoupper($method) !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Peach HTTP error: ' . $err);
    }

    $json = json_decode($raw, true);
    return [
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

/**
 * Calculate order total (items + delivery + pickup) for checkout amount.
 */
function calculateOrderPayableTotal(PDO $pdo, int $orderId): float
{
    $orderTotalStmt = $pdo->prepare("SELECT SUM(quantity * price) as order_total FROM order_items WHERE order_id = ?");
    $orderTotalStmt->execute([$orderId]);
    $itemsTotal = (float)($orderTotalStmt->fetch(PDO::FETCH_ASSOC)['order_total'] ?? 0);

    $deliveryFeeStmt = $pdo->prepare("SELECT fee FROM delivery_fee WHERE order_id = ? LIMIT 1");
    $deliveryFeeStmt->execute([$orderId]);
    $deliveryFee = (float)($deliveryFeeStmt->fetch(PDO::FETCH_ASSOC)['fee'] ?? 0);

    $pickupFeeStmt = $pdo->prepare("SELECT fee FROM pickup_order_fees WHERE order_id = ? LIMIT 1");
    $pickupFeeStmt->execute([$orderId]);
    $pickupFee = (float)($pickupFeeStmt->fetch(PDO::FETCH_ASSOC)['fee'] ?? 0);

    return round($itemsTotal + $deliveryFee + $pickupFee, 2);
}
