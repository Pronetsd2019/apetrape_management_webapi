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
 * Mobile Cart Add Item Endpoint
 * POST /mobile/v1/cart/add.php
 * Body: { "item_id": 123, "quantity": 2 }
 * Requires JWT authentication - adds item to user's cart or updates quantity if already exists
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

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['item_id']) || !isset($input['quantity'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request. Please provide item_id and quantity.'
        ]);
        exit;
    }

    $item_id = (int)$input['item_id'];
    $quantity = (int)$input['quantity'];

    if ($item_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid item ID.'
        ]);
        exit;
    }

    if ($quantity <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Quantity must be greater than 0.'
        ]);
        exit;
    }

    // Check if item exists
    $itemCheck = $pdo->prepare("SELECT id, name FROM items WHERE id = ? LIMIT 1");
    $itemCheck->execute([$item_id]);
    $item = $itemCheck->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Item not found.'
        ]);
        exit;
    }

    // Check if item already exists in cart
    $cartCheck = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND item_id = ? LIMIT 1");
    $cartCheck->execute([$user_id, $item_id]);
    $existingCartItem = $cartCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingCartItem) {
        // Update existing cart item quantity
        $newQuantity = $existingCartItem['quantity'] + $quantity;
        $updateStmt = $pdo->prepare("UPDATE cart SET quantity = ?, updated_at = NOW() WHERE id = ?");
        $updateStmt->execute([$newQuantity, $existingCartItem['id']]);

        $cart_id = $existingCartItem['id'];
        $action = 'updated';
    } else {
        // Add new item to cart
        $insertStmt = $pdo->prepare("INSERT INTO cart (user_id, item_id, quantity, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
        $insertStmt->execute([$user_id, $item_id, $quantity]);

        $cart_id = $pdo->lastInsertId();
        $action = 'added';
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Item {$action} to cart successfully.",
        'data' => [
            'cart_id' => (int)$cart_id,
            'item_id' => $item_id,
            'item_name' => $item['name'],
            'quantity' => $existingCartItem ? $newQuantity : $quantity,
            'action' => $action
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_cart_add', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while adding item to cart. Please try again later.',
        'error_details' => 'Error adding to cart: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_cart_add', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error adding to cart: ' . $e->getMessage()
    ]);
}
?>
