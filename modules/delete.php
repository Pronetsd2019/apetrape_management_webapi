<?php
/**
 * Delete Module Endpoint
 * DELETE /modules/delete.php
 */

 require_once __DIR__ . '/../util/connect.php';
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
 if (!checkUserPermission($userId, 'roles & permissions', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete roles & permissions.']);
     exit;
 }


// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get module ID from query string or JSON body
$module_id = null;
if (isset($_GET['id'])) {
    $module_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $module_id = $input['id'] ?? null;
}

if (!$module_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Module ID is required.']);
    exit;
}

try {
    // Check if module exists
    $stmt = $pdo->prepare("SELECT id, module_name, description FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);
    $module = $stmt->fetch();

    if (!$module) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Module not found.']);
        exit;
    }

    // Delete module (CASCADE will handle role_module_permissions)
    $stmt = $pdo->prepare("DELETE FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Module deleted successfully.',
        'data' => $module
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting module: ' . $e->getMessage()
    ]);
}
?>

