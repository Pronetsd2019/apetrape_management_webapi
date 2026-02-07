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
 * Mobile User Address Delete Endpoint
 * DELETE /mobile/v1/address/delete.php?id=1
 * Requires JWT authentication - user can only delete their own addresses
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
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

// Get address ID from query string or JSON body
$address_id = null;
if (isset($_GET['id'])) {
    $address_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $address_id = $input['id'] ?? null;
}

if (!$address_id || !is_numeric($address_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Valid address ID is required.'
    ]);
    exit;
}

$address_id = (int)$address_id;

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

    // Check if address exists and belongs to this user
    $stmt = $pdo->prepare("
        SELECT 
            ua.id,
            ua.user_id,
            ua.street,
            ua.plot,
            c.name AS city_name,
            r.name AS region_name,
            co.name AS country_name
        FROM user_addresses ua
        INNER JOIN city c ON ua.city = c.id
        INNER JOIN region r ON c.region_id = r.id
        INNER JOIN country co ON r.country_id = co.id
        WHERE ua.id = ? AND ua.user_id = ?
    ");
    $stmt->execute([$address_id, $user_id]);
    $address = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$address) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Address not found or does not belong to you.'
        ]);
        exit;
    }

    // Delete the address
    $stmt = $pdo->prepare("DELETE FROM user_addresses WHERE id = ? AND user_id = ?");
    $result = $stmt->execute([$address_id, $user_id]);

    if (!$result || $stmt->rowCount() === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to delete address. Please try again later.',
            'error_details' => 'Failed to delete address.'
        ]);
        exit;
    }

    // Log the address deletion
    logError('mobile_address_delete', 'User address deleted', [
        'user_id' => $user_id,
        'address_id' => $address_id,
        'address' => $address['street'] . ', ' . $address['plot'] . ', ' . $address['city_name'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Address deleted successfully.',
        'data' => [
            'id' => (int)$address['id'],
            'street' => $address['street'],
            'plot' => $address['plot'],
            'city' => $address['city_name'],
            'region' => $address['region_name'],
            'country' => $address['country_name']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_address_delete', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while deleting your address. Please try again later.',
        'error_details' => 'Error deleting address: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_address_delete', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while deleting your address. Please try again later.',
        'error_details' => 'Error deleting address: ' . $e->getMessage()
    ]);
}
?>

