<?php
/**
 * Create Admin Endpoint
 * POST /admin/create.php
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
 if (!checkUserPermission($userId, 'administration', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create administrators.']);
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
$required_fields = ['name', 'surname', 'email', 'cell', 'password', 'password_confirmation', 'role_id'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// Validate password confirmation
if ($input['password'] !== $input['password_confirmation']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password confirmation does not match.']);
    exit;
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

    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already exists.']);
        exit;
    }

    // Hash password
    $password_hash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Insert admin
    $stmt = $pdo->prepare("
        INSERT INTO admins (name, surname, email, cell, password_hash, role_id, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;

    $stmt->execute([
        $input['name'],
        $input['surname'],
        $input['email'],
        $input['cell'],
        $password_hash,
        $input['role_id'],
        $is_active
    ]);

    $admin_id = $pdo->lastInsertId();

    // Fetch created admin
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, role_id, is_active, created_at
        FROM admins WHERE id = ?
    ");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Admin created successfully.',
        'data' => $admin
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating admin: ' . $e->getMessage()
    ]);
}
?>

