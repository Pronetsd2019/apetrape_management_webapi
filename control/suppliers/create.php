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
 * Create Supplier Endpoint
 * POST /suppliers/create.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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
 if (!checkUserPermission($userId, 'suppliers', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to create supplier.']);
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

    $email = $input['email'];
    $name = $input['supplier_name'];
    $cellphone = $input['cell'];
    $telephone = $input['telephone'] ?? null;
    $reg = isset($input['reg']) && !empty($input['reg']) ? trim($input['reg']) : null;

    // Insert supplier
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (name, email, cellphone, telephone, password_hash, reg)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $name,
        $email,
        $cellphone,
        $telephone,
        $password_hash,
        $reg
    ]);

    $supplier_id = $pdo->lastInsertId();

    // Fetch created supplier
    $stmt = $pdo->prepare("
        SELECT id, name, email, cellphone, telephone, reg, created_at, updated_at
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
    logException('suppliers_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating supplier: ' . $e->getMessage()
    ]);
}
?>

