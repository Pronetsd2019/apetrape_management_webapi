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
 * Update Role Endpoint
 * PUT /roles/update.php
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

// Validate role_id (accept both 'id' and 'role_id')
$role_id = $input['role_id'] ?? $input['id'] ?? null;

if (!$role_id || empty($role_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

// Validate permissions array if provided
$permissions = $input['permissions'] ?? null;
if ($permissions !== null && !is_array($permissions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "permissions" must be an array.']);
    exit;
}

$pdo->beginTransaction();

try {
    // Check if role exists
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['role_name'])) {
        // Check if role_name already exists for another role
        $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = ? AND id != ?");
        $stmt->execute([$input['role_name'], $role_id]);
        if ($stmt->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Role name already exists.']);
            exit;
        }
        $update_fields[] = "role_name = ?";
        $params[] = $input['role_name'];
    }
    if (isset($input['description'])) {
        $update_fields[] = "description = ?";
        $params[] = $input['description'];
    }

    // Update role if there are fields to update
    if (!empty($update_fields)) {
        // Add role_id to params
        $params[] = $role_id;

        // Execute update
        $sql = "UPDATE roles SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Handle permissions update if provided
    if ($permissions !== null) {
        // Delete existing permissions for this role
        $stmt = $pdo->prepare("DELETE FROM role_module_permissions WHERE role_id = ?");
        $stmt->execute([$role_id]);

        // Insert new permissions
        if (!empty($permissions)) {
            $stmtInsertPermission = $pdo->prepare("
                INSERT INTO role_module_permissions (role_id, module_id, can_read, can_create, can_update, can_delete)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmtCheckModule = $pdo->prepare("SELECT id FROM modules WHERE id = ?");

            foreach ($permissions as $permission) {
                $module_id = $permission['module_id'] ?? null;
                
                if (!$module_id) {
                    continue; // Skip if module_id is missing
                }

                // Verify module exists
                $stmtCheckModule->execute([$module_id]);
                if (!$stmtCheckModule->fetch()) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => "Module with ID {$module_id} does not exist."
                    ]);
                    exit;
                }

                // Set permission flags (default to 0 if not provided)
                $can_read = isset($permission['can_read']) ? (int)$permission['can_read'] : 0;
                $can_create = isset($permission['can_create']) ? (int)$permission['can_create'] : 0;
                $can_update = isset($permission['can_update']) ? (int)$permission['can_update'] : 0;
                $can_delete = isset($permission['can_delete']) ? (int)$permission['can_delete'] : 0;

                // Insert permission
                $stmtInsertPermission->execute([
                    $role_id,
                    $module_id,
                    $can_read,
                    $can_create,
                    $can_update,
                    $can_delete
                ]);
            }
        }
    }

    $pdo->commit();

    // Fetch updated role with permissions
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.role_name,
            r.description,
            r.created_at
        FROM roles r
        WHERE r.id = ?
    ");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch permissions for the updated role
    $stmt = $pdo->prepare("
        SELECT 
            rmp.id,
            rmp.role_id,
            rmp.module_id,
            m.module_name,
            rmp.can_read,
            rmp.can_create,
            rmp.can_update,
            rmp.can_delete
        FROM role_module_permissions rmp
        INNER JOIN modules m ON rmp.module_id = m.id
        WHERE rmp.role_id = ?
        ORDER BY m.module_name ASC
    ");
    $stmt->execute([$role_id]);
    $role['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Role updated successfully.',
        'data' => $role
    ]);

} catch (PDOException $e) {
    logException('roles_update', $e);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating role: ' . $e->getMessage()
    ]);
}
?>

