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
 * Get Cities by Region Endpoint
 * GET /city/get_by_region.php
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
 if (!checkUserPermission($userId, 'locations', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read cities by region.']);
     exit;
 }
 

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$region_id = $_GET['region_id'] ?? null;

if (!$region_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Region ID is required.']);
    exit;
}

try {
    // Validate region exists and fetch country
    $stmt = $pdo->prepare("
        SELECT r.id, r.name AS region_name, r.country_id, c.name AS country_name
        FROM region r
        LEFT JOIN country c ON r.country_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$region) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Region not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            ci.id AS city_id,
            ci.name AS city_name,
            ci.region_id,
            :region_name AS region_name,
            :country_id AS country_id,
            :country_name AS country_name,
            COUNT(DISTINCT s.id) AS total_stores,
            ci.entry
        FROM city ci
        LEFT JOIN stores s ON s.city_id = ci.id
        WHERE ci.region_id = :region_id
        GROUP BY ci.id, ci.name, ci.region_id, ci.entry
        ORDER BY ci.name ASC
    ");
    $stmt->execute([
        ':region_id' => $region_id,
        ':region_name' => $region['region_name'],
        ':country_id' => $region['country_id'],
        ':country_name' => $region['country_name']
    ]);

    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cities fetched successfully.',
        'region' => $region,
        'data' => $cities,
        'count' => count($cities)
    ]);

} catch (PDOException $e) {
    logException('city_get_by_region', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching cities: ' . $e->getMessage()
    ]);
}
?>


