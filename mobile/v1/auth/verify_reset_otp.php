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
 * Verify Password-Reset OTP
 * POST /mobile/v1/auth/verify_reset_otp.php
 * Body: { "identifier": "email@example.com", "otp": "123456" }
 *
 * On success: marks OTP consumed and issues a short-lived reset_token
 * that the client must pass to reset_password.php to set the new password.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$identifier = isset($input['identifier']) ? trim((string)$input['identifier']) : '';
$otp        = isset($input['otp'])        ? trim((string)$input['otp'])        : '';

// Validate inputs
if ($identifier === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'identifier is required.']);
    exit;
}

if ($otp === '' || !preg_match('/^[0-9]{6}$/', $otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'otp is required and must be 6 digits.']);
    exit;
}

// Resolve identifier to channel + lookup value (same logic as forgot_password.php)
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
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid identifier format. Provide a valid email or phone number.']);
    exit;
}

try {
    // Look up user by identifier (email/phone provider only)
    if ($channel === 'email') {
        $userStmt = $pdo->prepare("
            SELECT id FROM users
            WHERE LOWER(email) = LOWER(?) AND provider IN ('email', 'phone') AND status = 1
            LIMIT 1
        ");
        $userStmt->execute([$email]);
    } else {
        $userStmt = $pdo->prepare("
            SELECT id FROM users
            WHERE cell = ? AND provider IN ('email', 'phone') AND status = 1
            LIMIT 1
        ");
        $userStmt->execute([$phone]);
    }
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Return the same message as an incorrect OTP to avoid enumeration
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired OTP. Please request a new code.'
        ]);
        exit;
    }

    $userId = (int)$user['id'];

    // Fetch the latest unconsumed, non-expired password_reset OTP for this user
    $otpStmt = $pdo->prepare("
        SELECT id, code_hash
        FROM user_otps
        WHERE user_id = ? AND purpose = 'password_reset'
          AND consumed_at IS NULL AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $otpStmt->execute([$userId]);
    $otpRow = $otpStmt->fetch(PDO::FETCH_ASSOC);

    if (!$otpRow) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired OTP. Please request a new code.'
        ]);
        exit;
    }

    if (!password_verify($otp, $otpRow['code_hash'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'The verification code is incorrect.'
        ]);
        exit;
    }

    // OTP is valid — consume it and issue a single-use reset token (valid 15 min)
    $resetToken = bin2hex(random_bytes(32)); // 64-char hex string returned to client
    $tokenHash  = hash('sha256', $resetToken); // only the hash is stored
    $expiresAt  = date('Y-m-d H:i:s', time() + 900); // 15 minutes

    $pdo->beginTransaction();

    // Mark OTP consumed
    $pdo->prepare("UPDATE user_otps SET consumed_at = NOW() WHERE id = ?")
        ->execute([(int)$otpRow['id']]);

    // Store reset token (hashed) — reuses user_otps with purpose = 'reset_token'
    $pdo->prepare("
        INSERT INTO user_otps (user_id, channel, destination, purpose, code_hash, expires_at, consumed_at, created_at)
        VALUES (?, ?, ?, 'reset_token', ?, ?, NULL, NOW())
    ")->execute([
        $userId,
        $channel,
        $channel === 'email' ? $email : $phone,
        $tokenHash,
        $expiresAt
    ]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success'     => true,
        'message'     => 'OTP verified. Use the reset_token to set your new password.',
        'reset_token' => $resetToken,
        'expires_at'  => $expiresAt
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_auth_verify_reset_otp', $e, ['identifier' => $identifier]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ]);
}
