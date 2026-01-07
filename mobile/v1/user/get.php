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
 * Mobile User Get Profile Endpoint
 * GET /mobile/v1/user/get.php
 * Requires JWT authentication - returns the authenticated user's profile
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
    // Fetch user profile
    $stmt = $pdo->prepare("
        SELECT 
            CONCAT_WS(' ', `name`, `surname`) AS user_names,
            `email`,
            `cell`,
            `created_at`,
            `provider`,
            `avatar`,
            `status`
        FROM `users`
        WHERE `id` = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'User profile not found.'
        ]);
        exit;
    }

    // Format response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'User profile retrieved successfully.',
        'data' => [
            'name' => $user['user_names'],
            'email' => $user['email'],
            'cell' => $user['cell'],
            'created_at' => $user['created_at'],
            'provider' => $user['provider'],
            'avatar' => $user['avatar'],
            'status' => (int)$user['status']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_user_get', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving your profile. Please try again later.',
        'error_details' => 'Error retrieving user profile: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_user_get', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving your profile. Please try again later.',
        'error_details' => 'Error retrieving user profile: ' . $e->getMessage()
    ]);
}
?>

