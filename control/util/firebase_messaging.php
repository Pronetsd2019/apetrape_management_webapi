<?php
/**
 * Firebase Cloud Messaging (HTTP v1) helper
 *
 * Required .env variables:
 * - FIREBASE_PROJECT_ID=your-firebase-project-id
 * - FIREBASE_SERVICE_ACCOUNT_PATH=/absolute/path/to/firebase-service-account.json
 *
 * Usage (from any script):
 *   // If your script is in project root, this works:
 *   // require_once __DIR__ . '/control/util/firebase_messaging.php';
 *   //
 *   // If your script is in an endpoint folder, adjust relative path accordingly.
 *   require_once __DIR__ . '/../control/util/firebase_messaging.php';
 *   $result = sendPushNotificationToUser(
 *       123,
 *       'Order Ready',
 *       'Your order #454 is ready for pickup',
 *       ['route' => 'order', 'order_id' => '454', 'notification_id' => '999']
 *   );
 *
 * Notes:
 * - Do NOT commit your service account JSON; store it outside the repo and reference via FIREBASE_SERVICE_ACCOUNT_PATH.
 * - Data payload values must be strings; this helper will stringify scalars/arrays automatically.
 */

require_once __DIR__ . '/error_logger.php';

/**
 * Base64 URL-safe encoding (RFC 7515).
 */
function firebaseBase64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Returns a Firebase OAuth2 access token (HTTP v1).
 * Uses service-account JWT assertion flow, with a simple file cache.
 *
 * @throws RuntimeException
 */
function getFirebaseAccessToken(string $serviceAccountPath, ?string $cacheFile = null): string {
    if (!is_file($serviceAccountPath)) {
        throw new RuntimeException('Firebase service account JSON not found at: ' . $serviceAccountPath);
    }

    if ($cacheFile === null) {
        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/firebase_access_token.json';
    }

    // Try cache first (with a 60s safety margin).
    if (is_file($cacheFile)) {
        $cached = json_decode(@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['expires_at'])) {
            $expiresAt = (int)$cached['expires_at'];
            if (time() < ($expiresAt - 60)) {
                return (string)$cached['access_token'];
            }
        }
    }

    $jsonKey = json_decode(file_get_contents($serviceAccountPath), true);
    if (!is_array($jsonKey)) {
        throw new RuntimeException('Invalid JSON in Firebase service account file.');
    }
    if (empty($jsonKey['client_email']) || empty($jsonKey['private_key'])) {
        throw new RuntimeException('Firebase service account JSON missing client_email or private_key.');
    }

    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);

    $now = time();
    $payload = json_encode([
        'iss'   => $jsonKey['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]);

    $base64UrlHeader  = firebaseBase64UrlEncode($header);
    $base64UrlPayload = firebaseBase64UrlEncode($payload);
    $signatureInput = $base64UrlHeader . '.' . $base64UrlPayload;

    $signature = '';
    $signedOk = openssl_sign($signatureInput, $signature, $jsonKey['private_key'], 'sha256');
    if (!$signedOk) {
        throw new RuntimeException('Failed to sign JWT with Firebase service account private key.');
    }
    $jwt = $signatureInput . '.' . firebaseBase64UrlEncode($signature);

    // Exchange JWT for access token.
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt
    ]));

    $response = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlErrNo) {
        throw new RuntimeException('OAuth token request failed: ' . $curlErr);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('OAuth token response is not valid JSON.');
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('OAuth token request returned HTTP ' . $httpCode . ': ' . ($data['error_description'] ?? ($data['error'] ?? 'unknown_error')));
    }

    $accessToken = $data['access_token'] ?? null;
    $expiresIn = (int)($data['expires_in'] ?? 3600);
    if (!$accessToken) {
        throw new RuntimeException('OAuth token response missing access_token.');
    }

    // Save cache.
    $cachePayload = [
        'access_token' => $accessToken,
        'expires_at' => time() + max(60, $expiresIn),
        'created_at' => date('c')
    ];
    @file_put_contents($cacheFile, json_encode($cachePayload, JSON_PRETTY_PRINT), LOCK_EX);

    return $accessToken;
}

/**
 * Sends a Firebase HTTP v1 message payload.
 *
 * @return array{http_code:int, ok:bool, response_raw:string, response:?array}
 * @throws RuntimeException
 */
function sendFirebaseMessage(array $message, string $projectId, string $accessToken): array {
    if (!$projectId) {
        throw new RuntimeException('Missing FIREBASE_PROJECT_ID.');
    }
    if (!$accessToken) {
        throw new RuntimeException('Missing Firebase access token.');
    }

    $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
    $payload = ['message' => $message];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $responseRaw = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false || $curlErrNo) {
        throw new RuntimeException('FCM send failed: ' . $curlErr);
    }

    $responseJson = json_decode($responseRaw, true);
    $ok = ($httpCode >= 200 && $httpCode < 300);

    return [
        'http_code' => $httpCode,
        'ok' => $ok,
        'response_raw' => $responseRaw,
        'response' => is_array($responseJson) ? $responseJson : null
    ];
}

/**
 * Extracts Firebase v1 FCM errorCode (e.g., UNREGISTERED) from response JSON.
 */
function firebaseExtractFcmErrorCode(?array $response): ?string {
    if (!$response || empty($response['error']) || !is_array($response['error'])) {
        return null;
    }
    $details = $response['error']['details'] ?? null;
    if (!is_array($details)) {
        return null;
    }
    foreach ($details as $d) {
        if (is_array($d) && isset($d['errorCode']) && is_string($d['errorCode'])) {
            return $d['errorCode'];
        }
    }
    return null;
}

/**
 * Sends a push notification to all devices for a given user_id using tokens in user_fcm_tokens.
 *
 * - $data values will be converted to strings (Firebase requires data payload values be strings).
 * - If Firebase responds with UNREGISTERED, the token is removed from user_fcm_tokens.
 *
 * @return array{success:bool, user_id:int, sent:int, failed:int, removed:int, results:array<int,array<string,mixed>>}
 */
function sendPushNotificationToUser(int $userId, string $title, string $body, array $data = [], ?string $platform = null, ?PDO $pdo = null): array {
    // Load env (and $pdo) if not provided.
    if ($pdo === null) {
        require_once __DIR__ . '/connect.php';
        /** @var PDO $pdo */
    }

    $projectId = $_ENV['FIREBASE_PROJECT_ID'] ?? '';
    $serviceAccountPath = $_ENV['FIREBASE_SERVICE_ACCOUNT_PATH'] ?? '';

    if (!$projectId || !$serviceAccountPath) {
        $missing = [];
        if (!$projectId) $missing[] = 'FIREBASE_PROJECT_ID';
        if (!$serviceAccountPath) $missing[] = 'FIREBASE_SERVICE_ACCOUNT_PATH';
        logError('firebase_messaging', 'Missing Firebase environment variables', ['missing' => $missing]);
        return [
            'success' => false,
            'user_id' => $userId,
            'sent' => 0,
            'failed' => 0,
            'removed' => 0,
            'results' => [[
                'ok' => false,
                'error' => 'Missing required env vars: ' . implode(', ', $missing)
            ]]
        ];
    }

    // Coerce data payload values to strings.
    $dataStr = [];
    foreach ($data as $k => $v) {
        if ($v === null) continue;
        $dataStr[(string)$k] = is_scalar($v) ? (string)$v : json_encode($v);
    }

    try {
        $accessToken = getFirebaseAccessToken($serviceAccountPath);
    } catch (Throwable $e) {
        logException('firebase_messaging_get_access_token', $e, ['user_id' => $userId]);
        return [
            'success' => false,
            'user_id' => $userId,
            'sent' => 0,
            'failed' => 0,
            'removed' => 0,
            'results' => [[
                'ok' => false,
                'error' => 'Failed to generate Firebase access token: ' . $e->getMessage()
            ]]
        ];
    }

    // Fetch tokens for this user.
    $sql = "SELECT fcm_token, device_id, platform FROM user_fcm_tokens WHERE user_id = ?";
    $params = [$userId];
    if ($platform !== null && $platform !== '') {
        $sql .= " AND platform = ?";
        $params[] = strtolower(trim($platform));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return [
            'success' => true,
            'user_id' => $userId,
            'sent' => 0,
            'failed' => 0,
            'removed' => 0,
            'results' => []
        ];
    }

    $sent = 0;
    $failed = 0;
    $removed = 0;
    $results = [];

    foreach ($rows as $row) {
        $token = trim((string)($row['fcm_token'] ?? ''));
        $deviceId = $row['device_id'] ?? null;
        $rowPlatform = $row['platform'] ?? null;

        if ($token === '') {
            $failed++;
            $results[] = [
                'ok' => false,
                'device_id' => $deviceId,
                'platform' => $rowPlatform,
                'error' => 'Empty fcm_token in database'
            ];
            continue;
        }

        $message = [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $dataStr,
            'apns' => [
                'payload' => [
                    'aps' => [
                        'sound' => 'default',
                        'badge' => 1
                    ]
                ]
            ],
            'android' => [
                'priority' => 'high'
            ]
        ];

        try {
            $resp = sendFirebaseMessage($message, $projectId, $accessToken);
            if ($resp['ok']) {
                $sent++;
                $results[] = [
                    'ok' => true,
                    'device_id' => $deviceId,
                    'platform' => $rowPlatform,
                    'http_code' => $resp['http_code'],
                    'name' => $resp['response']['name'] ?? null
                ];
                continue;
            }

            $failed++;
            $errorCode = firebaseExtractFcmErrorCode($resp['response']);
            $errorMsg = $resp['response']['error']['message'] ?? 'FCM send failed';

            $results[] = [
                'ok' => false,
                'device_id' => $deviceId,
                'platform' => $rowPlatform,
                'http_code' => $resp['http_code'],
                'error_code' => $errorCode,
                'error_message' => $errorMsg,
            ];

            // Cleanup invalid tokens.
            if ($errorCode === 'UNREGISTERED') {
                $del = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE user_id = ? AND fcm_token = ? LIMIT 1");
                $del->execute([$userId, $token]);
                $removed += (int)$del->rowCount();
            }

        } catch (Throwable $e) {
            $failed++;
            logException('firebase_messaging_send', $e, [
                'user_id' => $userId,
                'device_id' => $deviceId,
                'platform' => $rowPlatform
            ]);
            $results[] = [
                'ok' => false,
                'device_id' => $deviceId,
                'platform' => $rowPlatform,
                'error' => $e->getMessage()
            ];
        }
    }

    logError('firebase_messaging_send_to_user', 'FCM send complete', [
        'user_id' => $userId,
        'sent' => $sent,
        'failed' => $failed,
        'removed' => $removed,
        'platform_filter' => $platform
    ]);

    return [
        'success' => true,
        'user_id' => $userId,
        'sent' => $sent,
        'failed' => $failed,
        'removed' => $removed,
        'results' => $results
    ];
}

