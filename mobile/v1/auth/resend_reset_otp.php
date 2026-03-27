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
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Resend Password Reset OTP
 * POST /mobile/v1/auth/resend_reset_otp.php
 * Body: { "identifier": "email@example.com" } OR { "identifier": "+26876000000" }
 *
 * Always returns a generic 200 success for non-existing users to avoid account enumeration.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../../../control/util/otp_store.php';
require_once __DIR__ . '/../../../control/util/email_sender.php';
require_once __DIR__ . '/../../../control/util/sms_sender.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

define('RESEND_RESET_SUCCESS_RESPONSE', json_encode([
    'success' => true,
    'message' => 'If an account with that identifier exists, a code has been sent.'
]));

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

function logResendResetAttempt(array $data): void {
    $logFile = dirname(__DIR__, 4) . '/control/logs/forgot_password_attempts.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $entry = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$identifier = isset($input['identifier']) ? trim((string)$input['identifier']) : '';

if ($identifier === '') {
    logResendResetAttempt([
        'endpoint' => 'resend_reset_otp',
        'identifier' => '',
        'channel' => null,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'user_found' => false,
        'otp_sent' => false,
        'note' => 'empty identifier'
    ]);
    http_response_code(200);
    echo RESEND_RESET_SUCCESS_RESPONSE;
    exit;
}

$channel = null;
$email = null;
$phone = null;

if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
    $channel = 'email';
    $email = $identifier;
} else {
    $stripped = preg_replace('/\s+/', '', $identifier);
    if ($stripped && preg_match('/^\+?[0-9]{7,15}$/', $stripped)) {
        $channel = 'sms';
        $phone = $stripped;
    }
}

if ($channel === null) {
    logResendResetAttempt([
        'endpoint' => 'resend_reset_otp',
        'identifier' => $identifier,
        'channel' => null,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'user_found' => false,
        'otp_sent' => false,
        'note' => 'invalid identifier format'
    ]);
    http_response_code(200);
    echo RESEND_RESET_SUCCESS_RESPONSE;
    exit;
}

try {
    if ($channel === 'email') {
        $anyStmt = $pdo->prepare("SELECT id, provider FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $anyStmt->execute([$email]);
    } else {
        $anyStmt = $pdo->prepare("SELECT id, provider FROM users WHERE cell = ? LIMIT 1");
        $anyStmt->execute([$phone]);
    }
    $anyUser = $anyStmt->fetch(PDO::FETCH_ASSOC);

    if ($anyUser && !in_array((string)$anyUser['provider'], ['email', 'phone'], true)) {
        logResendResetAttempt([
            'endpoint' => 'resend_reset_otp',
            'identifier' => $identifier,
            'channel' => $channel,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'user_found' => true,
            'otp_sent' => false,
            'note' => 'social provider: ' . $anyUser['provider']
        ]);
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'This account uses ' . ucfirst((string)$anyUser['provider']) . ' sign-in. Please use the social login button to access your account.'
        ]);
        exit;
    }

    if ($channel === 'email') {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, status
            FROM users
            WHERE LOWER(email) = LOWER(?) AND provider IN ('email', 'phone')
            LIMIT 1
        ");
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, status
            FROM users
            WHERE cell = ? AND provider IN ('email', 'phone')
            LIMIT 1
        ");
        $stmt->execute([$phone]);
    }

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $userFound = (bool)$user;
    $otpSent = false;

    if ($user && (int)$user['status'] === 1) {
        $userId = (int)$user['id'];
        $destination = $channel === 'email' ? (string)$user['email'] : (string)$user['cell'];
        $toName = trim(((string)($user['name'] ?? '')) . ' ' . ((string)($user['surname'] ?? '')));
        if ($toName === '') {
            $toName = 'Customer';
        }

        $otpResult = createPasswordResetOtp($pdo, $userId, $channel, $destination);
        $otpCode = (string)($otpResult['otp_code'] ?? '');

        if ($otpCode !== '') {
            if ($channel === 'email') {
                $sendResult = sendOtpEmail($destination, $toName, $otpCode, [
                    'user_id' => $userId,
                    'purpose' => 'password_reset',
                    'source' => 'resend_reset_otp'
                ]);
            } else {
                $sendResult = sendOtpSms($destination, $otpCode, [
                    'user_id' => $userId,
                    'purpose' => 'password_reset',
                    'source' => 'resend_reset_otp'
                ]);
            }
            $otpSent = !empty($sendResult['ok']);
        }
    }

    logResendResetAttempt([
        'endpoint' => 'resend_reset_otp',
        'identifier' => $identifier,
        'channel' => $channel,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'user_found' => $userFound,
        'otp_sent' => $otpSent
    ]);

    http_response_code(200);
    echo RESEND_RESET_SUCCESS_RESPONSE;
} catch (Throwable $e) {
    logException('mobile_auth_resend_reset_otp', $e, [
        'identifier' => $identifier,
        'ip' => $ip
    ]);
    logResendResetAttempt([
        'endpoint' => 'resend_reset_otp',
        'identifier' => $identifier,
        'channel' => $channel,
        'ip' => $ip,
        'user_agent' => $userAgent,
        'user_found' => false,
        'otp_sent' => false,
        'note' => 'server error: ' . $e->getMessage()
    ]);
    http_response_code(200);
    echo RESEND_RESET_SUCCESS_RESPONSE;
}

