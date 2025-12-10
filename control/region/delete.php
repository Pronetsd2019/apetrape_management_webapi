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
 * Delete Region Endpoint
 * DELETE /region/delete.php
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
 if (!checkUserPermission($userId, 'locations', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete a region.']);
     exit;
 }
 
// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get region ID from query string or JSON body
$region_id = null;
if (isset($_GET['id'])) {
    $region_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $region_id = $input['id'] ?? null;
}

if (!$region_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Region ID is required.']);
    exit;
}

try {
    // Check if region exists
    $stmt = $pdo->prepare("SELECT id, name FROM region WHERE id = ?");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$region) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Region not found.']);
        exit;
    }

    // Check if any city references this region
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM city WHERE region_id = ?");
    $stmt->execute([$region_id]);
    $cityCount = $stmt->fetchColumn();

    if ($cityCount > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete region. It is referenced by existing cities.'
        ]);
        exit;
    }

    // Delete region
    $stmt = $pdo->prepare("DELETE FROM region WHERE id = ?");
    $stmt->execute([$region_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Region deleted successfully.',
        'data' => [
            'id' => $region['id'],
            'name' => $region['name']
        ]
    ]);

} catch (PDOException $e) {
    logException('region_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting region: ' . $e->getMessage()
    ]);
}
?>


