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
 * Mobile Notifications Refresh FCM Token Endpoint
 * POST /mobile/v1/notifications/refresh_fcm_token.php
 * Requires JWT authentication - refreshes FCM token for push notifications
 * Body: { "fcm_token": "...", "device_id": "...", "platform": "android|ios|web" }
 * 
 * This endpoint uses ON DUPLICATE KEY UPDATE to efficiently handle token refreshes
 * without duplicates. If a token exists for the same user_id and device_id, it updates
 * the token. Otherwise, it inserts a new record.
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
$authUser = requireMobileJwtAuth();
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

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Invalid JSON input.'
    ]);
    exit;
}

// Validate required fields
if (!isset($input['fcm_token']) || empty(trim($input['fcm_token']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'fcm_token is required.'
    ]);
    exit;
}

if (!isset($input['device_id']) || empty(trim($input['device_id']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'device_id is required for token refresh.'
    ]);
    exit;
}

$fcm_token = trim($input['fcm_token']);
$device_id = trim($input['device_id']);

// Validate and normalize platform
$platform = null;
if (isset($input['platform']) && !empty(trim($input['platform']))) {
    $platformInput = strtolower(trim($input['platform']));
    $allowedPlatforms = ['android', 'ios', 'web'];
    
    if (!in_array($platformInput, $allowedPlatforms)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Invalid platform. Must be one of: android, ios, web.'
        ]);
        exit;
    }
    
    $platform = $platformInput;
}

try {
    // Verify user exists
    $stmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
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

    // Use ON DUPLICATE KEY UPDATE for efficient token refresh
    // This ensures that:
    // 1. If a record exists for (user_id, device_id), it updates the token
    // 2. If no record exists, it inserts a new one
    // 3. No duplicate entries are created
    $stmt = $pdo->prepare("
        INSERT INTO user_fcm_tokens (user_id, device_id, fcm_token, platform, updated_at)
        VALUES (:user_id, :device_id, :fcm_token, :platform, NOW())
        ON DUPLICATE KEY UPDATE 
            fcm_token = :fcm_token, 
            platform = :platform,
            updated_at = NOW()
    ");
    
    $stmt->execute([
        'user_id' => $user_id,
        'device_id' => $device_id,
        'fcm_token' => $fcm_token,
        'platform' => $platform
    ]);

    // Determine if it was an insert or update
    // If affected rows is 1, it was an INSERT
    // If affected rows is 2, it was an UPDATE
    // If affected rows is 0, no change was made (same token)
    $affectedRows = $stmt->rowCount();
    
    if ($affectedRows === 1) {
        $action = 'created';
    } elseif ($affectedRows === 2) {
        $action = 'refreshed';
    } else {
        $action = 'unchanged';
    }

    // Log the action
    logError('mobile_notifications_refresh_fcm_token', 'FCM token refreshed', [
        'user_id' => $user_id,
        'action' => $action,
        'device_id' => $device_id,
        'platform' => $platform,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "FCM token {$action} successfully.",
        'data' => [
            'fcm_token' => $fcm_token,
            'device_id' => $device_id,
            'platform' => $platform,
            'action' => $action
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_notifications_refresh_fcm_token', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while refreshing FCM token. Please try again later.',
        'error_details' => 'Error refreshing FCM token: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_notifications_refresh_fcm_token', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error refreshing FCM token: ' . $e->getMessage()
    ]);
}
?>
