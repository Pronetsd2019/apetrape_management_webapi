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
 * Create Item for Supplier Endpoint
 * POST /supplier/item/create.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get the authenticated supplier's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$supplierId = $authUser['supplier_id'] ?? null;

if (!$supplierId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated supplier.']);
    exit;
}

// Check if request is multipart/form-data or JSON
$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;

if ($isMultipart) {
    // Handle multipart/form-data
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $price = $_POST['price'] ?? null;
    $cost_price = $_POST['cost_price'] ?? null;
    $discount = isset($_POST['discount']) ? (float)$_POST['discount'] : 0;
    $is_universal = isset($_POST['is_universal']) ? (int)$_POST['is_universal'] : 0;
    $main_image_index = isset($_POST['main_image_index']) ? (int)$_POST['main_image_index'] : 0;
    
    // Parse JSON arrays from form data
    $stores_input = $_POST['stores'] ?? '[]';
    $supported_models_raw = $_POST['supported_models'] ?? '[]';
    $categories_input = $_POST['categories'] ?? '[]';
    
    // Parse stores
    if (is_array($stores_input)) {
        $stores_payload = $stores_input;
    } else {
        $stores_payload = json_decode($stores_input ?: '[]', true);
    }
    if (!is_array($stores_payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field 'stores' must be a valid array or JSON array."]);
        exit;
    }
    
    // Parse supported models
    if (is_array($supported_models_raw)) {
        $supported_models_payload = $supported_models_raw;
    } else {
        $supported_models_payload = json_decode($supported_models_raw ?: '[]', true);
    }
    if (!is_array($supported_models_payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field 'supported_models' must be a valid array or JSON array."]);
        exit;
    }
    
    // Parse categories
    if (is_array($categories_input)) {
        $categories_payload = $categories_input;
    } else {
        $categories_payload = json_decode($categories_input ?: '[]', true);
    }
    if (!is_array($categories_payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field 'categories' must be a valid array or JSON array."]);
        exit;
    }
} else {
    // Handle JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $sku = trim($input['sku'] ?? '');
    $price = $input['price'] ?? null;
    $cost_price = $input['cost_price'] ?? null;
    $discount = isset($input['discount']) ? (float)$input['discount'] : 0;
    $is_universal = isset($input['is_universal']) ? (int)$input['is_universal'] : 0;
    $main_image_index = isset($input['main_image_index']) ? (int)$input['main_image_index'] : 0;
    $stores_payload = $input['stores'] ?? [];
    $supported_models_payload = $input['supported_models'] ?? [];
    $categories_payload = $input['categories'] ?? [];
}

// Validate required fields
$fieldValues = [
    'name' => $name,
    'description' => $description,
    'sku' => $sku,
    'price' => $price
];

$requiredFields = ['name', 'description', 'sku', 'price'];
foreach ($requiredFields as $field) {
    $value = $fieldValues[$field];
    if ($value === null || $value === '' || (is_numeric($value) && $value < 0)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Field '{$field}' is required and cannot be empty.",
            'error' => json_encode($requiredFields)
        ]);
        exit;
    }
}

// Convert price to float
$price = (float)$price;

// If cost_price is not provided, set it equal to price
if ($cost_price === null || $cost_price === '') {
    $cost_price = $price;
}
$cost_price = (float)$cost_price;

// Automatically calculate sale price as cost price + 10% markup
$sale_price = $cost_price * 1.10;

// Validate data
if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Item name must be at least 2 characters long.'
    ]);
    exit;
}

if (strlen($sku) < 3) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'SKU must be at least 3 characters long.'
    ]);
    exit;
}

if ($price <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Price must be greater than 0.'
    ]);
    exit;
}

if ($cost_price <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Cost price must be greater than 0.'
    ]);
    exit;
}

if ($discount < 0 || $discount > 100) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Discount must be between 0 and 100.'
    ]);
    exit;
}

// Extract store IDs
$storeIds = [];
foreach ($stores_payload as $entry) {
    if (is_array($entry)) {
        $store_id = $entry['store_id'] ?? $entry['storeId'] ?? $entry['id'] ?? null;
    } else {
        $store_id = $entry;
    }
    if ($store_id !== null && $store_id !== '') {
        $storeIds[] = (int)$store_id;
    }
}
$storeIds = array_values(array_unique(array_filter($storeIds, fn($v) => $v !== 0)));

// Extract vehicle model IDs
$vehicleModelIds = [];
if (!$is_universal && !empty($supported_models_payload)) {
    foreach ($supported_models_payload as $entry) {
        if (is_array($entry)) {
            $vehicle_model_id = $entry['vehicle_model_id'] ?? $entry['vehicleModelId'] ?? $entry['id'] ?? null;
        } else {
            $vehicle_model_id = $entry;
        }
        if ($vehicle_model_id !== null && $vehicle_model_id !== '') {
            $vehicleModelIds[] = (int)$vehicle_model_id;
        }
    }
}

// Extract category IDs
$categoryIds = [];
foreach ($categories_payload as $entry) {
    if (is_array($entry)) {
        $category_id = $entry['category_id'] ?? $entry['categoryId'] ?? $entry['id'] ?? null;
    } else {
        $category_id = $entry;
    }
    if ($category_id !== null && $category_id !== '') {
        $categoryIds[] = (int)$category_id;
    }
}
$categoryIds = array_values(array_unique(array_filter($categoryIds, fn($v) => $v !== 0)));

// Append supplier_id to SKU to avoid conflicts between different suppliers
$sku = $sku . '_' . $supplierId;

$pdo->beginTransaction();

try {
    // Check for duplicate SKU
    $stmt = $pdo->prepare("SELECT id FROM items WHERE sku = ?");
    $stmt->execute([$sku]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'An item with this SKU already exists.'
        ]);
        exit;
    }

    // Insert the item
    $stmt = $pdo->prepare("
        INSERT INTO items (supplier_id, name, description, sku, price, discount, sale_price, cost_price, is_universal)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $result = $stmt->execute([
        $supplierId,
        $name,
        $description !== '' ? $description : null,
        $sku !== '' ? $sku : null,
        $price,
        $discount,
        $sale_price,
        $cost_price,
        $is_universal
    ]);

    if (!$result) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create item.'
        ]);
        exit;
    }

    $itemId = $pdo->lastInsertId();

    // Link stores
    if (!empty($storeIds)) {
        $stmtInsertStore = $pdo->prepare("
            INSERT INTO store_items (store_id, item_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)
        ");
        $stmtCheckStore = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND supplier_id = ?");

        foreach ($storeIds as $store_id) {
            $stmtCheckStore->execute([$store_id, $supplierId]);
            if ($stmtCheckStore->fetch()) {
                $stmtInsertStore->execute([$store_id, $itemId]);
            }
        }
    }

    // Link supported models
    if (!$is_universal && !empty($vehicleModelIds)) {
        $stmtInsertModel = $pdo->prepare("
            INSERT INTO item_vehicle_models (item_id, vehicle_model_id)
            VALUES (?, ?)
        ");
        $stmtCheckModel = $pdo->prepare("SELECT id FROM vehicle_models WHERE id = ?");

        foreach ($vehicleModelIds as $vehicle_model_id) {
            $stmtCheckModel->execute([$vehicle_model_id]);
            if ($stmtCheckModel->fetch()) {
                $stmtInsertModel->execute([$itemId, $vehicle_model_id]);
            }
        }
    }

    // Link categories to item_category table (many-to-many relationship)
    if (!empty($categoryIds)) {
        $stmtInsertCategory = $pdo->prepare("
            INSERT INTO item_category (item_id, category_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)
        ");
        $stmtCheckCategory = $pdo->prepare("SELECT id FROM categories WHERE id = ?");

        foreach ($categoryIds as $category_id) {
            $stmtCheckCategory->execute([$category_id]);
            if ($stmtCheckCategory->fetch()) {
                $stmtInsertCategory->execute([$itemId, $category_id]);
            }
        }
    }

    // Handle images (only for multipart)
    $uploadedImages = [];
    if ($isMultipart && isset($_FILES['images'])) {
        $files = $_FILES['images'];

        // Normalize single upload into array format
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $totalImages = count($names);

        if ($totalImages > 0) {
            $uploadDir = dirname(dirname(dirname(__DIR__))) . '/uploads/items';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Failed to create uploads directory: ' . $uploadDir);
            }

            $stmtInsertImage = $pdo->prepare("
                INSERT INTO item_images (item_id, alt, src)
                VALUES (?, ?, ?)
            ");

            for ($index = 0; $index < $totalImages; $index++) {
                $error = $errors[$index];
                if ($error === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($error !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('File upload error code ' . $error . ' for image index ' . $index);
                }

                $tmpName = $tmpNames[$index];
                if (!is_uploaded_file($tmpName)) {
                    throw new RuntimeException('Potential file upload attack detected.');
                }

                $originalName = basename($names[$index] ?? '');
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $safeName = uniqid('item_' . $itemId . '_', true) . ($extension ? '.' . $extension : '');
                $targetPath = $uploadDir . '/' . $safeName;

                if (!move_uploaded_file($tmpName, $targetPath)) {
                    throw new RuntimeException('Failed to move uploaded image to destination.');
                }

                $relativePath = 'uploads/items/' . $safeName;
                $altText = $index === $main_image_index ? $name . ' (main image)' : $name;

                $stmtInsertImage->execute([$itemId, $altText, $relativePath]);
                $uploadedImages[] = [
                    'alt' => $altText,
                    'src' => $relativePath,
                    'is_main' => $index === $main_image_index
                ];
            }
        }
    }

    $pdo->commit();

    // Fetch the created item with related data
    $stmt = $pdo->prepare("
        SELECT
            id, supplier_id, name, description, sku, price, discount,
            sale_price, cost_price, is_universal, created_at, updated_at
        FROM items
        WHERE id = ?
    ");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch supported models
    $stmt = $pdo->prepare("
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
    ");
    $stmt->execute([$itemId]);
    $item['supported_models'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch stores
    $stmt = $pdo->prepare("
        SELECT s.id AS store_id, s.name AS store_name, s.physical_address
        FROM store_items si
        INNER JOIN stores s ON si.store_id = s.id
        WHERE si.item_id = ?
    ");
    $stmt->execute([$itemId]);
    $item['stores'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch categories
    $stmt = $pdo->prepare("
        SELECT c.id AS category_id, c.name AS category_name
        FROM item_category ic
        INNER JOIN categories c ON ic.category_id = c.id
        WHERE ic.item_id = ?
    ");
    $stmt->execute([$itemId]);
    $item['categories'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch images
    $stmt = $pdo->prepare("
        SELECT id, alt, src, created_at, updated_at
        FROM item_images
        WHERE item_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$itemId]);
    $item['images'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Item created successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
    logException('supplier_item_create', $e);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating item: ' . $e->getMessage()
    ]);
} catch (RuntimeException $e) {
    logException('supplier_item_create', $e);
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating item: ' . $e->getMessage()
    ]);
}
?>
