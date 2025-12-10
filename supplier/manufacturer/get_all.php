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
 * Get All Manufacturers Endpoint
 * GET /manufacturers/get_all.php
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

try {
    $stmt = $pdo->query("
        SELECT 
            m.id AS manufacturer_id,
            m.name AS manufacturer_name,
            m.created_at,
            m.updated_at,
            COUNT(DISTINCT vm.id) AS total_models,
            COUNT(DISTINCT ivm.item_id) AS total_items
        FROM manufacturers m
        LEFT JOIN vehicle_models vm ON vm.manufacturer_id = m.id
        LEFT JOIN item_vehicle_models ivm ON ivm.vehicle_model_id = vm.id
        GROUP BY m.id, m.name, m.created_at, m.updated_at
        ORDER BY m.name ASC
    ");

    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturers fetched successfully.',
        'data' => $manufacturers,
        'count' => count($manufacturers)
    ]);

} catch (PDOException $e) {
    logException('supplier_manufacturer_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
}
?>


