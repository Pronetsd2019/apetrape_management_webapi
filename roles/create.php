<?php
/**
 * Create Role Endpoint
 * POST /roles/create.php
 */

require_once __DIR__ . '/../util/connect.php';
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
if (!isset($input['role_name']) || empty($input['role_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "role_name" is required.']);
    exit;
}

try {
    // Check if role_name already exists
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_name = ?");
    $stmt->execute([$input['role_name']]);
    if ($stmt->fetch()) {
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

    // Fetch created role
    $stmt = $pdo->prepare("SELECT id, role_name, description FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Role created successfully.',
        'data' => $role
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating role: ' . $e->getMessage()
    ]);
}
?>

