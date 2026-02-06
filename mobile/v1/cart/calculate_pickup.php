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
 * Mobile Cart Calculate Pickup Delivery Cost
 * POST /mobile/v1/cart/calculate_pickup.php
 * Calculates delivery cost for pickup orders (currently returns 0.00)
 * Request: { "pickup_id": 1 }
 * Response: { "success": true, "data": { "cost": 0.00, "pickup_point": {...} } }
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

header('Content-Type: application/json');

try {
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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed. Use POST.'
        ]);
        exit;
    }

    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON',
            'message' => 'Request body must be valid JSON.'
        ]);
        exit;
    }

    // Validate required fields
    if (!isset($input['pickup_id']) || !is_numeric($input['pickup_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation error',
            'message' => 'pickup_id is required and must be a valid number.'
        ]);
        exit;
    }

    $pickup_id = (int)$input['pickup_id'];

    // Fetch pickup point from pickup_points table
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            address,
            fee
        FROM pickup_points
        WHERE id = ? AND status = 1
        LIMIT 1
    ");
    $stmt->execute([$pickup_id]);
    $pickupPoint = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pickupPoint) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Pickup point not found or inactive.'
        ]);
        exit;
    }

    // Get the fee from pickup point
    $pickupFee = isset($pickupPoint['fee']) ? (float)$pickupPoint['fee'] : 0.00;

    // Format pickup point for response
    $formattedPickupPoint = [
        'id' => (int)$pickupPoint['id'],
        'name' => $pickupPoint['name'],
        'address' => $pickupPoint['address'],
        'fee' => $pickupFee
    ];

    // Return success with pickup fee
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Pickup delivery cost calculated successfully.',
        'data' => [
            'cost' => $pickupFee,
            'pickup_point' => $formattedPickupPoint,
            'calculation_method' => 'pickup'
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_cart_calculate_pickup', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'message' => 'An error occurred while processing your request.'
    ]);
} catch (Exception $e) {
    logException('mobile_cart_calculate_pickup', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred.'
    ]);
}
?>
