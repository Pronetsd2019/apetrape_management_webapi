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
 * Mobile Notifications Save FCM Token Endpoint
 * POST /mobile/v1/notifications/save_fcm_token.php
 * Requires JWT authentication - saves or updates FCM token for push notifications
 * Body: { "fcm_token": "...", "device_id": "...", "platform": "android|ios|web" }
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

// Validate required field
if (!isset($input['fcm_token']) || empty(trim($input['fcm_token']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'fcm_token is required.'
    ]);
    exit;
}

$fcm_token = trim($input['fcm_token']);

// Normalize device_id: treat empty string as NULL
$device_id = isset($input['device_id']) && !empty(trim($input['device_id'])) 
    ? trim($input['device_id']) 
    : null;

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

    // Begin transaction for atomic upsert
    $pdo->beginTransaction();

    try {
        // Step 1: Check if a row exists for this (user_id, device_id)
        if ($device_id === null) {
            // Special handling for NULL device_id - check for existing row with NULL
            $checkStmt = $pdo->prepare("
                SELECT id, fcm_token, platform 
                FROM user_fcm_tokens 
                WHERE user_id = ? AND device_id IS NULL
                LIMIT 1
            ");
            $checkStmt->execute([$user_id]);
        } else {
            $checkStmt = $pdo->prepare("
                SELECT id, fcm_token, platform 
                FROM user_fcm_tokens 
                WHERE user_id = ? AND device_id = ?
                LIMIT 1
            ");
            $checkStmt->execute([$user_id, $device_id]);
        }
        
        $existingRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRow) {
            // Row exists for this (user_id, device_id): UPDATE it
            $updateStmt = $pdo->prepare("
                UPDATE user_fcm_tokens 
                SET fcm_token = ?, platform = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$fcm_token, $platform, $existingRow['id']]);
            
            $action = 'updated';
            $rowId = $existingRow['id'];
        } else {
            // No row for this (user_id, device_id): need to INSERT
            
            // Step 2: Check if this fcm_token already exists (any user/device)
            $tokenCheckStmt = $pdo->prepare("
                SELECT id FROM user_fcm_tokens WHERE fcm_token = ? LIMIT 1
            ");
            $tokenCheckStmt->execute([$fcm_token]);
            $existingToken = $tokenCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingToken) {
                // Token exists for a different user/device: DELETE it (token reassignment)
                $deleteStmt = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE id = ?");
                $deleteStmt->execute([$existingToken['id']]);
            }
            
            // Step 3: INSERT new row
            $insertStmt = $pdo->prepare("
                INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id, platform, updated_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $insertStmt->execute([$user_id, $fcm_token, $device_id, $platform]);
            
            $rowId = (int)$pdo->lastInsertId();
            $action = 'created';
        }

        $pdo->commit();

        // Log the action
        logError('mobile_notifications_save_fcm_token', 'FCM token saved', [
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
                'id' => $rowId,
                'fcm_token' => $fcm_token,
                'device_id' => $device_id,
                'platform' => $platform
            ]
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    logException('mobile_notifications_save_fcm_token', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while saving FCM token. Please try again later.',
        'error_details' => 'Error saving FCM token: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_notifications_save_fcm_token', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error saving FCM token: ' . $e->getMessage()
    ]);
}
?>
