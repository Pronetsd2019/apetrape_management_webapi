<?php
/**
 * Delete Role Module Permission Endpoint
 * DELETE /role_module_permissions/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting permission: ' . $e->getMessage()
    ]);
}
?>

