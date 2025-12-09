<?php
/**
 * Update Role Module Permission Endpoint
 * PUT /role_module_permissions/update.php
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

// Validate permission_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Permission ID is required.']);
    exit;
}

$permission_id = $input['id'];

try {
    // Check if permission exists
    $stmt = $pdo->prepare("SELECT id FROM role_module_permissions WHERE id = ?");
    $stmt->execute([$permission_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permission not found.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['role_id'])) {
        // Validate role_id exists
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
        $stmt->execute([$input['role_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Role not found.']);
            exit;
        }
        $update_fields[] = "role_id = ?";
        $params[] = $input['role_id'];
    }
    if (isset($input['module_id'])) {
        // Validate module_id exists
        $stmt = $pdo->prepare("SELECT id FROM modules WHERE id = ?");
        $stmt->execute([$input['module_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Module not found.']);
            exit;
        }
        $update_fields[] = "module_id = ?";
        $params[] = $input['module_id'];
    }
    if (isset($input['can_read'])) {
        $update_fields[] = "can_read = ?";
        $params[] = (int)$input['can_read'];
    }
    if (isset($input['can_create'])) {
        $update_fields[] = "can_create = ?";
        $params[] = (int)$input['can_create'];
    }
    if (isset($input['can_update'])) {
        $update_fields[] = "can_update = ?";
        $params[] = (int)$input['can_update'];
    }
    if (isset($input['can_delete'])) {
        $update_fields[] = "can_delete = ?";
        $params[] = (int)$input['can_delete'];
    }

    // If role_id or module_id is being updated, check for duplicate
    if (isset($input['role_id']) || isset($input['module_id'])) {
        // Get current values
        $stmt = $pdo->prepare("SELECT role_id, module_id FROM role_module_permissions WHERE id = ?");
        $stmt->execute([$permission_id]);
        $current = $stmt->fetch();
        
        $check_role_id = $input['role_id'] ?? $current['role_id'];
        $check_module_id = $input['module_id'] ?? $current['module_id'];
        
        $stmt = $pdo->prepare("SELECT id FROM role_module_permissions WHERE role_id = ? AND module_id = ? AND id != ?");
        $stmt->execute([$check_role_id, $check_module_id, $permission_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Permission already exists for this role and module.']);
            exit;
        }
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add permission_id to params
    $params[] = $permission_id;

    // Execute update
    $sql = "UPDATE role_module_permissions SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated permission
    $stmt = $pdo->prepare("
        SELECT id, role_id, module_id, can_read, can_create, can_update, can_delete
        FROM role_module_permissions WHERE id = ?
    ");
    $stmt->execute([$permission_id]);
    $permission = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Permission updated successfully.',
        'data' => $permission
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating permission: ' . $e->getMessage()
    ]);
}
?>

