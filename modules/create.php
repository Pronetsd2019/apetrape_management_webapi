<?php
/**
 * Create Module Endpoint
 * POST /modules/create.php
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
if (!isset($input['module_name']) || empty($input['module_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "module_name" is required.']);
    exit;
}

try {
    // Check if module_name already exists
    $stmt = $pdo->prepare("SELECT id FROM modules WHERE module_name = ?");
    $stmt->execute([$input['module_name']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Module name already exists.']);
        exit;
    }

    // Insert module
    $stmt = $pdo->prepare("
        INSERT INTO modules (module_name, description)
        VALUES (?, ?)
    ");

    $description = $input['description'] ?? null;

    $stmt->execute([
        $input['module_name'],
        $description
    ]);

    $module_id = $pdo->lastInsertId();

    // Fetch created module
    $stmt = $pdo->prepare("SELECT id, module_name, description FROM modules WHERE id = ?");
    $stmt->execute([$module_id]);
    $module = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Module created successfully.',
        'data' => $module
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating module: ' . $e->getMessage()
    ]);
}
?>

