<?php
/**
 * Create Role Endpoint
 * POST /roles/create.php
 */

 require_once __DIR__ . '/../util/connect.php';
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
 if (!checkUserPermission($userId, 'roles & permissions', 'write')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create roles & permissions.']);
     exit;
 }


// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['role_name']) || empty($input['role_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "role_name" is required.']);
    exit;
}

// Validate permissions array
$permissions = $input['permissions'] ?? [];
if (!is_array($permissions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "permissions" must be an array.']);
    exit;
}

$pdo->beginTransaction();

try {
    // Check if role_name already exists
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = ?");
    $stmt->execute([$input['role_name']]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Role name already exists.']);
        exit;
    }

    // Insert role
    $stmt = $pdo->prepare("
        INSERT INTO roles (role_name, description)
        VALUES (?, ?)
    ");

    $description = $input['description'] ?? null;

    $stmt->execute([
        $input['role_name'],
        $description
    ]);

    $role_id = $pdo->lastInsertId();

    // Insert permissions if provided
    if (!empty($permissions)) {
        $stmtInsertPermission = $pdo->prepare("
            INSERT INTO role_module_permissions (role_id, module_id, can_read, can_create, can_update, can_delete)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmtCheckModule = $pdo->prepare("SELECT id FROM modules WHERE id = ?");

        foreach ($permissions as $permission) {
            $module_id = $permission['module_id'] ?? null;
            
            if (!$module_id) {
                continue; // Skip if module_id is missing
            }

            // Verify module exists
            $stmtCheckModule->execute([$module_id]);
            if (!$stmtCheckModule->fetch()) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Module with ID {$module_id} does not exist."
                ]);
                exit;
            }

            // Set permission flags (default to 0 if not provided)
            $can_read = isset($permission['can_read']) ? (int)$permission['can_read'] : 0;
            $can_create = isset($permission['can_create']) ? (int)$permission['can_create'] : 0;
            $can_update = isset($permission['can_update']) ? (int)$permission['can_update'] : 0;
            $can_delete = isset($permission['can_delete']) ? (int)$permission['can_delete'] : 0;

            // Insert permission
            $stmtInsertPermission->execute([
                $role_id,
                $module_id,
                $can_read,
                $can_create,
                $can_update,
                $can_delete
            ]);
        }
    }

    $pdo->commit();

    // Fetch created role with permissions
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.role_name,
            r.description,
            r.created_at
        FROM roles r
        WHERE r.id = ?
    ");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch permissions for the created role
    $stmt = $pdo->prepare("
        SELECT 
            rmp.id,
            rmp.role_id,
            rmp.module_id,
            m.module_name,
            rmp.can_read,
            rmp.can_create,
            rmp.can_update,
            rmp.can_delete
        FROM role_module_permissions rmp
        INNER JOIN modules m ON rmp.module_id = m.id
        WHERE rmp.role_id = ?
        ORDER BY m.module_name ASC
    ");
    $stmt->execute([$role_id]);
    $role['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Role created successfully.',
        'data' => $role
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating role: ' . $e->getMessage()
    ]);
}
?>

