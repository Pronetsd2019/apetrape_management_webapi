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
 * Mobile Cart Remove Item Endpoint
 * DELETE /mobile/v1/cart/remove.php?item_id=123
 * Requires JWT authentication - removes item from user's cart
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

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Validate item_id parameter
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;

if (!$item_id || $item_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid item ID. Please provide a valid item_id parameter.'
    ]);
    exit;
}

try {
    // Check if item exists in cart
    $cartCheck = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND item_id = ? LIMIT 1");
    $cartCheck->execute([$user_id, $item_id]);
    $cartItem = $cartCheck->fetch(PDO::FETCH_ASSOC);

    if (!$cartItem) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Item not found in cart.'
        ]);
        exit;
    }

    // Remove item from cart
    $deleteStmt = $pdo->prepare("DELETE FROM cart WHERE id = ?");
    $deleteStmt->execute([$cartItem['id']]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item removed from cart successfully.',
        'data' => [
            'cart_id' => (int)$cartItem['id'],
            'item_id' => $item_id,
            'removed_quantity' => (int)$cartItem['quantity']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_cart_remove', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while removing item from cart. Please try again later.',
        'error_details' => 'Error removing from cart: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_cart_remove', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error removing from cart: ' . $e->getMessage()
    ]);
}
?>
