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
 * Delete Manufacturer Endpoint
 * DELETE /manufacturers/delete.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete manufacturers.']);
     exit;
 }

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get manufacturer ID from query string or JSON body
$manufacturer_id = null;
if (isset($_GET['id'])) {
    $manufacturer_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $manufacturer_id = $input['id'] ?? null;
}

if (!$manufacturer_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Manufacturer ID is required.']);
    exit;
}

try {
    // Check if manufacturer exists and get its image path
    $stmt = $pdo->prepare("SELECT id, name, img FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manufacturer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // Delete associated image file if it exists and is a local file
    if (isset($manufacturer['img']) && !empty($manufacturer['img'])) {
        $img_path = $manufacturer['img'];
        // Check if it's a local file (not a URL)
        if (!filter_var($img_path, FILTER_VALIDATE_URL) && strpos($img_path, 'uploads/') === 0) {
            $file_path = dirname(__DIR__, 2) . '/' . $img_path;
            if (file_exists($file_path)) {
                @unlink($file_path); // Suppress errors for unlinking
            }
        }
    }

    // Delete manufacturer (CASCADE will handle vehicle_models)
    $stmt = $pdo->prepare("DELETE FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer deleted successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    logException('manufacturer_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting manufacturer: ' . $e->getMessage()
    ]);
}
?>

