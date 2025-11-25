<?php
/**
 * Update Role Status Endpoint
 * PUT /roles/update_status.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['role_id']) || empty($input['role_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "role_id" is required.']);
    exit;
}

if (!isset($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "status" is required.']);
    exit;
}

$role_id = $input['role_id'];
$status = $input['status'];

// Validate status is numeric
if (!is_numeric($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "status" must be a number.']);
    exit;
}

try {
    // Check if role exists
    $stmt = $pdo->prepare("SELECT id, role_name, status FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $existing_role = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_role) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE roles SET status = ? WHERE id = ?");
    $stmt->execute([$status, $role_id]);

    // Fetch updated role
    $stmt = $pdo->prepare("
        SELECT 
            id,
            role_name,
            description,
            status,
            created_at
        FROM roles
        WHERE id = ?
    ");
    $stmt->execute([$role_id]);
    $updated_role = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Role status updated successfully.',
        'data' => $updated_role
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating role status: ' . $e->getMessage()
    ]);
}
?>

