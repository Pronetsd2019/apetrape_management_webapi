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
 * Supplier Registration Endpoint
 * POST /supplier/registration/register.php
 * 1= pending 2=rejected 3=approved
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';

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
$requiredFields = ['name', 'email', 'cell', 'address', 'password','password_confirm'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Field '{$field}' is required and cannot be empty."
        ]);
        exit;
    }
}

$name = trim($input['name']);
$email = trim($input['email']);
$cell = trim($input['cell']);
$address = trim($input['address']);
$password = $input['password'];
$passwordConfirm = $input['password_confirm'];
$reg = isset($input['reg']) ? trim($input['reg']) : null;
$telephone = isset($input['telephone']) ? trim($input['telephone']) : null;

// Additional validation
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid email address format.'
    ]);
    exit;
}

if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Name must be at least 2 characters long.'
    ]);
    exit;
}

if (strlen($cell) < 7) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cell phone number must be at least 7 digits.'
    ]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Password must be at least 6 characters long.'
    ]);
    exit;
}

if ($password !== $passwordConfirm) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Password confirmation does not match the password.'
    ]);
    exit;
}

try {
    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Check if supplier with same name already exists in suppliers table
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$name]);
    $existingSupplierByName = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSupplierByName) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'A supplier with this name already exists.',
        ]);
        exit;
    }

    // Check if supplier with same email already exists in suppliers table
    $stmt = $pdo->prepare("SELECT id, name, email FROM suppliers WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $existingSupplierByEmail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSupplierByEmail) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'A supplier with this email address already exists.',
        ]);
        exit;
    }

    // Check if there's already a pending application with same email
    $stmt = $pdo->prepare("SELECT id, name FROM supplier_application WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $existingApplicationByEmail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingApplicationByEmail) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'A registration application with this email already exists.',
        ]);
        exit;
    }

    // Check if there's already a pending application with same reg number
    if ($reg) {
        $stmt = $pdo->prepare("SELECT id, name FROM supplier_application WHERE reg = ?");
        $stmt->execute([$reg]);
        $existingApplicationByReg = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingApplicationByReg) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'A registration application with this registration number already exists.',
            ]);
            exit;
        }
    }

    // Insert the supplier application
    $stmt = $pdo->prepare("
        INSERT INTO supplier_application (name, email, cell, address, reg, telephone, password_hash, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");

    $result = $stmt->execute([$name, $email, $cell, $address, $reg, $telephone, $password_hash]);

    if ($result) {
        $applicationId = $pdo->lastInsertId();

        // Fetch the created application (exclude password_hash for security)
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, address, reg, telephone, status, created_at, updated_at
            FROM supplier_application
            WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Supplier registration application submitted successfully. Please wait for approval. Our team will be in touch',
            'data' => $application
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to submit registration application.'
        ]);
    }

} catch (PDOException $e) {
    logException('supplier_registration_register', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error processing registration: ' . $e->getMessage()
    ]);
}
?>
