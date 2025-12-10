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
 * Get Vehicle Models by Manufacturer Endpoint
 * GET /vehicle_models/get_by_manufacturer.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read models.']);
     exit;
 }

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$manufacturer_id = $_GET['manufacturer_id'] ?? null;

if (!$manufacturer_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Manufacturer ID is required.']);
    exit;
}

try {
    // Validate manufacturer exists
    $stmt = $pdo->prepare("SELECT id, name FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manufacturer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            vm.id AS model_id,
            vm.model_name,
            vm.variant,
            vm.year_from,
            vm.year_to,
            vm.manufacturer_id,
            :manufacturer_name AS manufacturer_name,
            vm.created_at,
            vm.updated_at
        FROM vehicle_models vm
        WHERE vm.manufacturer_id = :manufacturer_id
        ORDER BY vm.model_name ASC
    ");
    $stmt->execute([
        ':manufacturer_id' => $manufacturer_id,
        ':manufacturer_name' => $manufacturer['name']
    ]);

    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle models fetched successfully.',
        'manufacturer' => $manufacturer,
        'data' => $models,
        'count' => count($models)
    ]);

} catch (PDOException $e) {
    logException('model_get_by_manufacturer', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching vehicle models: ' . $e->getMessage()
    ]);
}
?>


