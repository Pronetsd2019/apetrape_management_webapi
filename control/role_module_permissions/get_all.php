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
 * Get All Roles with Permissions Endpoint
 * GET /role_module_permissions/get_all.php
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
 if (!checkUserPermission($userId, 'roles & permissions', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read roles & permissions.']);
     exit;
 }


// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    // Get all roles with user count
    $stmt = $pdo->query("
        SELECT 
            r.id,
            r.role_name,
            r.description,
            r.status,
            r.created_at,
            COUNT(DISTINCT a.id) AS admin_count
        FROM roles r
        LEFT JOIN admins a ON a.role_id = r.id
        GROUP BY r.id, r.role_name, r.description, r.created_at
        ORDER BY r.id ASC
    ");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($roles)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No roles found.',
            'data' => [],
            'count' => 0
        ]);
        exit;
    }

    // Get all role IDs
    $roleIds = array_column($roles, 'id');
    $placeholders = implode(',', array_fill(0, count($roleIds), '?'));

    // Fetch all permissions for these roles
    $stmtPermissions = $pdo->prepare("
        SELECT 
            rmp.id,
            rmp.role_id,
            rmp.module_id,
            m.module_name,
            m.description AS module_description,
            rmp.can_read,
            rmp.can_create,
            rmp.can_update,
            rmp.can_delete
        FROM role_module_permissions rmp
        INNER JOIN modules m ON rmp.module_id = m.id
        WHERE rmp.role_id IN ($placeholders)
        ORDER BY rmp.role_id ASC, m.module_name ASC
    ");
    $stmtPermissions->execute($roleIds);
    $permissions = $stmtPermissions->fetchAll(PDO::FETCH_ASSOC);

    // Group permissions by role_id
    $permissionsByRole = [];
    foreach ($permissions as $permission) {
        $roleId = $permission['role_id'];
        // Remove module_description from the output as it's not needed
        unset($permission['module_description']);
        $permissionsByRole[$roleId][] = $permission;
    }

    // Attach permissions to each role
    foreach ($roles as &$role) {
        $roleId = $role['id'];
        $role['permissions'] = $permissionsByRole[$roleId] ?? [];
    }
    unset($role);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Roles retrieved successfully.',
        'data' => $roles,
        'count' => count($roles)
    ]);

} catch (PDOException $e) {
    logException('role_module_permissions_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching roles: ' . $e->getMessage()
    ]);
}
?>

