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
 * Mobile Get Regions by Country Endpoint
 * GET /mobile/v1/location/get_regions.php?country_id=1
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

$country_id = $_GET['country_id'] ?? null;

if (!$country_id || !is_numeric($country_id)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Valid country_id parameter is required.'
    ]);
    exit;
}

try {
    // Validate country exists
    $stmt = $pdo->prepare("SELECT id, name FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    $country = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$country) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'Country not found.'
        ]);
        exit;
    }

    // Get regions for this country
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            country_id,
            entry,
            created_at,
            updated_at
        FROM region
        WHERE country_id = ?
        ORDER BY name ASC
    ");
    $stmt->execute([$country_id]);

    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Regions fetched successfully.',
        'country' => [
            'id' => (int)$country['id'],
            'name' => $country['name']
        ],
        'data' => $regions,
        'count' => count($regions)
    ]);

} catch (PDOException $e) {
    logException('mobile_location_get_regions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching regions. Please try again later.',
        'error_details' => 'Error fetching regions: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_location_get_regions', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching regions. Please try again later.',
        'error_details' => 'Error fetching regions: ' . $e->getMessage()
    ]);
}
?>

