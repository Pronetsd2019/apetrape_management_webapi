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
 * Mobile User Address Update Endpoint
 * PUT /mobile/v1/address/update.php
 * Supports both single and multiple address updates
 * Requires JWT authentication - user can only update their own addresses
 * Accepts Google Places API format address data
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
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

// Normalize input to array format (support both single object and array)
$addresses_to_update = [];
if (isset($input['id'])) {
    // Single address update
    $addresses_to_update = [$input];
} elseif (isset($input[0]) && is_array($input[0])) {
    // Multiple addresses update (array format)
    $addresses_to_update = $input;
} elseif (is_array($input) && isset($input['addresses']) && is_array($input['addresses'])) {
    // Multiple addresses update (nested format)
    $addresses_to_update = $input['addresses'];
} else {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Invalid request format. Provide a single address object or an array of addresses.'
    ]);
    exit;
}

if (empty($addresses_to_update)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'At least one address is required for update.'
    ]);
    exit;
}

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

    $results = [];
    $errors = [];
    $pdo->beginTransaction();

    foreach ($addresses_to_update as $index => $addr) {
        $address_id = isset($addr['id']) ? trim($addr['id']) : null;
        $place_id = isset($addr['place_id']) ? trim($addr['place_id']) : null;
        $formatted_address = isset($addr['formatted_address']) ? trim($addr['formatted_address']) : null;
        $latitude = isset($addr['latitude']) ? $addr['latitude'] : null;
        $longitude = isset($addr['longitude']) ? $addr['longitude'] : null;

        // Validate required fields for this address
        if (!$address_id || !is_numeric($address_id)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Valid address ID is required.'
            ];
            continue;
        }

        if (empty($place_id)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Place ID is required.'
            ];
            continue;
        }

        if (empty($formatted_address)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Formatted address is required.'
            ];
            continue;
        }

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Latitude and longitude must be valid numbers.'
            ];
            continue;
        }

        $address_id = (int)$address_id;
        $latitude = (float)$latitude;
        $longitude = (float)$longitude;

        // Validate latitude range (-90 to 90)
        if ($latitude < -90 || $latitude > 90) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Latitude must be between -90 and 90.'
            ];
            continue;
        }

        // Validate longitude range (-180 to 180)
        if ($longitude < -180 || $longitude > 180) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Longitude must be between -180 and 180.'
            ];
            continue;
        }

        // Check if address exists and belongs to this user
        $stmt = $pdo->prepare("SELECT id, user_id FROM user_addresses WHERE id = ? AND user_id = ?");
        $stmt->execute([$address_id, $user_id]);
        $existing_address = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing_address) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Address not found or does not belong to you.'
            ];
            continue;
        }

        // Check if user already has an address with the same place_id (excluding current address)
        $stmt = $pdo->prepare("
            SELECT id 
            FROM user_addresses 
            WHERE user_id = ? 
            AND place_id = ?
            AND id != ?
        ");
        $stmt->execute([$user_id, $place_id, $address_id]);
        $duplicate_address = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($duplicate_address) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'You already have this address registered.'
            ];
            continue;
        }

        // Extract address components (all optional)
        $address_components = $addr['address_components'] ?? [];
        $street_number = isset($address_components['street_number']) ? trim($address_components['street_number']) : null;
        $street = isset($address_components['street']) ? trim($address_components['street']) : null;
        $city = isset($address_components['city']) ? trim($address_components['city']) : null;
        $sublocality = isset($address_components['sublocality']) ? trim($address_components['sublocality']) : null;
        $district = isset($address_components['district']) ? trim($address_components['district']) : null;
        $region = isset($address_components['region']) ? trim($address_components['region']) : null;
        $country = isset($address_components['country']) ? trim($address_components['country']) : null;
        $country_code = isset($address_components['country_code']) ? trim($address_components['country_code']) : null;
        $postal_code = isset($address_components['postal_code']) ? trim($address_components['postal_code']) : null;

        // Update the address
        $stmt = $pdo->prepare("
            UPDATE user_addresses 
            SET 
                place_id = ?,
                formatted_address = ?,
                latitude = ?,
                longitude = ?,
                street_number = ?,
                street = ?,
                sublocality = ?,
                city = ?,
                district = ?,
                region = ?,
                country = ?,
                country_code = ?,
                postal_code = ?,
                updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $update_result = $stmt->execute([
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
            $postal_code,
            $address_id,
            $user_id
        ]);

        if ($update_result && $stmt->rowCount() > 0) {
            // Fetch updated address
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
            $updated_address = $stmt->fetch(PDO::FETCH_ASSOC);

            $results[] = [
                'index' => $index,
                'address_id' => $address_id,
                'success' => true,
                'data' => [
                    'id' => (int)$updated_address['id'],
                    'place_id' => $updated_address['place_id'],
                    'formatted_address' => $updated_address['formatted_address'],
                    'latitude' => (float)$updated_address['latitude'],
                    'longitude' => (float)$updated_address['longitude'],
                    'street_number' => $updated_address['street_number'],
                    'street' => $updated_address['street'],
                    'sublocality' => $updated_address['sublocality'],
                    'city' => $updated_address['city'],
                    'district' => $updated_address['district'],
                    'region' => $updated_address['region'],
                    'country' => $updated_address['country'],
                    'country_code' => $updated_address['country_code'],
                    'postal_code' => $updated_address['postal_code'],
                    'created_at' => $updated_address['created_at'],
                    'updated_at' => $updated_address['updated_at']
                ]
            ];
        } else {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Failed to update address. No changes were made.'
            ];
        }
    }

    // If there are errors and no successful updates, rollback
    if (!empty($errors) && empty($results)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Failed to update addresses.',
            'errors' => $errors
        ]);
        exit;
    }

    // Commit transaction if at least one update succeeded
    if (!empty($results)) {
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }

    // Log the update
    logError('mobile_address_update', 'User addresses updated', [
        'user_id' => $user_id,
        'total_requested' => count($addresses_to_update),
        'successful' => count($results),
        'failed' => count($errors),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => count($results) . ' address(es) updated successfully.',
        'data' => $results,
        'errors' => $errors,
        'summary' => [
            'total' => count($addresses_to_update),
            'successful' => count($results),
            'failed' => count($errors)
        ]
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logException('mobile_address_update', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your addresses. Please try again later.',
        'error_details' => 'Error updating addresses: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logException('mobile_address_update', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your addresses. Please try again later.',
        'error_details' => 'Error updating addresses: ' . $e->getMessage()
    ]);
}
?>

