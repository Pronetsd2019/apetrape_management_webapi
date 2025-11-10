<?php
/**
 * Create Supplier Endpoint
 * POST /suppliers/create.php
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
if (!isset($input['supplier_name']) || empty($input['supplier_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "supplier_name" is required.']);
    exit;
}

if (!isset($input['email']) || empty($input['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "email" is required.']);
    exit;
}

if (!isset($input['cell']) || empty($input['cell'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "cell" is required.']);
    exit;
}

if (!isset($input['password']) || empty($input['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "password" is required.']);
    exit;
}

if (!isset($input['password_confirmation']) || empty($input['password_confirmation'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "password_confirmation" is required.']);
    exit;
}

if ($input['password'] !== $input['password_confirmation']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password confirmation does not match.']);
    exit;
}

try {
    // Check if email already exists (if provided)
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already exists.']);
        exit;
    }

    // Hash password
    $password_hash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Insert supplier
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (name, email, cellphone, telephone, password_hash)
        VALUES (?, ?, ?, ?, ?)
    ");

    $email = $input['email'];
    $name = $input['supplier_name'];
    $cellphone = $input['cell'];
    $telephone = $input['telephone'] ?? null;


    $stmt->execute([
        $name,
        $email,
        $cellphone,
        $telephone,
        $password_hash
    ]);

    $supplier_id = $pdo->lastInsertId();

    // Fetch created supplier
    $stmt = $pdo->prepare("
        SELECT id, name, email, cellphone, telephone, created_at, updated_at
        FROM suppliers WHERE id = ?
    ");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier created successfully.',
        'data' => $supplier
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating supplier: ' . $e->getMessage()
    ]);
}
?>

