<?php
/**
 * MTN MoMo Collection helpers (request-to-pay).
 * Credentials stay server-side — never expose to mobile clients.
 * Caller must load control/util/connect.php first when DB access is needed.
 */

/**
 * Read a MoMo env var.
 */
function momoEnv(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function momoIsConfigured(): bool
{
    return (bool)(momoEnv('MOMO_API_USER')
        && momoEnv('MOMO_API_KEY')
        && momoEnv('MOMO_SUBSCRIPTION_KEY'));
}

function momoBaseUrl(): string
{
    return rtrim(momoEnv('MOMO_BASE_URL', 'https://proxy.momoapi.mtn.com'), '/');
}

function momoTargetEnvironment(): string
{
    return (string)momoEnv('MOMO_TARGET_ENVIRONMENT', 'mtnswaziland');
}

function momoCurrency(): string
{
    return strtoupper((string)momoEnv('MOMO_CURRENCY', 'SZL'));
}

function momoPayerMessage(): string
{
    return (string)momoEnv('MOMO_PAYER_MESSAGE', 'APE Trape order payment');
}

/**
 * Normalize a cell to local 8-digit MTN MSISDN (76… / 78…).
 * Accepts 76…/78… (8 digits) or 26876…/26878… (11 digits), with optional + and spaces.
 *
 * @return array{local:string,party_id:string}|null
 */
function momoNormalizeMsisdn(?string $cell): ?array
{
    if ($cell === null) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $cell);
    if ($digits === null || $digits === '') {
        return null;
    }

    if (strlen($digits) === 11 && strpos($digits, '268') === 0) {
        $digits = substr($digits, 3);
    }

    if (strlen($digits) !== 8) {
        return null;
    }
    if (!preg_match('/^7[68]\d{6}$/', $digits)) {
        return null;
    }

    return [
        'local' => $digits,
        'party_id' => '268' . $digits,
    ];
}

function momoIsMtnNumber(?string $cell): bool
{
    return momoNormalizeMsisdn($cell) !== null;
}

/**
 * Collection OAuth access token with simple file cache.
 */
function getMomoAccessToken(): string
{
    if (!momoIsConfigured()) {
        throw new RuntimeException('MoMo is not configured. Set MOMO_* env vars.');
    }

    $cacheFile = sys_get_temp_dir() . '/apetrape_momo_token_cache.json';
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

    $user = momoEnv('MOMO_API_USER');
    $key = momoEnv('MOMO_API_KEY');
    $subscription = momoEnv('MOMO_SUBSCRIPTION_KEY');
    $basic = 'Basic ' . base64_encode($user . ':' . $key);

    $url = momoBaseUrl() . '/collection/token/';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_HTTPHEADER => [
            'Ocp-Apim-Subscription-Key: ' . $subscription,
            'Authorization: ' . $basic,
            'Content-Length: 0',
        ],
        CURLOPT_TIMEOUT => 45,
    ]);
    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('MoMo token HTTP error: ' . $err);
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('MoMo token failed: HTTP ' . $httpCode . ' ' . $raw);
    }

    $body = json_decode($raw, true);
    $token = is_array($body) ? ($body['access_token'] ?? null) : null;
    if (!$token) {
        throw new RuntimeException('MoMo token response missing access_token');
    }

    $expiresIn = (int)(is_array($body) ? ($body['expires_in'] ?? 3600) : 3600);
    @file_put_contents($cacheFile, json_encode([
        'access_token' => $token,
        'expires_at' => time() + max(60, $expiresIn - 30),
    ]));

    return (string)$token;
}

/**
 * Generate MoMo externalId for an order (unique, alphanumeric-ish).
 */
function momoExternalId(int $orderId): string
{
    return 'ORD' . $orderId . '_' . bin2hex(random_bytes(4));
}

function momoReferenceId(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * POST /collection/v1_0/requesttopay — expects HTTP 202.
 *
 * @return array{http_code:int, raw:string, json:?array}
 */
function createMomoRequestToPay(
    string $referenceId,
    string $externalId,
    string $amount,
    string $partyId,
    string $payeeNote = ''
): array {
    $token = getMomoAccessToken();
    $url = momoBaseUrl() . '/collection/v1_0/requesttopay';
    $body = [
        'amount' => $amount,
        'currency' => momoCurrency(),
        'externalId' => $externalId,
        'payer' => [
            'partyIdType' => 'MSISDN',
            'partyId' => $partyId,
        ],
        'payerMessage' => momoPayerMessage(),
        'payeeNote' => $payeeNote !== '' ? $payeeNote : ('Order ' . $externalId),
    ];

    return momoHttpJson('POST', $url, $body, [
        'Authorization: Bearer ' . $token,
        'X-Reference-Id: ' . $referenceId,
        'X-Target-Environment: ' . momoTargetEnvironment(),
        'Ocp-Apim-Subscription-Key: ' . momoEnv('MOMO_SUBSCRIPTION_KEY'),
    ]);
}

/**
 * GET /collection/v2_0/payment/{referenceId}
 *
 * @return array{http_code:int, raw:string, json:?array}
 */
function getMomoPaymentStatus(string $referenceId): array
{
    $token = getMomoAccessToken();
    $url = momoBaseUrl() . '/collection/v2_0/payment/' . rawurlencode($referenceId);

    return momoHttpJson('GET', $url, null, [
        'Authorization: Bearer ' . $token,
        'X-Target-Environment: ' . momoTargetEnvironment(),
        'Ocp-Apim-Subscription-Key: ' . momoEnv('MOMO_SUBSCRIPTION_KEY'),
    ]);
}

/**
 * Normalize MoMo API status strings to lowercase.
 */
function momoNormalizeStatus(?string $status): string
{
    $status = strtolower(trim((string)$status));
    if ($status === '') {
        return 'pending';
    }
    return $status;
}

function momoIsTerminalStatus(string $status): bool
{
    $status = momoNormalizeStatus($status);
    return in_array($status, ['successful', 'failed', 'rejected', 'timeout', 'cancelled', 'canceled'], true);
}

function momoIsSuccessfulStatus(string $status): bool
{
    return momoNormalizeStatus($status) === 'successful';
}

/**
 * Ensure momo_transactions table exists (idempotent).
 */
function ensureMomoTransactionsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS momo_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NOT NULL,
            external_id VARCHAR(64) NOT NULL,
            reference_id VARCHAR(64) NOT NULL,
            cell VARCHAR(16) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            currency VARCHAR(3) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            reason VARCHAR(255) NULL,
            financial_transaction_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uk_momo_external_id (external_id),
            UNIQUE KEY uk_momo_reference_id (reference_id),
            KEY idx_momo_order_id (order_id),
            KEY idx_momo_status (status),
            KEY idx_momo_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/**
 * Ensure a pay_method row named "MoMo" exists; return its id.
 */
function ensureMomoPayMethodId(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM pay_method WHERE LOWER(name) = 'momo' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id'];
    }

    $insert = $pdo->prepare("INSERT INTO pay_method (name, status, create_At) VALUES ('MoMo', 1, NOW())");
    $insert->execute();
    return (int)$pdo->lastInsertId();
}

/**
 * Update a momo_transactions row from MTN status payload fields.
 */
function updateMomoTransactionStatus(
    PDO $pdo,
    int $id,
    string $status,
    ?string $reason = null,
    ?string $financialTransactionId = null,
    ?float $amount = null
): void {
    $status = momoNormalizeStatus($status);
    $stmt = $pdo->prepare("
        UPDATE momo_transactions
        SET status = ?,
            reason = COALESCE(?, reason),
            financial_transaction_id = COALESCE(?, financial_transaction_id),
            amount = COALESCE(?, amount),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $status,
        $reason !== null && trim($reason) !== '' ? substr(trim($reason), 0, 255) : null,
        $financialTransactionId !== null && trim($financialTransactionId) !== ''
            ? substr(trim($financialTransactionId), 0, 64)
            : null,
        $amount,
        $id,
    ]);
}

/**
 * Current order pay_status helper for idempotent early returns.
 */
function momoOrderPayStatus(PDO $pdo, int $orderId): string
{
    $statusStmt = $pdo->prepare("SELECT pay_status FROM orders WHERE id = ?");
    $statusStmt->execute([$orderId]);
    $order = $statusStmt->fetch(PDO::FETCH_ASSOC);
    return (string)($order['pay_status'] ?? 'pending');
}

/**
 * Record a successful MoMo payment against an order.
 * Idempotent by payments.transaction_id and by payments.ref (MoMo external_id) on the same order,
 * so callback + status poll + hourly cron cannot insert the same payment twice.
 * Returns ['inserted' => bool, 'pay_status' => string, 'payment_id' => ?int]
 */
function recordMomoOrderPayment(
    PDO $pdo,
    int $orderId,
    string $transactionId,
    string $reference,
    float $amount,
    ?string $payDate = null
): array {
    require_once __DIR__ . '/order_tracker.php';

    $payDate = $payDate ?: date('Y-m-d H:i:s');
    $payMethodId = ensureMomoPayMethodId($pdo);
    $transactionId = trim($transactionId);
    $reference = trim($reference);

    if ($transactionId === '') {
        throw new RuntimeException('MoMo transaction_id is required to record a payment.');
    }

    // 1) Same MTN financial / synthetic transaction id already stored
    $checkTransactionStmt = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
    $checkTransactionStmt->execute([$transactionId]);
    $existing = $checkTransactionStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return [
            'inserted' => false,
            'pay_status' => momoOrderPayStatus($pdo, $orderId),
            'payment_id' => (int)$existing['id'],
        ];
    }

    // 2) Same MoMo external_id (ref) already recorded for this order
    if ($reference !== '') {
        $checkRefStmt = $pdo->prepare("
            SELECT id FROM payments
            WHERE order_id = ? AND ref = ?
            LIMIT 1
        ");
        $checkRefStmt->execute([$orderId, $reference]);
        $existingRef = $checkRefStmt->fetch(PDO::FETCH_ASSOC);
        if ($existingRef) {
            return [
                'inserted' => false,
                'pay_status' => momoOrderPayStatus($pdo, $orderId),
                'payment_id' => (int)$existingRef['id'],
            ];
        }
    }

    $checkOrderStmt = $pdo->prepare("SELECT id, user_id, order_no FROM orders WHERE id = ?");
    $checkOrderStmt->execute([$orderId]);
    $existingOrder = $checkOrderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingOrder) {
        throw new RuntimeException('Order not found: ' . $orderId);
    }

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO payments (order_id, ref, pay_method, amount, pay_date, transaction_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([$orderId, $reference, $payMethodId, $amount, $payDate, $transactionId]);
        $paymentId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // Race: another request inserted the same transaction_id between SELECT and INSERT
        $retry = $pdo->prepare("SELECT id FROM payments WHERE transaction_id = ? LIMIT 1");
        $retry->execute([$transactionId]);
        $existingRace = $retry->fetch(PDO::FETCH_ASSOC);
        if ($existingRace) {
            return [
                'inserted' => false,
                'pay_status' => momoOrderPayStatus($pdo, $orderId),
                'payment_id' => (int)$existingRace['id'],
            ];
        }
        throw $e;
    }

    if (!function_exists('calculateOrderPayableTotal')) {
        require_once __DIR__ . '/peach_checkout.php';
    }

    $totalPaidStmt = $pdo->prepare("SELECT SUM(amount) as total_paid FROM payments WHERE order_id = ?");
    $totalPaidStmt->execute([$orderId]);
    $totalPaid = round((float)($totalPaidStmt->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0), 2);
    $orderTotal = calculateOrderPayableTotal($pdo, $orderId);

    if ($totalPaid <= 0) {
        $payStatus = 'unpaid';
    } elseif ($totalPaid >= $orderTotal) {
        $payStatus = ($totalPaid > $orderTotal) ? 'over paid' : 'paid';
    } else {
        $payStatus = 'partial paid';
    }

    $updateStatusStmt = $pdo->prepare("UPDATE orders SET pay_status = ?, updated_at = NOW() WHERE id = ?");
    $updateStatusStmt->execute([$payStatus, $orderId]);

    $trackAction = 'Payment recived: MoMo payment';
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

    try {
        require_once __DIR__ . '/firebase_messaging.php';
        require_once __DIR__ . '/notification_logger.php';

        $orderOwnerUserId = (int)($existingOrder['user_id'] ?? 0);
        $orderNo = $existingOrder['order_no'] ?? (string)$orderId;
        if ($orderOwnerUserId > 0) {
            $notifTitle = 'Payment Received';
            $notifBody = "Payment received for order {$orderNo}.";
            if (function_exists('logUserNotification')) {
                logUserNotification(
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
        error_log('MoMo payment notification error: ' . $e->getMessage());
    }

    return [
        'inserted' => true,
        'pay_status' => $payStatus,
        'payment_id' => $paymentId,
    ];
}

/**
 * Apply a MoMo status payload to a transaction row and record payment if successful.
 *
 * @param array $txn momo_transactions row
 * @param array $payload decoded MTN status / callback body
 * @return array{status:string, recorded:?array}
 */
function applyMomoStatusPayload(PDO $pdo, array $txn, array $payload): array
{
    $status = momoNormalizeStatus(
        $payload['status'] ?? ($payload['Status'] ?? null)
    );
    $reason = $payload['reason'] ?? ($payload['Reason'] ?? null);
    if (is_array($reason)) {
        $reason = $reason['message'] ?? json_encode($reason);
    }
    $financialId = $payload['financialTransactionId']
        ?? ($payload['financial_transaction_id'] ?? null);
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;

    updateMomoTransactionStatus(
        $pdo,
        (int)$txn['id'],
        $status,
        is_string($reason) ? $reason : null,
        is_string($financialId) ? $financialId : null,
        $amount
    );

    $recorded = null;
    if (momoIsSuccessfulStatus($status)) {
        $txId = is_string($financialId) && $financialId !== ''
            ? $financialId
            : ('momo-' . $txn['reference_id']);
        $payAmount = $amount !== null && $amount > 0
            ? $amount
            : (float)$txn['amount'];
        $recorded = recordMomoOrderPayment(
            $pdo,
            (int)$txn['order_id'],
            $txId,
            (string)$txn['external_id'],
            $payAmount
        );
    }

    return [
        'status' => $status,
        'recorded' => $recorded,
    ];
}

/**
 * HTTP helper for MoMo JSON APIs.
 *
 * @return array{http_code:int, raw:string, json:?array}
 */
function momoHttpJson(string $method, string $url, ?array $body = null, array $extraHeaders = []): array
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
        throw new RuntimeException('MoMo HTTP error: ' . $err);
    }

    $json = json_decode($raw, true);
    return [
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}
