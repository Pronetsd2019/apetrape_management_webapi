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
 * Forgot Password OTP Endpoint
 * POST /mobile/v1/auth/forgot_password.php
 * Body: { "identifier": "<email or phone>" }
 *
 * Always returns HTTP 200 success to prevent account enumeration.
 * Logs every attempt (identifier, channel, IP, user-agent) regardless of outcome.
 * Sends a password-reset OTP only when a matching active user is found.
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

// Constant success response — never reveals whether account exists
define('FORGOT_SUCCESS_RESPONSE', json_encode([
    'success' => true,
    'message' => 'If an account with that identifier exists, a code has been sent.'
]));

$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

/**
 * Append one line to the forgot-password attempt log.
 */
function logForgotPasswordAttempt(array $data): void {
    $logFile = dirname(__DIR__, 4) . '/control/logs/forgot_password_attempts.log';
    $logDir  = dirname($logFile);
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $ts    = date('Y-m-d H:i:s');
    $entry = "[{$ts}] " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

$input      = json_decode(file_get_contents('php://input'), true);
$identifier = isset($input['identifier']) ? trim((string)$input['identifier']) : '';

if ($identifier === '') {
    logForgotPasswordAttempt([
        'identifier'  => '',
        'channel'     => null,
        'ip'          => $ip,
        'user_agent'  => $userAgent,
        'user_found'  => false,
        'otp_sent'    => false,
        'note'        => 'empty identifier'
    ]);
    http_response_code(200);
    echo FORGOT_SUCCESS_RESPONSE;
    exit;
}

// Determine channel: email or phone
$channel = null;
$email   = null;
$phone   = null;

if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
    $channel = 'email';
    $email   = $identifier;
} else {
    $stripped = preg_replace('/\s+/', '', $identifier);
    if ($stripped && preg_match('/^\+?[0-9]{7,15}$/', $stripped)) {
        $channel = 'sms';
        $phone   = $stripped;
    }
}

if ($channel === null) {
    logForgotPasswordAttempt([
        'identifier'  => $identifier,
        'channel'     => null,
        'ip'          => $ip,
        'user_agent'  => $userAgent,
        'user_found'  => false,
        'otp_sent'    => false,
        'note'        => 'invalid identifier format'
    ]);
    http_response_code(200);
    echo FORGOT_SUCCESS_RESPONSE;
    exit;
}

try {
    // First check if the account exists under ANY provider so we can give a helpful
    // message when the account is linked to a social login.
    if ($channel === 'email') {
        $anyStmt = $pdo->prepare("
            SELECT id, provider FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1
        ");
        $anyStmt->execute([$email]);
    } else {
        $anyStmt = $pdo->prepare("
            SELECT id, provider FROM users WHERE cell = ? LIMIT 1
        ");
        $anyStmt->execute([$phone]);
    }
    $anyUser = $anyStmt->fetch(PDO::FETCH_ASSOC);

    if ($anyUser && !in_array($anyUser['provider'], ['email', 'phone'], true)) {
        // Account exists but is tied to a social provider — tell the user explicitly.
        logForgotPasswordAttempt([
            'identifier'  => $identifier,
            'channel'     => $channel,
            'ip'          => $ip,
            'user_agent'  => $userAgent,
            'user_found'  => true,
            'otp_sent'    => false,
            'note'        => 'social provider: ' . $anyUser['provider']
        ]);
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'This account uses ' . ucfirst($anyUser['provider']) . ' sign-in. Please use the social login button to access your account.'
        ]);
        exit;
    }

    // Now look up only email/phone provider accounts
    if ($channel === 'email') {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, status, activated
            FROM users
            WHERE LOWER(email) = LOWER(?) AND provider IN ('email', 'phone')
            LIMIT 1
        ");
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, status, activated
            FROM users
            WHERE cell = ? AND provider IN ('email', 'phone')
            LIMIT 1
        ");
        $stmt->execute([$phone]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $userFound = (bool)$user;
    $otpSent   = false;

    if ($user && (int)$user['status'] === 1) {
        $userId      = (int)$user['id'];
        $destination = ($channel === 'email') ? $user['email'] : $user['cell'];
        $toName      = trim(($user['name'] ?? '') . ' ' . ($user['surname'] ?? '')) ?: 'Customer';

        $otpResult = createPasswordResetOtp($pdo, $userId, $channel, $destination);
        $otpCode   = $otpResult['otp_code'];

        if ($channel === 'email') {
            $sendResult = sendOtpEmail($destination, $toName, $otpCode, [
                'user_id'  => $userId,
                'purpose'  => 'password_reset'
            ]);
        } else {
            $sendResult = sendOtpSms($destination, $otpCode, [
                'user_id'  => $userId,
                'purpose'  => 'password_reset'
            ]);
        }

        $otpSent = !empty($sendResult['ok']);
    }

    logForgotPasswordAttempt([
        'identifier'  => $identifier,
        'channel'     => $channel,
        'ip'          => $ip,
        'user_agent'  => $userAgent,
        'user_found'  => $userFound,
        'otp_sent'    => $otpSent,
    ]);

    http_response_code(200);
    echo FORGOT_SUCCESS_RESPONSE;

} catch (Throwable $e) {
    logException('mobile_auth_forgot_password', $e, [
        'identifier' => $identifier,
        'ip'         => $ip
    ]);
    logForgotPasswordAttempt([
        'identifier'  => $identifier,
        'channel'     => $channel,
        'ip'          => $ip,
        'user_agent'  => $userAgent,
        'user_found'  => false,
        'otp_sent'    => false,
        'note'        => 'server error: ' . $e->getMessage()
    ]);
    // Always return 200 — never expose server errors to clients
    http_response_code(200);
    echo FORGOT_SUCCESS_RESPONSE;
}
