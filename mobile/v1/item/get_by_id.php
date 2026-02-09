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
 * Mobile Item Get by ID Endpoint
 * GET /mobile/v1/item/get_by_id.php?id={item_id}
 * Public endpoint - returns detailed item information with related data
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

// Validate item ID parameter
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$item_id || $item_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid item ID. Please provide a valid item ID as ?id={number}'
    ]);
    exit;
}

try {
    // Get main item data
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.price,
            i.discount,
            i.sale_price,
            i.lead_time,
            i.created_at,
            i.updated_at
        FROM items i
        WHERE i.id = ?
        LIMIT 1
    ");

    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Item not found.'
        ]);
        exit;
    }

    // Get all item images
    $imagesStmt = $pdo->prepare("
        SELECT
            id as image_id,
            src,
            alt as alt_text,
            created_at
        FROM item_images
        WHERE item_id = ?
        ORDER BY id ASC
    ");
    $imagesStmt->execute([$item_id]);
    $images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get supported vehicle models
    $modelsStmt = $pdo->prepare("
        SELECT
            vm.id AS vehicle_model_id,
            vm.model_name,
            vm.variant,
            vm.year_from,
            vm.year_to,
            m.id AS manufacturer_id,
            m.name AS manufacturer_name
        FROM item_vehicle_models ivm
        INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE ivm.item_id = ?
        ORDER BY m.name ASC, vm.model_name ASC
    ");
    $modelsStmt->execute([$item_id]);
    $supported_models = $modelsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get item categories
    $categoriesStmt = $pdo->prepare("
        SELECT
            c.id AS category_id,
            c.name AS category_name
        FROM item_category ic
        INNER JOIN categories c ON ic.category_id = c.id
        WHERE ic.item_id = ?
        ORDER BY c.name ASC
    ");
    $categoriesStmt->execute([$item_id]);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the item data (matching search endpoint structure)
    $formatted_item = [
        'id' => (int)$item['id'],
        'name' => $item['name'],
        'description' => $item['description'] ?: null,
        'sku' => $item['sku'] ?: null,
        'is_universal' => (bool)$item['is_universal'],
        'price' => $item['price'] ? (float)$item['price'] : null,
        'discount' => $item['discount'] ? (float)$item['discount'] : null,
        'sale_price' => $item['sale_price'] ? (float)$item['sale_price'] : null,
        'lead_time' => $item['lead_time'] ?: null,
        'images' => $images,
        'supported_models' => $supported_models,
        'categories' => $categories,
        'created_at' => $item['created_at'],
        'updated_at' => $item['updated_at']
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item details retrieved successfully.',
        'data' => $formatted_item
    ]);

} catch (PDOException $e) {
    logException('mobile_item_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while retrieving item details. Please try again later.',
        'error_details' => 'Error retrieving item details: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_item_get_by_id', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An unexpected error occurred. Please try again later.',
        'error_details' => 'Error retrieving item details: ' . $e->getMessage()
    ]);
}
?>
