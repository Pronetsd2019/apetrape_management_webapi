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

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Mobile User Change Password Endpoint
 * PUT /mobile/v1/user/change_password.php
 * Requires JWT authentication - user must be logged in to change password
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get the authenticated user's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['current_password', 'new_password', 'new_password_confirm'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => "Field '{$field}' is required."
        ]);
        exit;
    }
}

$current_password = $input['current_password'];
$new_password = $input['new_password'];
$new_password_confirm = $input['new_password_confirm'];

// Validate password confirmation matches
if ($new_password !== $new_password_confirm) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'New password confirmation does not match.'
    ]);
    exit;
}

// Validate new password is different from current password
if ($current_password === $new_password) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'New password must be different from the current password.'
    ]);
    exit;
}

// Validate password length (minimum 6 characters)
if (strlen($new_password) < 6) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'New password must be at least 6 characters long.'
    ]);
    exit;
}

try {
    // Get user details including password hash
    $stmt = $pdo->prepare("
        SELECT id, email, password_hash, status, provider
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'User not found.'
        ]);
        exit;
    }

    // Check if user account is active
    if ($user['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Your account is not active. Please contact support.'
        ]);
        exit;
    }

    // Check if user has password (email/password users only)
    if ($user['provider'] !== 'email' || !$user['password_hash']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid request',
            'message' => 'Password change is only available for email/password accounts.'
        ]);
        exit;
    }

    // Verify current password
    $password_correct = password_verify($current_password, $user['password_hash']);

    if (!$password_correct) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Current password is incorrect.'
        ]);
        exit;
    }

    // Hash new password
    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

    // Update password and reset lockout stats
    $stmt = $pdo->prepare("
        UPDATE users
        SET password_hash = ?, 
            failed_attempts = 0, 
            locked_until = NULL,
            lockout_stage = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_password_hash, $user_id]);

    // Log the password change
    logError('mobile_user_change_password', 'User password changed', [
        'user_id' => $user_id,
        'email' => $user['email'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully.'
    ]);

} catch (PDOException $e) {
    logException('mobile_user_change_password', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while changing your password. Please try again later.',
        'error_details' => 'Error changing password: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_user_change_password', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while changing your password. Please try again later.',
        'error_details' => 'Error changing password: ' . $e->getMessage()
    ]);
}
?>

