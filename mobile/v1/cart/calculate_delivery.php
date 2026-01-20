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
 * Mobile Cart Calculate Delivery Cost Endpoint
 * POST /mobile/v1/cart/calculate_delivery.php
 * Body: { "address_id": 123, "order_id": 456 }
 * Requires JWT authentication - calculates delivery cost from warehouse to user address
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

/**
 * Calculate Haversine distance between two coordinates
 * Returns distance in kilometers, multiplied by 1.5 to approximate road distance
 */
function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // Earth radius in kilometers
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadius * $c;
    
    return $distance * 1.5; // Multiply by 1.5 as per requirement
}

/**
 * Call Google Distance Matrix API to get distance
 */
function getGoogleDistance($warehouseLat, $warehouseLng, $userLat, $userLng) {
    $apiKey = getenv('GOOGLE_MAPS_API_KEY') ?: $_ENV['GOOGLE_MAPS_API_KEY'] ?? null;
    
    if (!$apiKey) {
        return ['error' => 'Google Maps API key not configured', 'use_haversine' => true];
    }
    
    $origins = "{$warehouseLat},{$warehouseLng}";
    $destinations = "{$userLat},{$userLng}";
    
    $url = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origins) 
         . "&destinations=" . urlencode($destinations) 
         . "&key=" . urlencode($apiKey);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError || $httpCode !== 200) {
        return ['error' => 'Google API request failed', 'use_haversine' => true];
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['rows'][0]['elements'][0])) {
        return ['error' => 'Invalid Google API response', 'use_haversine' => true];
    }
    
    $element = $data['rows'][0]['elements'][0];
    $status = $element['status'] ?? 'UNKNOWN';
    
    // Check for error statuses that should trigger Haversine fallback
    if (in_array($status, ['NOT_FOUND', 'ZERO_RESULTS', 'MAX_ROUTE_ELEMENTS_EXCEEDED'])) {
        return ['use_haversine' => true, 'api_status' => $status];
    }
    
    if ($status !== 'OK' || !isset($element['distance']['value'])) {
        return ['error' => 'Google API returned error: ' . $status, 'use_haversine' => true];
    }
    
    // Distance in meters, convert to kilometers
    $distanceKm = $element['distance']['value'] / 1000;
    
    return ['distance_km' => $distanceKm, 'method' => 'google_api'];
}

try {
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
    if (!isset($input['address_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'address_id is required.'
        ]);
        exit;
    }

    $address_id = (int)$input['address_id'];
    $order_id = isset($input['order_id']) ? (int)$input['order_id'] : null;

    if ($address_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Invalid address_id.'
        ]);
        exit;
    }

    // Get warehouse location
    $warehouseStmt = $pdo->prepare("
        SELECT id, place_id, formatted_address, latitude, longitude 
        FROM warehouse_address 
        LIMIT 1
    ");
    $warehouseStmt->execute();
    $warehouse = $warehouseStmt->fetch(PDO::FETCH_ASSOC);

    if (!$warehouse) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Warehouse address not configured.'
        ]);
        exit;
    }

    // Get user address
    $addressStmt = $pdo->prepare("
        SELECT id, place_id, formatted_address, latitude, longitude
        FROM user_addresses 
        WHERE id = ? AND user_id = ?
    ");
    $addressStmt->execute([$address_id, $user_id]);
    $userAddress = $addressStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userAddress) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Address not found or does not belong to you.'
        ]);
        exit;
    }

    $warehouseLat = (float)$warehouse['latitude'];
    $warehouseLng = (float)$warehouse['longitude'];
    $userLat = (float)$userAddress['latitude'];
    $userLng = (float)$userAddress['longitude'];

    // Check for cached distance in delivery_cost_map
    $cacheStmt = $pdo->prepare("
        SELECT id, km, amount 
        FROM delivery_cost_map
        WHERE pickup = ST_GeomFromText(?, 4326)
        AND delivery = ST_GeomFromText(?, 4326)
        LIMIT 1
    ");
    
    $pickupPoint = "POINT({$warehouseLng} {$warehouseLat})";
    $deliveryPoint = "POINT({$userLng} {$userLat})";
    
    $cacheStmt->execute([$pickupPoint, $deliveryPoint]);
    $cachedCost = $cacheStmt->fetch(PDO::FETCH_ASSOC);

    if ($cachedCost) {
        // Use cached result
        $distanceKm = (float)$cachedCost['km'];
        $cost = (float)$cachedCost['amount'];
        $costMapId = (int)$cachedCost['id'];
        $calculationMethod = 'cache';
        $usedCache = true;
    } else {
        // Calculate distance
        $usedCache = false;
        
        // Try Google API first
        $googleResult = getGoogleDistance($warehouseLat, $warehouseLng, $userLat, $userLng);
        
        if (isset($googleResult['use_haversine']) && $googleResult['use_haversine']) {
            // Fallback to Haversine
            $distanceKm = haversineDistance($warehouseLat, $warehouseLng, $userLat, $userLng);
            $calculationMethod = 'haversine';
        } else {
            // Use Google API result
            $distanceKm = $googleResult['distance_km'];
            $calculationMethod = 'google_api';
        }
        
        // Calculate cost: E2/km with E50 minimum
        $cost = max(50, round($distanceKm * 2, 2));
        
        // Insert into delivery_cost_map
        $insertCostStmt = $pdo->prepare("
            INSERT INTO delivery_cost_map (pickup, delivery, km, amount, created_at, updated_at)
            VALUES (
                ST_GeomFromText(?, 4326),
                ST_GeomFromText(?, 4326),
                ?,
                ?,
                NOW(),
                NOW()
            )
        ");
        
        $insertCostStmt->execute([$pickupPoint, $deliveryPoint, $distanceKm, $cost]);
        $costMapId = (int)$pdo->lastInsertId();
    }

    // If order_id provided, insert into delivery_fee
    $deliveryFeeId = null;
    if ($order_id) {
        $insertFeeStmt = $pdo->prepare("
            INSERT INTO delivery_fee (cost_map_id, fee, order_id, created_at, updated_at)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $insertFeeStmt->execute([$costMapId, $cost, $order_id]);
        $deliveryFeeId = (int)$pdo->lastInsertId();
    }

    // Log the calculation
    logError('mobile_cart_calculate_delivery', 'Delivery cost calculated', [
        'user_id' => $user_id,
        'address_id' => $address_id,
        'distance_km' => $distanceKm,
        'cost' => $cost,
        'method' => $calculationMethod,
        'used_cache' => $usedCache,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Delivery cost calculated successfully.',
        'data' => [
            'distance_km' => round($distanceKm, 2),
            'cost' => round($cost, 2),
            'cost_map_id' => $costMapId,
            'delivery_fee_id' => $deliveryFeeId,
            'used_cache' => $usedCache,
            'calculation_method' => $calculationMethod,
            'warehouse' => [
                'id' => (int)$warehouse['id'],
                'formatted_address' => $warehouse['formatted_address']
            ],
            'delivery_address' => [
                'id' => (int)$userAddress['id'],
                'formatted_address' => $userAddress['formatted_address']
            ]
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_cart_calculate_delivery', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while calculating delivery cost. Please try again later.',
        'error_details' => 'Error calculating delivery cost: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_cart_calculate_delivery', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error calculating delivery cost: ' . $e->getMessage()
    ]);
}
?>
