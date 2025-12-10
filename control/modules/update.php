<?php
/**
 * Update Module Endpoint
 * PUT /modules/update.php
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
 if (!checkUserPermission($userId, 'roles & permissions', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update roles & permissions.']);
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

// Validate module_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Module ID is required.']);
    exit;
}

$module_id = $input['id'];

try {
    // Check if module exists
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Module not found.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['module_name'])) {
        // Check if module_name already exists for another module
        $stmt = $pdo->prepare("SELECT id FROM modules WHERE module_name = ? AND id != ?");
        $stmt->execute([$input['module_name'], $module_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Module name already exists.']);
            exit;
        }
        $update_fields[] = "module_name = ?";
        $params[] = $input['module_name'];
    }
    if (isset($input['description'])) {
        $update_fields[] = "description = ?";
        $params[] = $input['description'];
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add module_id to params
    $params[] = $module_id;

    // Execute update
    $sql = "UPDATE modules SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated module
    $stmt = $pdo->prepare("SELECT id, module_name, description FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);
    $module = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Module updated successfully.',
        'data' => $module
    ]);

} catch (PDOException $e) {
    logException('modules_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating module: ' . $e->getMessage()
    ]);
}
?>

