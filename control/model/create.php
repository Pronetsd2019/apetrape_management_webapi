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
 * Create Vehicle Model Endpoint
 * POST /vehicle_models/create.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create models.']);
     exit;
 }

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Required fields
$required_fields = ['manufacturer_id', 'model_name', 'variant'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// year_from and year_to are optional
$year_from = isset($input['year_from']) && $input['year_from'] !== '' && is_numeric($input['year_from']) ? (int)$input['year_from'] : null;
$year_to = isset($input['year_to']) && $input['year_to'] !== '' && is_numeric($input['year_to']) ? (int)$input['year_to'] : null;

try {
    // Validate manufacturer_id exists
    $manufacturer_id = (int)$input['manufacturer_id'];
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // When both year_from and year_to are provided, validate them
    if ($year_from !== null && $year_to !== null) {
        if ($year_from <= 0 || $year_to <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Fields \'year_from\' and \'year_to\' must be positive integers when provided.']);
            exit;
        }
        if ($year_to < $year_from) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => '\'year_to\' cannot be less than \'year_from\'.']);
            exit;
        }
    }

    $model_name = trim((string)$input['model_name']);
    $variant = trim((string)$input['variant']);

    // Insert vehicle model (year_from, year_to optional)
    $stmt = $pdo->prepare("
        INSERT INTO vehicle_models (manufacturer_id, model_name, variant, year_from, year_to)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $manufacturer_id,
        $model_name,
        $variant,
        $year_from,
        $year_to
    ]);

    $vehicle_model_id = $pdo->lastInsertId();

    // Fetch created vehicle model
    $stmt = $pdo->prepare("
        SELECT vm.id, vm.manufacturer_id, vm.model_name, vm.variant,
               vm.year_from, vm.year_to, vm.created_at, vm.updated_at,
               m.name AS manufacturer_name
        FROM vehicle_models vm
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE vm.id = ?
    ");
    $stmt->execute([$vehicle_model_id]);
    $vehicle_model = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle model created successfully.',
        'data' => $vehicle_model
    ]);

} catch (PDOException $e) {
    logException('model_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating vehicle model: ' . $e->getMessage()
    ]);
}
?>

