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
 * Create Module Endpoint
 * POST /modules/create.php
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
 if (!checkUserPermission($userId, 'roles & permissions', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create roles & permissions.']);
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

// Validate required fields
if (!isset($input['module_name']) || empty($input['module_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "module_name" is required.']);
    exit;
}

try {
    // Check if module_name already exists
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE module_name = ?");
    $stmt->execute([$input['module_name']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Module name already exists.']);
        exit;
    }

    // Insert module
    $stmt = $pdo->prepare("
        INSERT INTO modules (module_name, description)
        VALUES (?, ?)
    ");

    $description = $input['description'] ?? null;

    $stmt->execute([
        $input['module_name'],
        $description
    ]);

    $module_id = $pdo->lastInsertId();

    // Fetch created module
    $stmt = $pdo->prepare("SELECT id, module_name, description FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);
    $module = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Module created successfully.',
        'data' => $module
    ]);

} catch (PDOException $e) {
    logException('modules_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating module: ' . $e->getMessage()
    ]);
}
?>

