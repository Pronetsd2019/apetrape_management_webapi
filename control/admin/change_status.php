<?php
/**
 * Change Admin Status Endpoint
 * PUT /admin/change_status.php
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
 if (!checkUserPermission($userId, 'administration', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update administrators.']);
     exit;
 }

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['id', 'status'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

$admin_id = $input['id'];
$status = $input['status'];

if (!is_numeric($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Status must be a numeric value.']);
    exit;
}

$status = (int)$status;

try {
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id, is_active FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("
        UPDATE admins
        SET is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $admin_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Admin status updated successfully.',
        'data' => [
            'previous_status' => (int)$admin['is_active'],
            'current_status' => $status
        ]
    ]);

} catch (PDOException $e) {
    logException('admin_change_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating admin status: ' . $e->getMessage()
    ]);
}
?>


