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
 * Mobile Notifications Mark as Read Endpoint
 * PUT /mobile/v1/notifications/mark_as_read.php
 * Requires JWT authentication - marks notifications as read for the authenticated user
 * Accepts: { "ids": [1, 2, 3] } or { "all": true }
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

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
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

    if ($user['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Your account is not active. Please contact support.'
        ]);
        exit;
    }

    $marked_count = 0;

    // Check if marking all notifications as read
    if (isset($input['all']) && $input['all'] === true) {
        // Mark all unread notifications for this user as read
        $stmt = $pdo->prepare("
            UPDATE notification_users 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        $marked_count = $stmt->rowCount();

    } elseif (isset($input['ids']) && is_array($input['ids']) && !empty($input['ids'])) {
        // Mark specific notifications as read
        $ids = array_filter($input['ids'], function($id) {
            return is_numeric($id) && $id > 0;
        });

        if (empty($ids)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'Invalid notification IDs provided.'
            ]);
            exit;
        }

        // Cast to integers
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique($ids)); // Remove duplicates

        // Update only notifications that belong to this user and are not already read
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("
            UPDATE notification_users 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = ? 
            AND id IN ({$placeholders})
            AND is_read = 0
        ");
        
        $params = array_merge([$user_id], $ids);
        $stmt->execute($params);
        $marked_count = $stmt->rowCount();

    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Please provide either "ids" array or set "all" to true.'
        ]);
        exit;
    }

    // Log the action
    logError('mobile_notifications_mark_as_read', 'Notifications marked as read', [
        'user_id' => $user_id,
        'marked_count' => $marked_count,
        'marked_all' => isset($input['all']) && $input['all'] === true,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $marked_count . ' notification(s) marked as read successfully.',
        'data' => [
            'marked_count' => $marked_count
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_notifications_mark_as_read', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while marking notifications as read. Please try again later.',
        'error_details' => 'Error marking notifications as read: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_notifications_mark_as_read', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error marking notifications as read: ' . $e->getMessage()
    ]);
}
?>
