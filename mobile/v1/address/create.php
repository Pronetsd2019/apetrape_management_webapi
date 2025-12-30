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

// Validate required fields
$required_fields = ['city_id', 'plot', 'street'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => "Field '{$field}' is required."
        ]);
        exit;
    }
}

$city_id = trim($input['city_id']);
$plot = trim($input['plot']);
$street = trim($input['street']);

// Validate city_id is numeric
if (!is_numeric($city_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'City ID must be a valid number.'
    ]);
    exit;
}

$city_id = (int)$city_id;

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

    // Validate city exists
    $stmt = $pdo->prepare("SELECT id, name FROM city WHERE id = ?");
    $stmt->execute([$city_id]);
    $city = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$city) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'City not found.'
        ]);
        exit;
    }

    // Check if user already has the exact same address
    $stmt = $pdo->prepare("
        SELECT id 
        FROM user_address 
        WHERE user_id = ? 
        AND city = ? 
        AND LOWER(TRIM(plot)) = LOWER(TRIM(?)) 
        AND LOWER(TRIM(street)) = LOWER(TRIM(?))
    ");
    $stmt->execute([$user_id, $city_id, $plot, $street]);
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
        INSERT INTO user_address (user_id, city, plot, street)
        VALUES (?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([$user_id, $city_id, $plot, $street]);

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

    // Fetch the created address with city, region, and country details
    $stmt = $pdo->prepare("
        SELECT 
            ua.id,
            ua.user_id,
            ua.city,
            ua.plot,
            ua.street,
            ua.created_at,
            ua.updated_at,
            c.id AS city_id,
            c.name AS city_name,
            r.id AS region_id,
            r.name AS region_name,
            co.id AS country_id,
            co.name AS country_name
        FROM user_address ua
        INNER JOIN city c ON ua.city = c.id
        INNER JOIN region r ON c.region_id = r.id
        INNER JOIN country co ON r.country_id = co.id
        WHERE ua.id = ?
    ");
    $stmt->execute([$address_id]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);

    // Log the address creation
    logError('mobile_address_create', 'User address created', [
        'user_id' => $user_id,
        'address_id' => $address_id,
        'city_id' => $city_id,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Address created successfully.',
        'data' => [
            'id' => (int)$address['id'],
            'street' => $address['street'],
            'plot' => $address['plot'],
            'city' => $address['city_name'],
            'region' => $address['region_name'],
            'country' => $address['country_name'],
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

