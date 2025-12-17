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
 * Get Users by Role ID Endpoint
 * GET /role_module_permissions/get_users.php?id={role_id}
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

// Check if the user has permission to read roles & permissions
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
    // Get role ID from query parameters
    $roleId = $_GET['role_id'] ?? null;

    // Validate role ID
    if (!$roleId || !is_numeric($roleId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Valid role ID is required as query parameter'
        ]);
        exit;
    }

    $roleId = (int)$roleId;

    // Check if role exists
    $stmt = $pdo->prepare("SELECT id, role_name, description, status FROM roles WHERE id = ?");
    $stmt->execute([$roleId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$role) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Role not found with ID: ' . $roleId
        ]);
        exit;
    }

    // Get all admins/users associated with this role
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.name,
            a.surname,
            a.email,
            a.cell,
            a.role_id,
            a.is_active,
            a.locked_until,
            a.failed_attempts,
            a.created_at,
            a.updated_at
        FROM admins a
        WHERE a.role_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$roleId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert status fields to integers
    foreach ($users as &$user) {
        $user['id'] = (int)$user['id'];
        $user['role_id'] = (int)$user['role_id'];
        $user['is_active'] = (int)$user['is_active'];
        $user['failed_attempts'] = (int)($user['failed_attempts'] ?? 0);
    }
    unset($user);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Users retrieved successfully.',
        'role' => $role,
        'data' => $users,
        'count' => count($users)
    ]);

} catch (PDOException $e) {
    logException('role_module_permissions_get_users', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching users: ' . $e->getMessage()
    ]);
}
?>
