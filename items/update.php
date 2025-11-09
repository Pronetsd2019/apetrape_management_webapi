<?php
/**
 * Update Item/Stock Endpoint
 * PUT /items/update.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate item_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Item ID is required.']);
    exit;
}

$item_id = $input['id'];

try {
    // Check if item exists
    $stmt = $pdo->prepare("SELECT id, supplier_id, is_universal FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $existing = $stmt->fetch();

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    $current_supplier_id = $existing['supplier_id'];

    // Build update query dynamically
    $update_fields = [];
    $params = [];

    if (isset($input['supplier_id'])) {
        // Validate supplier_id exists
        $stmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
        $stmt->execute([$input['supplier_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
            exit;
        }
        $update_fields[] = "supplier_id = ?";
        $params[] = $input['supplier_id'];
        $current_supplier_id = $input['supplier_id'];
    }
    if (isset($input['name'])) {
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['description'])) {
        $update_fields[] = "description = ?";
        $params[] = $input['description'];
    }
    if (isset($input['sku'])) {
        $update_fields[] = "sku = ?";
        $params[] = $input['sku'];
    }
    if (isset($input['is_universal'])) {
        $update_fields[] = "is_universal = ?";
        $params[] = (int)$input['is_universal'];
    }

    // Update item fields if any
    if (!empty($update_fields)) {
        $params[] = $item_id;
        $sql = "UPDATE items SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Get updated is_universal value
    $new_is_universal = isset($input['is_universal']) ? (int)$input['is_universal'] : $existing['is_universal'];

    // Update vehicle models if provided
    if (isset($input['vehicle_model_ids'])) {
        // Delete existing vehicle model links
        $stmt = $pdo->prepare("DELETE FROM item_vehicle_models WHERE item_id = ?");
        $stmt->execute([$item_id]);

        // Add new vehicle models if not universal
        if (!$new_is_universal && is_array($input['vehicle_model_ids']) && !empty($input['vehicle_model_ids'])) {
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
    }

    // Update stores if provided
    if (isset($input['stores']) && is_array($input['stores'])) {
        foreach ($input['stores'] as $store_data) {
            if (isset($store_data['store_id'])) {
                // Validate store belongs to supplier
                $stmt_check = $pdo->prepare("SELECT id FROM stores WHERE id = ? AND supplier_id = ?");
                $stmt_check->execute([$store_data['store_id'], $current_supplier_id]);
                if ($stmt_check->fetch()) {
                    $quantity = isset($store_data['quantity']) ? (int)$store_data['quantity'] : 0;
                    $price = isset($store_data['price']) ? $store_data['price'] : null;

                    $stmt_store = $pdo->prepare("
                        INSERT INTO store_items (store_id, item_id, quantity, price)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), price = VALUES(price)
                    ");
                    $stmt_store->execute([$store_data['store_id'], $item_id, $quantity, $price]);
                }
            }
        }
    }

    // Fetch updated item with related data
    $stmt = $pdo->prepare("
        SELECT id, supplier_id, name, description, sku, is_universal, created_at, updated_at
        FROM items WHERE id = ?
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    // Fetch vehicle models
    if (!$item['is_universal']) {
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

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item updated successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating item: ' . $e->getMessage()
    ]);
}
?>

