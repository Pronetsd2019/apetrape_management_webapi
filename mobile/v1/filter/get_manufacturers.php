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
 * Mobile Get Manufacturers Endpoint
 * GET /mobile/v1/filter/get_manufacturers.php
 * Public endpoint - no authentication required
 * Returns all manufacturers with logo images
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

try {
    // Get all manufacturers
    $stmt = $pdo->query("
        SELECT 
            id,
            name,
            img,
            created_at,
            updated_at
        FROM manufacturers
        ORDER BY name ASC
    ");

    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $formatted_manufacturers = [];
    foreach ($manufacturers as $manufacturer) {
        $formatted_manufacturers[] = [
            'id' => (int)$manufacturer['id'],
            'name' => $manufacturer['name'],
            'img' => $manufacturer['img'] ? $manufacturer['img'] : null,
            'created_at' => $manufacturer['created_at'],
            'updated_at' => $manufacturer['updated_at']
        ];
    }

    $response = [
        'success' => true,
        'message' => 'Manufacturers fetched successfully.',
        'data' => $formatted_manufacturers,
        'count' => count($formatted_manufacturers)
    ];

    http_response_code(200);
    echo json_encode($response);

} catch (PDOException $e) {
    logException('mobile_filter_get_manufacturers', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching manufacturers. Please try again later.',
        'error_details' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_filter_get_manufacturers', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching manufacturers. Please try again later.',
        'error_details' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
}
?>

