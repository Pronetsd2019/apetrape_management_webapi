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
 * Mobile User Action Tracking Endpoint
 * POST /mobile/v1/user/create_actions.php
 * Body: {
 *   "action_type": "view|click|add_to_cart|purchase",  // required
 *   "item_id": 123,      // optional - item ID (integer)
 *   "brand": 5,          // optional - brand ID (integer)
 *   "category": 10       // optional - category ID (integer)
 * }
 * Note: At least one of item_id, brand, or category must be provided
 * Requires JWT authentication - tracks user interactions with items/brands/categories
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

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in request body.']);
    exit;
}

// Validate required fields
$action_type = isset($input['action_type']) ? trim($input['action_type']) : null;

if (!$action_type) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'action_type is required.']);
    exit;
}

// Optional fields - at least one must be provided
$item_id = isset($input['item_id']) && is_numeric($input['item_id']) ? (int)$input['item_id'] : null;

// Validate action_type against allowed values
$allowed_actions = ['view', 'click', 'add_to_cart', 'purchase'];
if (!in_array($action_type, $allowed_actions)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action_type. Must be one of: ' . implode(', ', $allowed_actions)
    ]);
    exit;
}

// Optional fields - brand and category are now integers (foreign keys)
$brand = isset($input['brand']) && is_numeric($input['brand']) ? (int)$input['brand'] : null;
$category = isset($input['category']) && is_numeric($input['category']) ? (int)$input['category'] : null;

// Ensure at least one identifier is provided
if (!$item_id && !$brand && !$category) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'At least one of item_id, brand, or category must be provided.'
    ]);
    exit;
}

try {
    // Insert user action
    $insertStmt = $pdo->prepare("
        INSERT INTO user_actions (
            user_id, 
            action_type, 
            item_id, 
            brand, 
            category, 
            created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $result = $insertStmt->execute([
        $user_id,
        $action_type,
        $item_id,
        $brand,
        $category
    ]);

    if ($result) {
        $action_id = $pdo->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'User action tracked successfully.',
            'data' => [
                'id' => (int)$action_id,
                'user_id' => $user_id,
                'action_type' => $action_type,
                'item_id' => $item_id,
                'brand' => $brand,
                'category' => $category,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to track user action.'
        ]);
    }

} catch (PDOException $e) {
    logException('mobile_user_create_actions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while tracking user action. Please try again later.',
        'error_details' => 'Error tracking action: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_user_create_actions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error tracking action: ' . $e->getMessage()
    ]);
}
?>
