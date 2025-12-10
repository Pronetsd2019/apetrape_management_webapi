<?php
/**
 * Create Manufacturer Endpoint
 * POST /manufacturers/create.php
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
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create manufacturers.']);
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
if (!isset($input['manufacturer_name']) || empty($input['manufacturer_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "name" is required.']);
    exit;
}

try {
    // Check if manufacturer name already exists
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ?");
    $stmt->execute([$input['manufacturer_name']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Manufacturer name already exists.']);
        exit;
    }

    // Insert manufacturer
    $stmt = $pdo->prepare("
        INSERT INTO manufacturers (name)
        VALUES (?)
    ");

    $stmt->execute([
        $input['manufacturer_name']
    ]);

    $manufacturer_id = $pdo->lastInsertId();

    // Fetch created manufacturer
    $stmt = $pdo->prepare("SELECT id, name, created_at, updated_at FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer created successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    logException('manufacturer_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating manufacturer: ' . $e->getMessage()
    ]);
}
?>

