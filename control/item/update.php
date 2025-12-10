<?php
/**
 * Update Item/Stock Endpoint
 * PUT /items/update.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'stock management', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update stock items.']);
     exit;
 }


function respondBadRequest(string $message): never
{
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Allow both PUT (JSON) and POST (multipart) to simplify uploads
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['PUT', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT or POST.']);
    exit;
}

$isMultipart = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') !== false;

if ($isMultipart) {
    // Mirror the create endpoint behaviour for multipart form-data
    $item_id = $_POST['id'] ?? null;
    if (!$item_id) {
        respondBadRequest("Field 'id' is required.");
    }

    $supplier_id = $_POST['supplier_id'] ?? null;
    $name = isset($_POST['name']) ? trim($_POST['name']) : null;
    $description = isset($_POST['description']) ? trim($_POST['description']) : null;
    $sku = isset($_POST['sku']) ? trim($_POST['sku']) : null;
    $price = $_POST['price'] ?? null;
    $discount = $_POST['discount'] ?? null;
    $sale_price = $_POST['sale_price'] ?? null;
    $cost_price = $_POST['cost_price'] ?? null;
    $is_universal = isset($_POST['is_universal']) ? (int)$_POST['is_universal'] : null;
    $supported_models_raw = $_POST['supported_models'] ?? null;
    $stores_raw = $_POST['stores'] ?? null;
    $main_image_index = isset($_POST['main_image_index']) ? (int)$_POST['main_image_index'] : 0;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respondBadRequest('Invalid JSON payload.');
    }

    $item_id = $input['id'] ?? null;
    if (!$item_id) {
        respondBadRequest("Field 'id' is required.");
    }

    $supplier_id = $input['supplier_id'] ?? null;
    $name = $input['name'] ?? null;
    $description = $input['description'] ?? null;
    $sku = $input['sku'] ?? null;
    $price = $input['price'] ?? null;
    $discount = $input['discount'] ?? null;
    $sale_price = $input['sale_price'] ?? null;
    $cost_price = $input['cost_price'] ?? null;
    $is_universal = array_key_exists('is_universal', $input) ? (int)$input['is_universal'] : null;
    $supported_models_raw = array_key_exists('supported_models', $input) ? json_encode($input['supported_models']) : null;
    $stores_raw = array_key_exists('stores', $input) ? json_encode($input['stores']) : null;
    $main_image_index = $input['main_image_index'] ?? 0;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    $current_supplier_id = $existing['supplier_id'];
    $current_is_universal = (int)$existing['is_universal'];

    $update_fields = [];
    $params = [];

    if ($supplier_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        if (!$stmt->fetch()) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
            exit;
        }
        $update_fields[] = "supplier_id = ?";
        $params[] = $supplier_id;
        $current_supplier_id = $supplier_id;
    }
    if ($name !== null) {
        $update_fields[] = "name = ?";
        $params[] = $name;
    }
    if ($description !== null) {
        $update_fields[] = "description = ?";
        $params[] = $description;
    }
    if ($sku !== null) {
        $update_fields[] = "sku = ?";
        $params[] = $sku;
    }
    if ($is_universal !== null) {
        $update_fields[] = "is_universal = ?";
        $params[] = $is_universal;
        $current_is_universal = $is_universal;
    }
    foreach (['price' => $price, 'discount' => $discount, 'sale_price' => $sale_price, 'cost_price' => $cost_price] as $field => $value) {
        if ($value !== null) {
            if (!is_numeric($value)) {
                respondBadRequest("Field '$field' must be numeric.");
            }
            $update_fields[] = "$field = ?";
            $params[] = (float)$value;
        }
    }

    if (!empty($update_fields)) {
        $params[] = $item_id;
        $sql = "UPDATE items SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    if ($supported_models_raw !== null) {
        $supported_models = json_decode($supported_models_raw, true);
        if (!is_array($supported_models)) {
            respondBadRequest("Field 'supported_models' must be a valid JSON array.");
        }

        $stmt = $pdo->prepare("DELETE FROM item_vehicle_models WHERE item_id = ?");
        $stmt->execute([$item_id]);

        if (!$current_is_universal) {
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
    }

    $stores_input = $stores_raw ?? [];
    if (is_string($stores_input)) {
        $stores_payload = json_decode($stores_input ?: '[]', true);
    } else {
        $stores_payload = $stores_input;
    }
    if (!is_array($stores_payload)) {
        respondBadRequest("Field 'stores' must be a valid array or JSON array.");
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

    $stmt = $pdo->prepare("DELETE FROM store_items WHERE item_id = ?");
    $stmt->execute([$item_id]);

    if (!empty($storeIds)) {
        $stmtInsertStore = $pdo->prepare("
            INSERT INTO store_items (store_id, item_id)
            VALUES (?, ?)
        ");
        $stmtCheckStore = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND supplier_id = ?");

        foreach ($storeIds as $store_id) {
            $stmtCheckStore->execute([$store_id, $current_supplier_id]);
            if ($stmtCheckStore->fetch()) {
                $stmtInsertStore->execute([$store_id, $item_id]);
            }
        }
    }

    if ($isMultipart && isset($_FILES['images'])) {
        if (isset($_POST['delete_image_ids'])) {
            $deleteIds = json_decode($_POST['delete_image_ids'], true);
            if (is_array($deleteIds) && !empty($deleteIds)) {
                $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                $stmt = $pdo->prepare("SELECT id, src FROM item_images WHERE item_id = ? AND id IN ($placeholders)");
                $stmt->execute(array_merge([$item_id], $deleteIds));
                $imagesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($imagesToDelete)) {
                    $stmt = $pdo->prepare("DELETE FROM item_images WHERE item_id = ? AND id IN ($placeholders)");
                    $stmt->execute(array_merge([$item_id], $deleteIds));

                    foreach ($imagesToDelete as $img) {
                        $filePath = dirname(__DIR__) . '/' . $img['src'];
                        if (is_file($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
            }
        }

        $files = $_FILES['images'];
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
                $altText = $index === $main_image_index ? ($name ?? $existing['name']) . ' (main image)' : ($name ?? $existing['name']);

                $stmtInsertImage->execute([$item_id, $altText, $relativePath]);
            }
        }
    }

    $pdo->commit();

    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, description, sku, is_universal, price, discount, sale_price, cost_price, created_at, updated_at
        FROM items WHERE id = ?
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

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
    $item['supported_models'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT id, alt, src, created_at, updated_at
        FROM item_images
        WHERE item_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$item_id]);
    $item['images'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item updated successfully.',
        'data' => $item
    ]);

} catch (Throwable $t) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating item: ' . $t->getMessage()
    ]);
}
?>
