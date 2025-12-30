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
        $city_id = isset($addr['city_id']) ? trim($addr['city_id']) : null;
        $plot = isset($addr['plot']) ? trim($addr['plot']) : null;
        $street = isset($addr['street']) ? trim($addr['street']) : null;

        // Validate required fields for this address
        if (!$address_id || !is_numeric($address_id)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Valid address ID is required.'
            ];
            continue;
        }

        if (!$city_id || !is_numeric($city_id)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Valid city_id is required.'
            ];
            continue;
        }

        if (empty($plot)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Plot is required.'
            ];
            continue;
        }

        if (empty($street)) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'Street is required.'
            ];
            continue;
        }

        $address_id = (int)$address_id;
        $city_id = (int)$city_id;

        // Check if address exists and belongs to this user
        $stmt = $pdo->prepare("SELECT id, user_id FROM user_address WHERE id = ? AND user_id = ?");
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

        // Validate city exists
        $stmt = $pdo->prepare("SELECT id, name FROM city WHERE id = ?");
        $stmt->execute([$city_id]);
        $city = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$city) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'City not found.'
            ];
            continue;
        }

        // Check if user already has the exact same address (excluding current address)
        $stmt = $pdo->prepare("
            SELECT id 
            FROM user_address 
            WHERE user_id = ? 
            AND city = ? 
            AND LOWER(TRIM(plot)) = LOWER(TRIM(?)) 
            AND LOWER(TRIM(street)) = LOWER(TRIM(?))
            AND id != ?
        ");
        $stmt->execute([$user_id, $city_id, $plot, $street, $address_id]);
        $duplicate_address = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($duplicate_address) {
            $errors[] = [
                'index' => $index,
                'address_id' => $address_id,
                'error' => 'You already have this address registered.'
            ];
            continue;
        }

        // Update the address
        $stmt = $pdo->prepare("
            UPDATE user_address 
            SET city = ?, plot = ?, street = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $update_result = $stmt->execute([$city_id, $plot, $street, $address_id, $user_id]);

        if ($update_result && $stmt->rowCount() > 0) {
            // Fetch updated address with location hierarchy
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
                WHERE ua.id = ?
            ");
            $stmt->execute([$address_id]);
            $updated_address = $stmt->fetch(PDO::FETCH_ASSOC);

            $results[] = [
                'index' => $index,
                'address_id' => $address_id,
                'success' => true,
                'data' => [
                    'id' => (int)$updated_address['id'],
                    'street' => $updated_address['street'],
                    'plot' => $updated_address['plot'],
                    'city' => [
                        'id' => (int)$updated_address['city_id'],
                        'name' => $updated_address['city_name']
                    ],
                    'region' => [
                        'id' => (int)$updated_address['region_id'],
                        'name' => $updated_address['region_name']
                    ],
                    'country' => [
                        'id' => (int)$updated_address['country_id'],
                        'name' => $updated_address['country_name']
                    ],
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

