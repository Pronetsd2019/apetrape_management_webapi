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
 * Resend Registration Verification OTP
 * POST /mobile/v1/auth/resend_verification_otp.php
 * Body: { "user_id": 123 } OR { "identifier": "email@example.com" } OR { "identifier": "+26876000000" }
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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$identifier = isset($input['identifier']) ? trim((string)$input['identifier']) : '';

if ($userId <= 0 && $identifier === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Provide user_id or identifier.'
    ]);
    exit;
}

try {
    $user = null;

    if ($userId > 0) {
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, provider, status, activated
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $stmt = $pdo->prepare("
                SELECT id, name, email, cell, provider, status, activated
                FROM users
                WHERE LOWER(email) = LOWER(?)
                LIMIT 1
            ");
            $stmt->execute([$identifier]);
        } else {
            $normalized = preg_replace('/\s+/', '', $identifier);
            if (!$normalized || !preg_match('/^\+?[0-9]{7,15}$/', $normalized)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid identifier format.'
                ]);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT id, name, email, cell, provider, status, activated
                FROM users
                WHERE cell = ?
                LIMIT 1
            ");
            $stmt->execute([$normalized]);
        }
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'If an account exists and is pending verification, a code has been sent.'
        ]);
        exit;
    }

    if (!in_array((string)$user['provider'], ['email', 'phone'], true)) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => 'This account uses ' . ucfirst((string)$user['provider']) . ' sign-in. Please use the social login button.'
        ]);
        exit;
    }

    if ((int)$user['status'] !== 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account is not active. Please contact support.'
        ]);
        exit;
    }

    if ((int)($user['activated'] ?? 0) === 1) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Account is already verified.',
            'otp_sent' => false
        ]);
        exit;
    }

    $hasEmail = !empty(trim((string)($user['email'] ?? ''))) && filter_var(trim((string)$user['email']), FILTER_VALIDATE_EMAIL);
    $hasCell = !empty(trim((string)($user['cell'] ?? '')));

    $otpChannel = null;
    $otpDestination = null;
    if ($hasEmail) {
        $otpChannel = 'email';
        $otpDestination = trim((string)$user['email']);
    } elseif ($hasCell) {
        $otpChannel = 'sms';
        $otpDestination = trim((string)$user['cell']);
    }

    if (!$otpChannel || !$otpDestination) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No valid email or phone found to send OTP.'
        ]);
        exit;
    }

    $otpRow = createRegistrationOtp($pdo, (int)$user['id'], $otpChannel, $otpDestination);
    $otpId = (int)($otpRow['otp_id'] ?? 0);
    $otpCode = (string)($otpRow['otp_code'] ?? '');
    $otpSent = false;

    if ($otpCode !== '') {
        if ($otpChannel === 'email') {
            $sendResult = sendOtpEmail($otpDestination, (string)($user['name'] ?? 'User'), $otpCode, [
                'user_id' => (int)$user['id'],
                'otp_id' => $otpId,
                'source' => 'resend_verification_otp'
            ]);
        } else {
            $sendResult = sendOtpSms($otpDestination, $otpCode, [
                'user_id' => (int)$user['id'],
                'otp_id' => $otpId,
                'source' => 'resend_verification_otp'
            ]);
        }
        $otpSent = !empty($sendResult['ok']);
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $otpSent ? 'Verification code resent successfully.' : 'OTP created but delivery failed.',
        'otp_sent' => $otpSent,
        'otp_channel' => $otpChannel,
        'otp_expires_in' => 600,
        'otp_id' => $otpId
    ]);
} catch (Throwable $e) {
    logException('mobile_auth_resend_verification_otp', $e, [
        'user_id' => $userId,
        'identifier' => $identifier
    ]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while resending the OTP. Please try again later.'
    ]);
}

