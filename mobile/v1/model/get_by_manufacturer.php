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
 * Mobile Get Vehicle Models by Manufacturer Endpoint
 * GET /mobile/v1/model/get_by_manufacturer.php?manufacturer_id=1
 * Public endpoint - no authentication required
 * Returns all vehicle models for a specific manufacturer
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

$manufacturer_id = $_GET['manufacturer_id'] ?? null;

if (!$manufacturer_id || !is_numeric($manufacturer_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid manufacturer ID is required.']);
    exit;
}

$manufacturer_id = (int)$manufacturer_id;

try {
    // Validate manufacturer exists
    $stmt = $pdo->prepare("SELECT id, name, img FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manufacturer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // Get all vehicle models for this manufacturer
    $stmt = $pdo->prepare("
        SELECT
            vm.id AS model_id,
            vm.model_name,
            vm.variant,
            vm.year_from,
            vm.year_to,
            vm.manufacturer_id,
            vm.created_at,
            vm.updated_at
        FROM vehicle_models vm
        WHERE vm.manufacturer_id = ?
        ORDER BY vm.model_name ASC, vm.variant ASC
    ");
    $stmt->execute([$manufacturer_id]);

    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $formatted_models = [];
    foreach ($models as $model) {
        $formatted_models[] = [
            'id' => (int)$model['model_id'],
            'model_name' => $model['model_name'],
            'variant' => $model['variant'] ? $model['variant'] : null,
            'year_from' => $model['year_from'] ? (int)$model['year_from'] : null,
            'year_to' => $model['year_to'] ? (int)$model['year_to'] : null,
            'manufacturer_id' => (int)$model['manufacturer_id'],
            'created_at' => $model['created_at'],
            'updated_at' => $model['updated_at']
        ];
    }

    // Format manufacturer response
    $formatted_manufacturer = [
        'id' => (int)$manufacturer['id'],
        'name' => $manufacturer['name'],
        'img' => $manufacturer['img'] ? $manufacturer['img'] : null
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle models fetched successfully.',
        'manufacturer' => $formatted_manufacturer,
        'data' => $formatted_models,
        'count' => count($formatted_models)
    ]);

} catch (PDOException $e) {
    logException('mobile_model_get_by_manufacturer', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching vehicle models. Please try again later.',
        'error_details' => 'Error fetching vehicle models: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_model_get_by_manufacturer', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error fetching vehicle models: ' . $e->getMessage()
    ]);
}
?>

