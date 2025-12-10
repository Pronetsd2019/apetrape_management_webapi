<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
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
 * Update City Endpoint
 * PUT /city/update.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'locations', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update a city.']);
     exit;
 }
 

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required field
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'City ID is required.']);
    exit;
}

$city_id = $input['id'];

try {
    // Fetch existing city with region info
    $stmt = $pdo->prepare("
        SELECT ci.id, ci.name, ci.region_id, ci.entry, r.country_id
        FROM city ci
        LEFT JOIN region r ON ci.region_id = r.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$city_id]);
    $city = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$city) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'City not found.']);
        exit;
    }

    $update_fields = [];
    $params = [];

    // Handle region change if provided
    $target_region_id = $city['region_id'];
    if (isset($input['region_id'])) {
        if ($input['region_id'] === null || $input['region_id'] === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Region ID cannot be empty.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM region WHERE id = ?");
        $stmt->execute([$input['region_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Region not found.']);
            exit;
        }

        $update_fields[] = "region_id = ?";
        $params[] = $input['region_id'];
        $target_region_id = $input['region_id'];
    }

    // Handle name change if provided
    if (isset($input['city_name'])) {
        $name = trim($input['city_name']);
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'City name cannot be empty.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM city WHERE LOWER(name) = LOWER(?) AND region_id = ? AND id != ?");
        $stmt->execute([$name, $target_region_id, $city_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'City name already exists for this region.']);
            exit;
        }

        $update_fields[] = "name = ?";
        $params[] = $name;
    }

    // Handle entry change
    if (array_key_exists('entry', $input)) {
        $update_fields[] = "entry = ?";
        $params[] = $input['entry'] !== '' ? $input['entry'] : null;
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    $update_fields[] = "updated_at = NOW()";
    $params[] = $city_id;

    $sql = "UPDATE city SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated city with region/country details
    $stmt = $pdo->prepare("
        SELECT 
            ci.id AS city_id,
            ci.name AS city_name,
            ci.region_id,
            r.name AS region_name,
            r.country_id,
            c.name AS country_name,
            ci.entry,
            ci.updated_at
        FROM city ci
        LEFT JOIN region r ON ci.region_id = r.id
        LEFT JOIN country c ON r.country_id = c.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$city_id]);
    $updated_city = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'City updated successfully.',
        'data' => $updated_city
    ]);

} catch (PDOException $e) {
    logException('city_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating city: ' . $e->getMessage()
    ]);
}
?>


