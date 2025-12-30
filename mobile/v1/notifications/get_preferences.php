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
 * Mobile User Get Notification Preferences Endpoint
 * GET /mobile/v1/notifications/get_preferences.php
 * Requires JWT authentication - returns notification preferences for the authenticated user
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
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

    // Get notification preferences
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            push_notifications,
            email_notifications,
            sms_notifications,
            promotions,
            security,
            general,
            created_at,
            updated_at
        FROM user_notification_preferences
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);

    // If preferences don't exist, return default values
    if (!$preferences) {
        $preferences = [
            'id' => null,
            'user_id' => $user_id,
            'push_notifications' => 1,
            'email_notifications' => 0,
            'sms_notifications' => 0,
            'promotions' => 1,
            'security' => 1,
            'general' => 1,
            'created_at' => null,
            'updated_at' => null
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Notification preferences fetched successfully.',
        'data' => [
            'push_notifications' => (bool)$preferences['push_notifications'],
            'email_notifications' => (bool)$preferences['email_notifications'],
            'sms_notifications' => (bool)$preferences['sms_notifications'],
            'promotions' => (bool)$preferences['promotions'],
            'security' => (bool)$preferences['security'],
            'general' => (bool)$preferences['general']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_notifications_get_preferences', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching your notification preferences. Please try again later.',
        'error_details' => 'Error fetching notification preferences: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_notifications_get_preferences', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching your notification preferences. Please try again later.',
        'error_details' => 'Error fetching notification preferences: ' . $e->getMessage()
    ]);
}
?>

