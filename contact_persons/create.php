<?php
/**
 * Create Contact Person Endpoint
 * POST /contact_persons/create.php
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
$required_fields = ['supplier_id', 'name', 'surname'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    // Validate supplier_id exists
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$input['supplier_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Check if email already exists for this supplier (if provided)
    if (isset($input['email']) && !empty($input['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ?");
        $stmt->execute([$input['email'], $input['supplier_id']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists for this supplier.']);
            exit;
        }
    }

    // Check if cell already exists for this supplier (if provided)
    if (isset($input['cell']) && !empty($input['cell'])) {
        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE cell = ? AND supplier_id = ?");
        $stmt->execute([$input['cell'], $input['supplier_id']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Cell number already exists for this supplier.']);
            exit;
        }
    }

    // Insert contact person
    $stmt = $pdo->prepare("
        INSERT INTO contact_persons (supplier_id, name, surname, email, cell)
        VALUES (?, ?, ?, ?, ?)
    ");

    $email = $input['email'] ?? null;
    $cell = $input['cell'] ?? null;

    $stmt->execute([
        $input['supplier_id'],
        $input['name'],
        $input['surname'],
        $email,
        $cell
    ]);

    $contact_person_id = $pdo->lastInsertId();

    // Fetch created contact person
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, surname, email, cell, created_at, updated_at
        FROM contact_persons WHERE id = ?
    ");
    $stmt->execute([$contact_person_id]);
    $contact_person = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Contact person created successfully.',
        'data' => $contact_person
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating contact person: ' . $e->getMessage()
    ]);
}
?>

