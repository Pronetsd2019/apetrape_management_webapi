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
 * Delete Item/Stock Endpoint
 * DELETE /items/delete.php
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
 if (!checkUserPermission($userId, 'stock management', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete stock items.']);
     exit;
 }


// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get item ID from query string or JSON body
$item_id = null;
if (isset($_GET['id'])) {
    $item_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $item_id = $input['id'] ?? null;
}

if (!$item_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Item ID is required.']);
    exit;
}

try {
    // Check if item exists
    $stmt = $pdo->prepare("SELECT id, name, sku FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    // Remove store associations
    $stmt = $pdo->prepare("DELETE FROM store_items WHERE item_id = ?");
    $stmt->execute([$item_id]);

    // Delete item (CASCADE will handle remaining relations)
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$item_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item deleted successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
    logException('item_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting item: ' . $e->getMessage()
    ]);
}
?>

