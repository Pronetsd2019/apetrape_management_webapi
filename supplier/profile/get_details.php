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
 * Get Supplier Profile Details Endpoint
 * GET /supplier/profile/get_details.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
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

try {
    // Get supplier details with counts
    $stmt = $pdo->prepare("
        SELECT
            s.id, s.name, s.email, s.cellphone, s.telephone,
            s.created_at, s.updated_at, s.status, s.reg,
            (SELECT COUNT(*) FROM stores WHERE supplier_id = s.id) AS number_of_stores,
            (SELECT COUNT(*) FROM items WHERE supplier_id = s.id) AS total_items
        FROM suppliers s
        WHERE s.id = ?
    ");
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

    // Convert counts to integers
    $supplier['store_count'] = (int) $supplier['store_count'];
    $supplier['item_count'] = (int) $supplier['item_count'];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier details retrieved successfully.',
        'data' => $supplier
    ]);

} catch (PDOException $e) {
    logException('supplier_profile_get_details', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving supplier details: ' . $e->getMessage()
    ]);
}
?>
