<?php
/**
 * Create Item Endpoint
 * POST /items/create.php
 */

 ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

function respondBadRequest(string $message): never
{
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Expecting multipart/form-data
$name = trim($_POST['name'] ?? '');
$supplier_id = $_POST['supplier_id'] ?? null;
$description = trim($_POST['description'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$price = $_POST['price'] ?? null;
$discount = $_POST['discount'] ?? null;
$sale_price = $_POST['sale_price'] ?? null;
$cost_price = $_POST['cost_price'] ?? null;
$is_universal = isset($_POST['is_universal']) ? (int)$_POST['is_universal'] : 0;
$supported_models_raw = $_POST['supported_models'] ?? '[]';
$main_image_index = isset($_POST['main_image_index']) ? (int)$_POST['main_image_index'] : 0;
$stores_input = $_POST['stores'] ?? '[]';
if (is_array($stores_input)) {
    $stores_payload = $stores_input;
} else {
    $stores_payload = json_decode($stores_input ?: '[]', true);
}
if (!is_array($stores_payload)) {
    respondBadRequest("Field 'stores' must be a valid array or JSON array when provided.");
}

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

if ($name === '') {
    respondBadRequest("Field 'name' is required.");
}
if (!$supplier_id) {
    respondBadRequest("Field 'supplier_id' is required.");
}

$numericFields = [
    'price' => $price,
    'discount' => $discount,
    'sale_price' => $sale_price,
    'cost_price' => $cost_price
];

foreach ($numericFields as $field => $value) {
    if ($value === null || $value === '') {
        respondBadRequest("Field '$field' is required.");
    }
    if (!is_numeric($value)) {
        respondBadRequest("Field '$field' must be numeric.");
    }
}

$price = (float)$price;
$discount = (float)$discount;
$sale_price = (float)$sale_price;
$cost_price = (float)$cost_price;

$supported_models = [];
if (!$is_universal) {
    $supported_models = json_decode($supported_models_raw, true);
    if (!is_array($supported_models)) {
        respondBadRequest("Field 'supported_models' must be a valid JSON array.");
    }
}

$pdo->beginTransaction();

try {
    // Validate supplier exists
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Insert item
    $stmt = $pdo->prepare("
        INSERT INTO items (supplier_id, name, description, sku, is_universal, price, discount, sale_price, cost_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $supplier_id,
        $name,
        $description !== '' ? $description : null,
        $sku !== '' ? $sku : null,
        $is_universal,
        $price,
        $discount,
        $sale_price,
        $cost_price
    ]);

    $item_id = $pdo->lastInsertId();

    // Supported models
    if (!$is_universal && !empty($supported_models)) {
        $stmtInsertModel = $pdo->prepare("
            INSERT INTO item_vehicle_models (item_id, vehicle_model_id)
            VALUES (?, ?)
        ");
        $stmtCheckModel = $pdo->prepare("SELECT id FROM vehicle_models WHERE id = ?");

        foreach ($supported_models as $entry) {
            $vehicle_model_id = $entry['vehicle_model_id'] ?? null;
            if (!$vehicle_model_id) {
                continue;
            }
            $stmtCheckModel->execute([$vehicle_model_id]);
            if ($stmtCheckModel->fetch()) {
                $stmtInsertModel->execute([$item_id, $vehicle_model_id]);
            }
        }
    }

    // Store associations
    if (!empty($storeIds)) {
        $stmtInsertStore = $pdo->prepare("
            INSERT INTO store_items (store_id, item_id)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)
        ");
        $stmtCheckStore = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND supplier_id = ?");

        foreach ($storeIds as $store_id) {
            $stmtCheckStore->execute([$store_id, $supplier_id]);
            if ($stmtCheckStore->fetch()) {
                $stmtInsertStore->execute([$store_id, $item_id]);
            }
        }
    }

    // Handle images
    $uploadedImages = [];
    if (isset($_FILES['images'])) {
        $files = $_FILES['images'];

        // Normalise single upload into array format
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
        $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
        $totalImages = count($names);

        if ($totalImages > 0) {
            $uploadDir = dirname(__DIR__) . '/uploads/items';
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
                $safeName = uniqid('item_' . $item_id . '_', true) . ($extension ? '.' . $extension : '');
                $targetPath = $uploadDir . '/' . $safeName;

                if (!move_uploaded_file($tmpName, $targetPath)) {
                    throw new RuntimeException('Failed to move uploaded image to destination.');
                }

                $relativePath = 'uploads/items/' . $safeName;
                $altText = $index === $main_image_index ? $name . ' (main image)' : $name;

                $stmtInsertImage->execute([$item_id, $altText, $relativePath]);
                $uploadedImages[] = [
                    'alt' => $altText,
                    'src' => $relativePath,
                    'is_main' => $index === $main_image_index
                ];
            }
        }
    }

    $pdo->commit();

    // Fetch item summary
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, description, sku, is_universal, price, discount, sale_price, cost_price, created_at, updated_at
        FROM items
        WHERE id = ?
    ");
    $stmt->execute([$item_id]);
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
    $stmt->execute([$item_id]);
    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch stored images
    $stmt = $pdo->prepare("
        SELECT id, alt, src, created_at, updated_at
        FROM item_images
        WHERE item_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$item_id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $item['supported_models'] = $models;
    $item['images'] = $images;

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Item created successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
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

