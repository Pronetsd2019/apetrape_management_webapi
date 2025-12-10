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
 * Change Store Status Endpoint
 * PUT /stores/change_status.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
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
$required_fields = ['id', 'status'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

$store_id = $input['id'];
$status = $input['status'];

if (!is_numeric($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Status must be a numeric value.']);
    exit;
}

$status = (int)$status;

try {
    // Check if store exists and belongs to this supplier
    $stmt = $pdo->prepare("SELECT id, status FROM stores WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$store_id, $supplierId]);
    $store = $stmt->fetch();

    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found or does not belong to this supplier.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("
        UPDATE stores
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $store_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Store status updated successfully.',
        'data' => [
            'id' => $store_id,
            'previous_status' => (int)$store['status'],
            'current_status' => $status
        ]
    ]);

} catch (PDOException $e) {
    logException('supplier_stores_change_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating store status: ' . $e->getMessage()
    ]);
}
?>


