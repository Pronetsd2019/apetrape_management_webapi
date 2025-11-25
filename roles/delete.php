<?php
/**
 * Delete Role Endpoint
 * DELETE /roles/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get role ID from query string or JSON body
$role_id = null;
if (isset($_GET['role_id'])) {
    $role_id = $_GET['role_id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $role_id = $input['role_id'] ?? null;
}

if (!$role_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

try {
    // Check if role exists
    $stmt = $pdo->prepare("SELECT id, role_name, description FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch();

    if (!$role) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    // Check if role is being used by any admin
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM admins WHERE role_id = ?");
    $stmt->execute([$role_id]);
    $result = $stmt->fetch();
    if ($result['count'] > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete role. It is being used by ' . $result['count'] . ' admin(s).'
        ]);
        exit;
    }

    // Begin transaction for atomic deletion
    $pdo->beginTransaction();

    try {
        // Delete role permissions first
        $stmt = $pdo->prepare("DELETE FROM role_module_permissions WHERE role_id = ?");
        $stmt->execute([$role_id]);

        // Delete role
        $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
        $stmt->execute([$role_id]);

        // Commit transaction
        $pdo->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Role deleted successfully.',
            'data' => $role
        ]);

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e; // Re-throw to be caught by outer catch block
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting role: ' . $e->getMessage()
    ]);
}
?>

