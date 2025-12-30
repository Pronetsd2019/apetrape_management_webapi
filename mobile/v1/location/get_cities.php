<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile Get Cities by Region Endpoint
 * GET /mobile/v1/location/get_cities.php?region_id=1
 * Public endpoint - no authentication required
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

header('Content-Type: application/json');

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

$region_id = $_GET['region_id'] ?? null;

if (!$region_id || !is_numeric($region_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Valid region_id parameter is required.'
    ]);
    exit;
}

try {
    // Validate region exists and fetch country info
    $stmt = $pdo->prepare("
        SELECT 
            r.id AS region_id,
            r.name AS region_name,
            r.country_id,
            c.name AS country_name
        FROM region r
        LEFT JOIN country c ON r.country_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$region) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Region not found.'
        ]);
        exit;
    }

    // Get cities for this region
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            region_id,
            entry,
            created_at,
            updated_at
        FROM city
        WHERE region_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$region_id]);

    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cities fetched successfully.',
        'region' => [
            'id' => (int)$region['region_id'],
            'name' => $region['region_name'],
            'country_id' => (int)$region['country_id'],
            'country_name' => $region['country_name']
        ],
        'data' => $cities,
        'count' => count($cities)
    ]);

} catch (PDOException $e) {
    logException('mobile_location_get_cities', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching cities. Please try again later.',
        'error_details' => 'Error fetching cities: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_location_get_cities', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching cities. Please try again later.',
        'error_details' => 'Error fetching cities: ' . $e->getMessage()
    ]);
}
?>

