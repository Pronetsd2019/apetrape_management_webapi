<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
/**
 * Create Contact Person Endpoint
 * POST /contact_persons/create.php
 */

 require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
 require_once __DIR__ . '/../../control/middleware/auth_middleware.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['name', 'surname'];
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
    $stmt->execute([$supplierId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Check if email already exists for this supplier (if provided)
    if (isset($input['email']) && !empty($input['email'])) {
        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE email = ? AND supplier_id = ?");
        $stmt->execute([$input['email'], $supplierId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists for this supplier.']);
            exit;
        }
    }

    // Check if cell already exists for this supplier (if provided)
    if (isset($input['cell']) && !empty($input['cell'])) {
        $stmt = $pdo->prepare("SELECT id FROM contact_persons WHERE cell = ? AND supplier_id = ?");
        $stmt->execute([$input['cell'], $supplierId]);
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
        $supplierId,
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
    logException('supplier_contact_persons_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating contact person: ' . $e->getMessage()
    ]);
}
?>

