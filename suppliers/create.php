<?php
/**
 * Create Supplier Endpoint
 * POST /suppliers/create.php
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
if (!isset($input['name']) || empty($input['name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "name" is required.']);
    exit;
}

try {
    // Check if email already exists (if provided)
    if (isset($input['email']) && !empty($input['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE email = ?");
        $stmt->execute([$input['email']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
    }

    // Insert supplier
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (name, email, cellphone, telephone)
        VALUES (?, ?, ?, ?)
    ");

    $email = $input['email'] ?? null;
    $cellphone = $input['cellphone'] ?? null;
    $telephone = $input['telephone'] ?? null;

    $stmt->execute([
        $input['name'],
        $email,
        $cellphone,
        $telephone
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

