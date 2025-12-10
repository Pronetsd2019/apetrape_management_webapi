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
 * Edit Supplier Application Endpoint
 * PUT /supplier_application/edit.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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

// Check if the user has permission to edit supplier applications
if (!checkUserPermission($userId, 'suppliers', 'write')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to edit supplier applications.']);
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

// Extract optional fields to update
$updateFields = [];
$updateValues = [];

if (isset($input['name']) && !empty(trim($input['name']))) {
    $updateFields[] = 'name = ?';
    $updateValues[] = trim($input['name']);
}

if (isset($input['email']) && !empty(trim($input['email']))) {
    $email = trim($input['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email address format.'
        ]);
        exit;
    }
    $updateFields[] = 'email = ?';
    $updateValues[] = $email;
}

if (isset($input['cell']) && !empty(trim($input['cell']))) {
    $cell = trim($input['cell']);
    if (strlen($cell) < 7) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Cell phone number must be at least 7 digits.'
        ]);
        exit;
    }
    $updateFields[] = 'cell = ?';
    $updateValues[] = $cell;
}

if (isset($input['telephone'])) {
    $telephone = $input['telephone'] === '' ? null : trim($input['telephone']);
    $updateFields[] = 'telephone = ?';
    $updateValues[] = $telephone;
}

if (isset($input['address']) && !empty(trim($input['address']))) {
    $updateFields[] = 'address = ?';
    $updateValues[] = trim($input['address']);
}

if (isset($input['reg'])) {
    $reg = $input['reg'] === '' ? null : trim($input['reg']);
    $updateFields[] = 'reg = ?';
    $updateValues[] = $reg;
}

if (empty($updateFields)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'At least one field must be provided for update.'
    ]);
    exit;
}

try {
    // Check if application exists
    $stmt = $pdo->prepare("SELECT id, status FROM supplier_application WHERE id = ?");
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

    // Check if application is still pending (can only edit pending applications)
    if ($application['status'] != 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Only pending applications can be edited.',
            'current_status' => $application['status']
        ]);
        exit;
    }

    // Check for email conflicts if email is being updated
    if (isset($input['email']) && !empty(trim($input['email']))) {
        $stmt = $pdo->prepare("SELECT id FROM supplier_application WHERE email = ? AND id != ?");
        $stmt->execute([$email, $applicationId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Another application with this email already exists.'
            ]);
            exit;
        }
    }

    // Check for reg conflicts if reg is being updated
    if (isset($input['reg']) && $input['reg'] !== '' && $input['reg'] !== null) {
        $stmt = $pdo->prepare("SELECT id FROM supplier_application WHERE reg = ? AND id != ?");
        $stmt->execute([$reg, $applicationId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Another application with this registration number already exists.'
            ]);
            exit;
        }
    }

    // Update the application
    $updateValues[] = $applicationId; // Add ID for WHERE clause
    $sql = "UPDATE supplier_application SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($updateValues);

    if ($result && $stmt->rowCount() > 0) {
        // Fetch updated application
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, telephone, address, reg, status, created_at, updated_at
            FROM supplier_application
            WHERE id = ?
        ");
        $stmt->execute([$applicationId]);
        $updatedApplication = $stmt->fetch(PDO::FETCH_ASSOC);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Supplier application updated successfully.',
            'data' => $updatedApplication
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update supplier application.'
        ]);
    }

} catch (PDOException $e) {
    logException('supplier_applications_edit', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating supplier application: ' . $e->getMessage()
    ]);
}
?>
