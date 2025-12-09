<?php
/**
 * Approve Supplier Application Endpoint
 * PUT /suppliers/approve.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';
require_once __DIR__ . '/../util/check_permission.php';

// Ensure the request is authenticated
requireJwtAuth();

// Get the authenticated user's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$userId = $authUser['admin_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
    exit;
}

// Check if the user has permission to approve suppliers
if (!checkUserPermission($userId, 'suppliers', 'write')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to approve suppliers.']);
    exit;
}

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['application_id']) || !is_numeric($input['application_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Valid application_id is required.'
    ]);
    exit;
}

$applicationId = (int)$input['application_id'];

try {
    // Check if application exists and is pending (status = 1)
    $stmt = $pdo->prepare("
        SELECT id, name, email, cell, telephone, address, reg, password_hash, status, created_at, updated_at
        FROM supplier_application
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);
    $application = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$application) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Supplier application not found.'
        ]);
        exit;
    }

    if ($application['status'] != 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Application is not in pending status.',
            'current_status' => $application['status']
        ]);
        exit;
    }

    // Check if supplier with same email already exists (shouldn't happen due to registration checks, but safety check)
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$application['email']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'A supplier with this email already exists.'
        ]);
        exit;
    }

    // Insert approved supplier into suppliers table
    $stmt = $pdo->prepare("
        INSERT INTO suppliers (
            name, email, cellphone, telephone, reg, password_hash,
            status, locked_until, failed_attempts, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, 1, NULL, 0, ?, ?)
    ");

    $result = $stmt->execute([
        $application['name'],
        $application['email'],
        $application['cell'], // maps to cellphone
        $application['telephone'],
        $application['reg'],
        $application['password_hash'],
        $application['created_at'],
        $application['updated_at']
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create supplier account.'
        ]);
        exit;
    }

    $supplierId = $pdo->lastInsertId();

    // Update application status to approved (3)
    $stmt = $pdo->prepare("
        UPDATE supplier_application
        SET status = 3, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$applicationId]);

    // Fetch the created supplier (exclude sensitive data)
    $stmt = $pdo->prepare("
        SELECT id, name, email, cellphone, telephone, reg, status, created_at, updated_at
        FROM suppliers
        WHERE id = ?
    ");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier application approved successfully. Supplier account created.',
        'data' => [
            'supplier' => $supplier,
            'application_id' => $applicationId,
            'approved_at' => date('Y-m-d H:i:s')
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error approving supplier application: ' . $e->getMessage()
    ]);
}
?>
