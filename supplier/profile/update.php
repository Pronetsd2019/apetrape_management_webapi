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
 * Update Supplier Profile Endpoint
 * PUT /supplier/profile/update.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
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

try {
    // Check if supplier exists and is active
    $stmt = $pdo->prepare("SELECT id, status FROM suppliers WHERE id = ?");
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Check if supplier is active
    if ($supplier['status'] !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is not active.']);
        exit;
    }

    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];

    if (isset($input['name'])) {
        $update_fields[] = "name = ?";
        $params[] = trim($input['name']);
    }

    if (isset($input['reg'])) {
        $update_fields[] = "reg = ?";
        $params[] = trim($input['reg']);
    }

    if (isset($input['email'])) {
        // Check if email already exists for another supplier
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE email = ? AND id != ?");
        $stmt->execute([trim($input['email']), $supplierId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }
        $update_fields[] = "email = ?";
        $params[] = trim($input['email']);
    }

    if (isset($input['cellphone'])) {
        $update_fields[] = "cellphone = ?";
        $params[] = trim($input['cellphone']);
    }

    if (isset($input['telephone'])) {
        $update_fields[] = "telephone = ?";
        $params[] = trim($input['telephone']);
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    // Add supplier_id to params
    $params[] = $supplierId;

    // Execute update
    $sql = "UPDATE suppliers SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated supplier details
    $stmt = $pdo->prepare("
        SELECT
            id, name, email, cellphone, telephone,
            created_at, updated_at, status, reg
        FROM suppliers
        WHERE id = ?
    ");
    $stmt->execute([$supplierId]);
    $updatedSupplier = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => $updatedSupplier
    ]);

} catch (PDOException $e) {
    logException('supplier_profile_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating profile: ' . $e->getMessage()
    ]);
}
?>