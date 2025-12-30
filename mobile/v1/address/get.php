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
 * Mobile User Get Addresses Endpoint
 * GET /mobile/v1/address/get.php
 * Requires JWT authentication - returns all addresses for the authenticated user
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

    // Get all addresses for this user with location hierarchy
    $stmt = $pdo->prepare("
        SELECT 
            ua.id,
            ua.user_id,
            ua.street,
            ua.plot,
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
        WHERE ua.user_id = ?
        ORDER BY ua.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format addresses for response
    $formatted_addresses = [];
    foreach ($addresses as $address) {
        $formatted_addresses[] = [
            'id' => (int)$address['id'],
            'street' => $address['street'],
            'plot' => $address['plot'],
            'city' => [
                'id' => (int)$address['city_id'],
                'name' => $address['city_name']
            ],
            'region' => [
                'id' => (int)$address['region_id'],
                'name' => $address['region_name']
            ],
            'country' => [
                'id' => (int)$address['country_id'],
                'name' => $address['country_name']
            ],
            'created_at' => $address['created_at'],
            'updated_at' => $address['updated_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Addresses fetched successfully.',
        'data' => $formatted_addresses,
        'count' => count($formatted_addresses)
    ]);

} catch (PDOException $e) {
    logException('mobile_address_get', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching your addresses. Please try again later.',
        'error_details' => 'Error fetching addresses: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_address_get', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching your addresses. Please try again later.',
        'error_details' => 'Error fetching addresses: ' . $e->getMessage()
    ]);
}
?>

