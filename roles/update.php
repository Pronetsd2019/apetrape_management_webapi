<?php
/**
 * Update Role Endpoint
 * PUT /roles/update.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate role_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

$role_id = $input['id'];

try {
    // Check if role exists
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    if (!$stmt->fetch()) {
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

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add role_id to params
    $params[] = $role_id;

    // Execute update
    $sql = "UPDATE roles SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated role
    $stmt = $pdo->prepare("SELECT id, role_name, description FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Role updated successfully.',
        'data' => $role
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating role: ' . $e->getMessage()
    ]);
}
?>

