<?php
/**
 * Create Role Module Permission Endpoint
 * POST /role_module_permissions/create.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['role_id', 'module_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    // Validate role_id exists
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE id = ?");
    $stmt->execute([$input['role_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    // Validate module_id exists
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE id = ?");
    $stmt->execute([$input['module_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Module not found.']);
        exit;
    }

    // Check if permission already exists
    $stmt = $pdo->prepare("SELECT id FROM role_module_permissions WHERE role_id = ? AND module_id = ?");
    $stmt->execute([$input['role_id'], $input['module_id']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Permission already exists for this role and module.']);
        exit;
    }

    // Insert permission
    $stmt = $pdo->prepare("
        INSERT INTO role_module_permissions (role_id, module_id, can_read, can_create, can_update, can_delete)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $can_read = isset($input['can_read']) ? (int)$input['can_read'] : 0;
    $can_create = isset($input['can_create']) ? (int)$input['can_create'] : 0;
    $can_update = isset($input['can_update']) ? (int)$input['can_update'] : 0;
    $can_delete = isset($input['can_delete']) ? (int)$input['can_delete'] : 0;

    $stmt->execute([
        $input['role_id'],
        $input['module_id'],
        $can_read,
        $can_create,
        $can_update,
        $can_delete
    ]);

    $permission_id = $pdo->lastInsertId();

    // Fetch created permission
    $stmt = $pdo->prepare("
        SELECT id, role_id, module_id, can_read, can_create, can_update, can_delete
        FROM role_module_permissions WHERE id = ?
    ");
    $stmt->execute([$permission_id]);
    $permission = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Permission created successfully.',
        'data' => $permission
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating permission: ' . $e->getMessage()
    ]);
}
?>

