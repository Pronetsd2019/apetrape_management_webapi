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
 * Mobile Get All Manufacturers Endpoint
 * GET /mobile/v1/manufacturer/get_all.php
 * Public endpoint - no authentication required
 * Returns all manufacturers with logo images and statistics
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
    // Get all manufacturers with model and item counts
    $stmt = $pdo->query("
        SELECT
            m.id AS manufacturer_id,
            m.name AS manufacturer_name,
            m.img AS manufacturer_img,
            m.created_at,
            m.updated_at,
            COUNT(DISTINCT vm.id) AS total_models,
            COUNT(DISTINCT ivm.item_id) AS total_items
        FROM manufacturers m
        LEFT JOIN vehicle_models vm ON vm.manufacturer_id = m.id
        LEFT JOIN item_vehicle_models ivm ON ivm.vehicle_model_id = vm.id
        GROUP BY m.id, m.name, m.img, m.created_at, m.updated_at
        ORDER BY m.name ASC
    ");

    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $formatted_manufacturers = [];
    foreach ($manufacturers as $manufacturer) {
        $formatted_manufacturers[] = [
            'id' => (int)$manufacturer['manufacturer_id'],
            'name' => $manufacturer['manufacturer_name'],
            'img' => $manufacturer['manufacturer_img'] ? $manufacturer['manufacturer_img'] : null,
            'total_models' => (int)$manufacturer['total_models'],
            'total_items' => (int)$manufacturer['total_items'],
            'created_at' => $manufacturer['created_at'],
            'updated_at' => $manufacturer['updated_at']
        ];
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturers fetched successfully.',
        'data' => $formatted_manufacturers,
        'count' => count($formatted_manufacturers)
    ]);

} catch (PDOException $e) {
    logException('mobile_manufacturer_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching manufacturers. Please try again later.',
        'error_details' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_manufacturer_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
}
?>

