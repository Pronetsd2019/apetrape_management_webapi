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
 * Mobile Verify OTP for Registration
 * POST /mobile/v1/auth/verify_otp.php
 * Body: { "user_id": 123, "otp": "123456" }
 * On success: marks OTP consumed and sets users.activated = 1.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$input = $input ?? [];

$userId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$otp = isset($input['otp']) ? trim((string)$input['otp']) : '';

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'user_id is required and must be a positive integer.'
    ]);
    exit;
}

if ($otp === '' || !preg_match('/^[0-9]{6}$/', $otp)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'otp is required and must be 6 digits.'
    ]);
    exit;
}

try {
    // Find latest unconsumed, non-expired registration OTP for this user
    $stmt = $pdo->prepare("
        SELECT id, user_id, code_hash
        FROM user_otps
        WHERE user_id = ? AND purpose = 'register' AND consumed_at IS NULL AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or expired OTP',
            'message' => 'OTP not found, already used, or expired. Please request a new code.'
        ]);
        exit;
    }

    if (!password_verify($otp, $row['code_hash'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid OTP',
            'message' => 'The verification code is incorrect.'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    $otpId = (int)$row['id'];

    $pdo->prepare("UPDATE user_otps SET consumed_at = NOW() WHERE id = ?")->execute([$otpId]);
    $pdo->prepare("UPDATE users SET activated = 1, updated_at = NOW() WHERE id = ?")->execute([$userId]);

    $pdo->commit();

    $userStmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, created_at, updated_at, activated
        FROM users
        WHERE id = ?
    ");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Verification successful. Your account is now activated.',
        'data' => [
            'user' => [
                'id' => (string)$user['id'],
                'name' => $user['name'],
                'surname' => $user['surname'],
                'email' => $user['email'],
                'cell' => $user['cell'],
                'activated' => (bool)(int)($user['activated'] ?? 0),
                'created_at' => $user['created_at'],
                'updated_at' => $user['updated_at']
            ]
        ]
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_auth_verify_otp', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during verification. Please try again later.'
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logException('mobile_auth_verify_otp', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during verification. Please try again later.'
    ]);
}
