<?php
/**
 * Create Item/Stock Endpoint
 * POST /items/create.php
 */

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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['supplier_id', 'name'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    // Validate supplier_id exists
    $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
    $stmt->execute([$input['supplier_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    $is_universal = isset($input['is_universal']) ? (int)$input['is_universal'] : 0;

    // Insert item
    $stmt = $pdo->prepare("
        INSERT INTO items (supplier_id, name, description, sku, is_universal)
        VALUES (?, ?, ?, ?, ?)
    ");

    $description = $input['description'] ?? null;
    $sku = $input['sku'] ?? null;

    $stmt->execute([
        $input['supplier_id'],
        $input['name'],
        $description,
        $sku,
        $is_universal
    ]);

    $item_id = $pdo->lastInsertId();

    // Add vehicle models if item is not universal
    if (!$is_universal && isset($input['vehicle_model_ids']) && is_array($input['vehicle_model_ids'])) {
        $stmt_models = $pdo->prepare("
            INSERT INTO item_vehicle_models (item_id, vehicle_model_id)
            VALUES (?, ?)
        ");

        foreach ($input['vehicle_model_ids'] as $vehicle_model_id) {
            // Validate vehicle model exists
            $stmt_check = $pdo->prepare("SELECT id FROM vehicle_models WHERE id = ?");
            $stmt_check->execute([$vehicle_model_id]);
            if ($stmt_check->fetch()) {
                $stmt_models->execute([$item_id, $vehicle_model_id]);
            }
        }
    }

    // Add stores and quantities if provided
    if (isset($input['stores']) && is_array($input['stores'])) {
        $stmt_stores = $pdo->prepare("
            INSERT INTO store_items (store_id, item_id, quantity, price)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), price = VALUES(price)
        ");

        foreach ($input['stores'] as $store_data) {
            if (isset($store_data['store_id'])) {
                // Validate store belongs to supplier
                $stmt_check = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND supplier_id = ?");
                $stmt_check->execute([$store_data['store_id'], $input['supplier_id']]);
                if ($stmt_check->fetch()) {
                    $quantity = isset($store_data['quantity']) ? (int)$store_data['quantity'] : 0;
                    $price = isset($store_data['price']) ? $store_data['price'] : null;
                    $stmt_stores->execute([$store_data['store_id'], $item_id, $quantity, $price]);
                }
            }
        }
    }

    // Fetch created item with related data
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, description, sku, is_universal, created_at, updated_at
        FROM items WHERE id = ?
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    // Fetch vehicle models
    if (!$is_universal) {
        $stmt = $pdo->prepare("
            SELECT vm.id, vm.manufacturer_id, vm.model_name, vm.variant, 
                   vm.year_from, vm.year_to, m.name as manufacturer_name
            FROM item_vehicle_models ivm
            INNER JOIN vehicle_models vm ON ivm.vehicle_model_id = vm.id
            INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
            WHERE ivm.item_id = ?
        ");
        $stmt->execute([$item_id]);
        $item['vehicle_models'] = $stmt->fetchAll();
    } else {
        $item['vehicle_models'] = [];
    }

    // Fetch stores with quantities
    $stmt = $pdo->prepare("
        SELECT s.id as store_id, s.physical_address, si.quantity, si.price
        FROM store_items si
        INNER JOIN stores s ON si.store_id = s.id
        WHERE si.item_id = ?
    ");
    $stmt->execute([$item_id]);
    $item['stores'] = $stmt->fetchAll();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Item created successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating item: ' . $e->getMessage()
    ]);
}
?>

