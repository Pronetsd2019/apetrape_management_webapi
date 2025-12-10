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
 * Update Vehicle Model Endpoint
 * PUT /vehicle_models/update.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update models.']);
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

// Validate vehicle_model_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vehicle model ID is required.']);
    exit;
}

$vehicle_model_id = $input['id'];

try {
    // Check if vehicle model exists
    $stmt = $pdo->prepare("SELECT id FROM vehicle_models WHERE id = ?");
    $stmt->execute([$vehicle_model_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Vehicle model not found.']);
        exit;
    }

    // Build update query dynamically
    $update_fields = [];
    $params = [];

    if (isset($input['manufacturer_id'])) {
        // Validate manufacturer_id exists
        $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE id = ?");
        $stmt->execute([$input['manufacturer_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
            exit;
        }
        $update_fields[] = "manufacturer_id = ?";
        $params[] = $input['manufacturer_id'];
    }
    if (isset($input['model_name'])) {
        $update_fields[] = "model_name = ?";
        $params[] = $input['model_name'];
    }
    if (isset($input['variant'])) {
        $update_fields[] = "variant = ?";
        $params[] = $input['variant'];
    }
    if (isset($input['year_from'])) {
        $update_fields[] = "year_from = ?";
        $params[] = (int)$input['year_from'];
    }
    if (isset($input['year_to'])) {
        $update_fields[] = "year_to = ?";
        $params[] = (int)$input['year_to'];
    }


    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    $params[] = $vehicle_model_id;

    // Execute update
    $sql = "UPDATE vehicle_models SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated vehicle model
    $stmt = $pdo->prepare("
        SELECT vm.id, vm.manufacturer_id, vm.model_name, vm.variant, 
               vm.year_from, vm.year_to, vm.created_at, vm.updated_at,
               m.name as manufacturer_name
        FROM vehicle_models vm
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE vm.id = ?
    ");
    $stmt->execute([$vehicle_model_id]);
    $vehicle_model = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle model updated successfully.',
        'data' => $vehicle_model
    ]);

} catch (PDOException $e) {
    logException('model_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating vehicle model: ' . $e->getMessage()
    ]);
}
?>

