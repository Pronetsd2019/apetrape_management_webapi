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
 * Mobile Cart Get Endpoint
 * GET /mobile/v1/cart/get.php
 * Requires JWT authentication - returns user's cart items with full item details
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

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get cart items with item details
    $stmt = $pdo->prepare("
        SELECT
            c.id as cart_id,
            c.user_id,
            c.item_id,
            c.quantity,
            c.created_at as cart_created_at,
            c.updated_at as cart_updated_at,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.price,
            i.discount,
            i.sale_price,
            i.cost_price,
            i.lead_time,
            (
                SELECT src
                FROM item_images ii
                WHERE ii.item_id = i.id
                ORDER BY ii.id ASC
                LIMIT 1
            ) AS image_url
        FROM cart c
        INNER JOIN items i ON c.item_id = i.id
        WHERE c.user_id = ?
        ORDER BY c.created_at DESC
    ");

    $stmt->execute([$user_id]);
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate totals
    $total_items = 0;
    $total_price = 0;
    $formatted_items = [];

    foreach ($cartItems as $item) {
        $quantity = (int)$item['quantity'];
        $unit_price = (float)($item['sale_price'] ?: $item['price'] ?: 0);
        $item_total = $quantity * $unit_price;

        $total_items += $quantity;
        $total_price += $item_total;

        $formatted_items[] = [
            'cart_id' => (int)$item['cart_id'],
            'item_id' => (int)$item['item_id'],
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_price' => round($item_total, 2),
            'cart_created_at' => $item['cart_created_at'],
            'cart_updated_at' => $item['cart_updated_at'],
            'item' => [
                'id' => (int)$item['item_id'],
                'name' => $item['name'],
                'description' => $item['description'] ?: null,
                'sku' => $item['sku'] ?: null,
                'is_universal' => (bool)$item['is_universal'],
                'price' => $item['price'] ? (float)$item['price'] : null,
                'discount' => $item['discount'] ? (float)$item['discount'] : null,
                'sale_price' => $item['sale_price'] ? (float)$item['sale_price'] : null,
                'cost_price' => $item['cost_price'] ? (float)$item['cost_price'] : null,
                'lead_time' => $item['lead_time'] ?: null,
                'image_url' => $item['image_url']
            ]
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cart items retrieved successfully.',
        'data' => $formatted_items,
        'summary' => [
            'total_items' => $total_items,
            'total_unique_items' => count($formatted_items),
            'total_price' => round($total_price, 2)
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_cart_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving your cart. Please try again later.',
        'error_details' => 'Error retrieving cart: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_cart_get', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving cart: ' . $e->getMessage()
    ]);
}
?>
