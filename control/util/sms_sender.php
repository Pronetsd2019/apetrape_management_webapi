<?php
/**
 * SMS Sender Utility (RealSMS gateway)
 *
 * Requires .env:
 * - SMS_GATEWAY_URL (e.g. http://www.realsms.co.sz/urlSend)
 * - SMS_GATEWAY_API_KEY
 */

require_once __DIR__ . '/error_logger.php';
require_once __DIR__ . '/comm_logger.php';

/**
 * Sends an OTP SMS using the RealSMS gateway.
 *
 * URL shape:
 *   {SMS_GATEWAY_URL}?_apiKey={SMS_GATEWAY_API_KEY}&dest={msisdn}&message={text}
 *
 * @return array{ok:bool,http_code:int,url:string,response_raw:string,error?:string}
 */
function sendOtpSms(string $destMsisdn, string $otpCode, array $context = []): array {
    $baseUrl = $_ENV['SMS_GATEWAY_URL'] ?? '';
    $apiKey = $_ENV['SMS_GATEWAY_API_KEY'] ?? '';

    if (!$baseUrl || !$apiKey) {
        $missing = [];
        if (!$baseUrl) $missing[] = 'SMS_GATEWAY_URL';
        if (!$apiKey) $missing[] = 'SMS_GATEWAY_API_KEY';
        return [
            'ok' => false,
            'http_code' => 0,
            'url' => '',
            'response_raw' => '',
            'error' => 'Missing required env vars: ' . implode(', ', $missing)
        ];
    }

    $message = "Your OTP is {$otpCode}. Expires in 10 minutes.";

    $query = http_build_query([
        '_apiKey' => $apiKey,
        'dest' => $destMsisdn,
        'message' => $message
    ]);

    $url = rtrim($baseUrl, '?') . '?' . $query;
    // Redact API key for logs/returns
    $safeUrl = rtrim($baseUrl, '?') . '?' . http_build_query([
        '_apiKey' => 'REDACTED',
        'dest' => $destMsisdn,
        'message' => $message
    ]);

    // Log attempt (do not log OTP code separately; message contains OTP so don't log message)
    logComm('comm_sms_attempt', 'Attempting to send OTP SMS', array_merge($context, [
        'dest' => $destMsisdn,
        'url' => rtrim($baseUrl, '?') . '?_apiKey=REDACTED&dest=...&message=...'
    ]));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $responseRaw = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false || $curlErrNo) {
        logComm('comm_sms_error', 'SMS send failed (curl)', array_merge($context, [
            'dest' => $destMsisdn,
            'error' => $curlErr
        ]), 'error');
        return [
            'ok' => false,
            'http_code' => $httpCode ?: 0,
            'url' => $safeUrl,
            'response_raw' => '',
            'error' => $curlErr ?: 'SMS request failed'
        ];
    }

    $ok = ($httpCode >= 200 && $httpCode < 300);
    if (!$ok) {
        logComm('comm_sms_error', 'SMS gateway returned non-2xx', array_merge($context, [
            'dest' => $destMsisdn,
            'http_code' => $httpCode,
            'response' => $responseRaw
        ]), 'error');
    } else {
        logComm('comm_sms_success', 'OTP SMS sent successfully', array_merge($context, [
            'dest' => $destMsisdn,
            'http_code' => $httpCode
        ]));
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'url' => $safeUrl,
        'response_raw' => $responseRaw
    ];
}

/**
 * Sends an SMS with an arbitrary message using the same gateway as sendOtpSms.
 *
 * @param string $destMsisdn Destination phone number (msisdn).
 * @param string $message Message text to send.
 * @param array $context Optional context for logging.
 * @return array{ok:bool,http_code:int,url:string,response_raw:string,error?:string}
 */
function sendSms(string $destMsisdn, string $message, array $context = []): array {
    $baseUrl = $_ENV['SMS_GATEWAY_URL'] ?? '';
    $apiKey = $_ENV['SMS_GATEWAY_API_KEY'] ?? '';

    if (!$baseUrl || !$apiKey) {
        $missing = [];
        if (!$baseUrl) $missing[] = 'SMS_GATEWAY_URL';
        if (!$apiKey) $missing[] = 'SMS_GATEWAY_API_KEY';
        return [
            'ok' => false,
            'http_code' => 0,
            'url' => '',
            'response_raw' => '',
            'error' => 'Missing required env vars: ' . implode(', ', $missing)
        ];
    }

    $query = http_build_query([
        '_apiKey' => $apiKey,
        'dest' => $destMsisdn,
        'message' => $message
    ]);

    $url = rtrim($baseUrl, '?') . '?' . $query;
    $safeUrl = rtrim($baseUrl, '?') . '?' . http_build_query([
        '_apiKey' => 'REDACTED',
        'dest' => $destMsisdn,
        'message' => $message
    ]);

    logComm('comm_sms_attempt', 'Attempting to send SMS', array_merge($context, [
        'dest' => $destMsisdn,
        'url' => rtrim($baseUrl, '?') . '?_apiKey=REDACTED&dest=...&message=...'
    ]));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $responseRaw = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseRaw === false || $curlErrNo) {
        logComm('comm_sms_error', 'SMS send failed (curl)', array_merge($context, [
            'dest' => $destMsisdn,
            'error' => $curlErr
        ]), 'error');
        return [
            'ok' => false,
            'http_code' => $httpCode ?: 0,
            'url' => $safeUrl,
            'response_raw' => '',
            'error' => $curlErr ?: 'SMS request failed'
        ];
    }

    $ok = ($httpCode >= 200 && $httpCode < 300);
    if (!$ok) {
        logComm('comm_sms_error', 'SMS gateway returned non-2xx', array_merge($context, [
            'dest' => $destMsisdn,
            'http_code' => $httpCode,
            'response' => $responseRaw
        ]), 'error');
    } else {
        logComm('comm_sms_success', 'SMS sent successfully', array_merge($context, [
            'dest' => $destMsisdn,
            'http_code' => $httpCode
        ]));
    }

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'url' => $safeUrl,
        'response_raw' => $responseRaw
    ];
}

