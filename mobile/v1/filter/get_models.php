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
 * Mobile Get Vehicle Models Endpoint
 * GET /mobile/v1/filter/get_models.php?manufacturer_id=1
 * GET /mobile/v1/filter/get_models.php?manufacturer_id=2,5
 * GET /mobile/v1/filter/get_models.php?manufacturer_id[]=2&manufacturer_id[]=5
 * Public endpoint - no authentication required
 * Optional manufacturer_id filter (supports single or multiple IDs)
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

// Parse manufacturer_id(s) - support multiple formats
$manufacturer_ids = [];

if (isset($_GET['manufacturer_id'])) {
    $manufacturer_id_param = $_GET['manufacturer_id'];
    
    // Handle array format: manufacturer_id[]=2&manufacturer_id[]=5
    if (is_array($manufacturer_id_param)) {
        $manufacturer_ids = array_map('trim', $manufacturer_id_param);
    } 
    // Handle comma-separated format: manufacturer_id=2,5
    else {
        $manufacturer_ids = array_map('trim', explode(',', $manufacturer_id_param));
    }
    
    // Remove empty values
    $manufacturer_ids = array_filter($manufacturer_ids, function($id) {
        return $id !== '' && $id !== null;
    });
    
    // Validate all IDs are numeric
    foreach ($manufacturer_ids as $id) {
        if (!is_numeric($id)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Validation failed',
                'message' => 'All manufacturer IDs must be valid numbers.'
            ]);
            exit;
        }
    }
    
    // Convert to integers and remove duplicates
    $manufacturer_ids = array_unique(array_map('intval', $manufacturer_ids));
    
    if (empty($manufacturer_ids)) {
        $manufacturer_ids = [];
    }
}

try {
    // Build query with optional manufacturer filter
    if (!empty($manufacturer_ids)) {
        // Validate all manufacturers exist
        $placeholders = implode(',', array_fill(0, count($manufacturer_ids), '?'));
        $stmt = $pdo->prepare("SELECT id, name FROM manufacturers WHERE id IN ($placeholders)");
        $stmt->execute($manufacturer_ids);
        $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $found_ids = array_column($manufacturers, 'id');
        $missing_ids = array_diff($manufacturer_ids, $found_ids);

        if (!empty($missing_ids)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Not found',
                'message' => 'One or more manufacturers not found.',
                'missing_ids' => array_values($missing_ids)
            ]);
            exit;
        }

        // Get models for these manufacturers
        $placeholders = implode(',', array_fill(0, count($manufacturer_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT 
                vm.id,
                vm.model_name,
                vm.variant,
                vm.year_from,
                vm.year_to,
                vm.manufacturer_id,
                m.name AS manufacturer_name,
                vm.created_at,
                vm.updated_at
            FROM vehicle_models vm
            INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
            WHERE vm.manufacturer_id IN ($placeholders)
            ORDER BY m.name ASC, vm.model_name ASC, vm.variant ASC
        ");
        $stmt->execute($manufacturer_ids);
    } else {
        // Get all models
        $stmt = $pdo->query("
            SELECT 
                vm.id,
                vm.model_name,
                vm.variant,
                vm.year_from,
                vm.year_to,
                vm.manufacturer_id,
                m.name AS manufacturer_name,
                vm.created_at,
                vm.updated_at
            FROM vehicle_models vm
            INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
            ORDER BY m.name ASC, vm.model_name ASC, vm.variant ASC
        ");
    }

    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $formatted_models = [];
    foreach ($models as $model) {
        $formatted_models[] = [
            'id' => (int)$model['id'],
            'model_name' => $model['model_name'],
            'variant' => $model['variant'],
            'year_from' => $model['year_from'] ? (int)$model['year_from'] : null,
            'year_to' => $model['year_to'] ? (int)$model['year_to'] : null,
            'manufacturer' => [
                'id' => (int)$model['manufacturer_id'],
                'name' => $model['manufacturer_name']
            ],
            'created_at' => $model['created_at'],
            'updated_at' => $model['updated_at']
        ];
    }

    $response = [
        'success' => true,
        'message' => 'Vehicle models fetched successfully.',
        'data' => $formatted_models,
        'count' => count($formatted_models)
    ];

    // Include manufacturer info if filtered
    if (!empty($manufacturer_ids) && isset($manufacturers)) {
        $formatted_manufacturers = [];
        foreach ($manufacturers as $manufacturer) {
            $formatted_manufacturers[] = [
                'id' => (int)$manufacturer['id'],
                'name' => $manufacturer['name']
            ];
        }
        
        // If single manufacturer, include as object for backward compatibility
        if (count($formatted_manufacturers) === 1) {
            $response['manufacturer'] = $formatted_manufacturers[0];
        } else {
            // Multiple manufacturers
            $response['manufacturers'] = $formatted_manufacturers;
        }
    }

    http_response_code(200);
    echo json_encode($response);

} catch (PDOException $e) {
    logException('mobile_filter_get_models', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching vehicle models. Please try again later.',
        'error_details' => 'Error fetching vehicle models: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_filter_get_models', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while fetching vehicle models. Please try again later.',
        'error_details' => 'Error fetching vehicle models: ' . $e->getMessage()
    ]);
}
?>

