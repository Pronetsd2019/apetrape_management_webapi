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
 * Mobile User Address Creation Endpoint
 * POST /mobile/v1/address/create.php
 * Requires JWT authentication - user must be logged in to create an address
 * Accepts Google Places API format address data
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
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
$required_fields = ['place_id', 'formatted_address', 'latitude', 'longitude'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => "Field '{$field}' is required."
        ]);
        exit;
    }
}

// Extract and validate required fields
$place_id = trim($input['place_id']);
$formatted_address = trim($input['formatted_address']);
$latitude = $input['latitude'];
$longitude = $input['longitude'];

// Validate place_id is not empty
if (empty($place_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Place ID cannot be empty.'
    ]);
    exit;
}

// Validate formatted_address is not empty
if (empty($formatted_address)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Formatted address cannot be empty.'
    ]);
    exit;
}

// Validate latitude and longitude are numeric
if (!is_numeric($latitude) || !is_numeric($longitude)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Latitude and longitude must be valid numbers.'
    ]);
    exit;
}

$latitude = (float)$latitude;
$longitude = (float)$longitude;

// Validate latitude range (-90 to 90)
if ($latitude < -90 || $latitude > 90) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Latitude must be between -90 and 90.'
    ]);
    exit;
}

// Validate longitude range (-180 to 180)
if ($longitude < -180 || $longitude > 180) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Longitude must be between -180 and 180.'
    ]);
    exit;
}

// Extract address components (all optional)
$address_components = $input['address_components'] ?? [];
$street_number = isset($address_components['street_number']) ? trim($address_components['street_number']) : null;
$street = isset($address_components['street']) ? trim($address_components['street']) : null;
$city = isset($address_components['city']) ? trim($address_components['city']) : null;
$sublocality = isset($address_components['sublocality']) ? trim($address_components['sublocality']) : null;
$district = isset($address_components['district']) ? trim($address_components['district']) : null;
$region = isset($address_components['region']) ? trim($address_components['region']) : null;
$country = isset($address_components['country']) ? trim($address_components['country']) : null;
$country_code = isset($address_components['country_code']) ? trim($address_components['country_code']) : null;
$postal_code = isset($address_components['postal_code']) ? trim($address_components['postal_code']) : null;

try {
    // Verify user exists and is active
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

    // Check if user already has an address with the same place_id
    $stmt = $pdo->prepare("
        SELECT id 
        FROM user_addresses 
        WHERE user_id = ? AND place_id = ?
    ");
    $stmt->execute([$user_id, $place_id]);
    $existingAddress = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingAddress) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'error' => 'Conflict',
            'message' => 'You already have this address registered.'
        ]);
        exit;
    }

    // Insert the address
    $stmt = $pdo->prepare("
        INSERT INTO user_addresses (
            user_id, 
            place_id, 
            formatted_address, 
            latitude, 
            longitude, 
            street_number, 
            street, 
            sublocality, 
            city, 
            district, 
            region, 
            country, 
            country_code, 
            postal_code,
            created_at,
            updated_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $result = $stmt->execute([
        $user_id,
        $place_id,
        $formatted_address,
        $latitude,
        $longitude,
        $street_number,
        $street,
        $sublocality,
        $city,
        $district,
        $region,
        $country,
        $country_code,
        $postal_code
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to create address. Please try again later.',
            'error_details' => 'Failed to insert address.'
        ]);
        exit;
    }

    $address_id = $pdo->lastInsertId();

    // Fetch the created address
    $stmt = $pdo->prepare("
        SELECT 
            id,
            place_id,
            formatted_address,
            latitude,
            longitude,
            street_number,
            street,
            sublocality,
            city,
            district,
            region,
            country,
            country_code,
            postal_code,
            created_at,
            updated_at
        FROM user_addresses
        WHERE id = ?
    ");
    $stmt->execute([$address_id]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);

    // Log the address creation
    logError('mobile_address_create', 'User address created', [
        'user_id' => $user_id,
        'address_id' => $address_id,
        'place_id' => $place_id,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Address created successfully.',
        'data' => [
            'id' => (int)$address['id'],
            'place_id' => $address['place_id'],
            'formatted_address' => $address['formatted_address'],
            'latitude' => (float)$address['latitude'],
            'longitude' => (float)$address['longitude'],
            'street_number' => $address['street_number'],
            'street' => $address['street'],
            'sublocality' => $address['sublocality'],
            'city' => $address['city'],
            'district' => $address['district'],
            'region' => $address['region'],
            'country' => $address['country'],
            'country_code' => $address['country_code'],
            'postal_code' => $address['postal_code'],
            'created_at' => $address['created_at'],
            'updated_at' => $address['updated_at']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_address_create', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while creating your address. Please try again later.',
        'error_details' => 'Error creating address: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_address_create', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while creating your address. Please try again later.',
        'error_details' => 'Error creating address: ' . $e->getMessage()
    ]);
}
?>

