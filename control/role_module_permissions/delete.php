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
 * Delete Role Module Permission Endpoint
 * DELETE /role_module_permissions/delete.php
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

// Get permission ID from query string or JSON body
$permission_id = null;
if (isset($_GET['id'])) {
    $permission_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $permission_id = $input['id'] ?? null;
}

if (!$permission_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Permission ID is required.']);
    exit;
}

try {
    // Check if permission exists
    $stmt = $pdo->prepare("
        SELECT id, role_id, module_id, can_read, can_create, can_update, can_delete
        FROM role_module_permissions WHERE id = ?
    ");
    $stmt->execute([$permission_id]);
    $permission = $stmt->fetch();

    if (!$permission) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Permission not found.']);
        exit;
    }

    // Delete permission
    $stmt = $pdo->prepare("DELETE FROM role_module_permissions WHERE id = ?");
    $stmt->execute([$permission_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Permission deleted successfully.',
        'data' => $permission
    ]);

} catch (PDOException $e) {
    logException('role_module_permissions_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting permission: ' . $e->getMessage()
    ]);
}
?>

