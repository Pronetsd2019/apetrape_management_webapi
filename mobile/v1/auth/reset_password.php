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
 * Reset Password
 * POST /mobile/v1/auth/reset_password.php
 * Body: { "reset_token": "<64-char token>", "new_password": "..." }
 *
 * Consumes the single-use reset_token issued by verify_reset_otp.php
 * and sets the user's new password. The token is invalidated on use.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$resetToken  = isset($input['reset_token'])  ? trim((string)$input['reset_token'])  : '';
$newPassword = isset($input['new_password']) ? (string)$input['new_password']        : '';

// Validate inputs
if ($resetToken === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'reset_token is required.']);
    exit;
}

if ($newPassword === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'new_password is required.']);
    exit;
}

if (strlen($newPassword) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters long.']);
    exit;
}

try {
    // Hash the received token to look it up (we only store the hash)
    $tokenHash = hash('sha256', $resetToken);

    $tokenStmt = $pdo->prepare("
        SELECT id, user_id
        FROM user_otps
        WHERE code_hash = ? AND purpose = 'reset_token'
          AND consumed_at IS NULL AND expires_at > NOW()
        LIMIT 1
    ");
    $tokenStmt->execute([$tokenHash]);
    $tokenRow = $tokenStmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or expired reset token. Please start the password reset process again.'
        ]);
        exit;
    }

    $userId  = (int)$tokenRow['user_id'];
    $tokenId = (int)$tokenRow['id'];

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    // Consume the reset token so it cannot be reused
    $pdo->prepare("UPDATE user_otps SET consumed_at = NOW() WHERE id = ?")
        ->execute([$tokenId]);

    // Set the new password
    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$passwordHash, $userId]);

    $pdo->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password updated successfully. Please log in with your new password.'
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_auth_reset_password', $e, ['user_id' => $userId ?? null]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred. Please try again later.'
    ]);
}
