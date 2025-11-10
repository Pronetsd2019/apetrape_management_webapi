<?php
/**
 * Update Admin Endpoint
 * PUT /admin/update.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate admin_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Admin ID is required.']);
    exit;
}

$admin_id = $input['id'];

try {
    // Check if admin exists
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['name'])) {
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['surname'])) {
        $update_fields[] = "surname = ?";
        $params[] = $input['surname'];
    }
    if (isset($input['email'])) {
        // Check if email already exists for another admin
        $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ? AND id != ?");
        $stmt->execute([$input['email'], $admin_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $update_fields[] = "email = ?";
        $params[] = $input['email'];
    }
    if (isset($input['cell'])) {
        $update_fields[] = "cell = ?";
        $params[] = $input['cell'];
    }
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
    if (isset($input['is_active'])) {
        $update_fields[] = "is_active = ?";
        $params[] = (int)$input['is_active'];
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add admin_id to params
    $params[] = $admin_id;

    // Execute update
    $sql = "UPDATE admins SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated admin
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, role_id, is_active, created_at
        FROM admins WHERE id = ?
    ");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Admin updated successfully.',
        'data' => $admin
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating admin: ' . $e->getMessage()
    ]);
}
?>

