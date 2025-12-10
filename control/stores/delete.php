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
 * Delete Store Endpoint
 * DELETE /stores/delete.php
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
 if (!checkUserPermission($userId, 'stores', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete stores.']);
     exit;
 }

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get store ID from query string or JSON body
$store_id = null;
if (isset($_GET['id'])) {
    $store_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $store_id = $input['id'] ?? null;
}

if (!$store_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Store ID is required.']);
    exit;
}

try {
    // Check if store exists
    $stmt = $pdo->prepare("
        SELECT s.id, s.supplier_id, s.physical_address
        FROM stores s WHERE s.id = ?
    ");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch();

    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found.']);
        exit;
    }

    // Delete store (CASCADE will handle operating hours)
    $stmt = $pdo->prepare("DELETE FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Store deleted successfully.',
        'data' => $store
    ]);

} catch (PDOException $e) {
    logException('stores_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting store: ' . $e->getMessage()
    ]);
}
?>

