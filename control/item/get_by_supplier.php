<?php
/**
 * Get Items by Supplier Endpoint
 * GET /item/get_by_supplier.php?supplier_id=1
 */

 require_once __DIR__ . '/../util/connect.php';
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
 if (!checkUserPermission($userId, 'stock management', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read stock items.']);
     exit;
 }


// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $supplier_id = $_GET['supplier_id'] ?? null;
    
    if (!$supplier_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parameter "supplier_id" is required.']);
        exit;
    }
    
    // Check if supplier exists
    $stmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }
    
    // Get items for this supplier
    $stmt = $pdo->prepare("
        SELECT 
            i.id,
            i.supplier_id,
            s.name AS supplier_name,
            i.name,
            i.description,
            i.sku,
            i.is_universal,
            i.created_at,
            i.updated_at,
            i.price,
            i.discount,
            i.sale_price,
            i.cost_price,
            (
                SELECT src
                FROM item_images ii
                WHERE ii.item_id = i.id
                ORDER BY ii.id ASC
                LIMIT 1
            ) AS image_url
        FROM items i
        LEFT JOIN suppliers s ON i.supplier_id = s.id
        WHERE i.supplier_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$supplier_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($items)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'No items found for this supplier.',
            'supplier' => $supplier,
            'data' => [],
            'count' => 0
        ]);
        exit;
    }
    
    $itemIds = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    
    // Fetch supported models
    $stmtModels = $pdo->prepare("
        SELECT 
            ivm.item_id,
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
        WHERE ivm.item_id IN ($placeholders)
        ORDER BY m.name ASC, vm.model_name ASC
    ");
    $stmtModels->execute($itemIds);
    $links = $stmtModels->fetchAll(PDO::FETCH_ASSOC);
    
    $modelsByItem = [];
    foreach ($links as $link) {
        $itemId = $link['item_id'];
        unset($link['item_id']);
        $modelsByItem[$itemId][] = $link;
    }
    
    // Fetch all images
    $stmtImages = $pdo->prepare("
        SELECT item_id, id, alt, src, created_at, updated_at
        FROM item_images
        WHERE item_id IN ($placeholders)
        ORDER BY item_id ASC, id ASC
    ");
    $stmtImages->execute($itemIds);
    $images = $stmtImages->fetchAll(PDO::FETCH_ASSOC);
    
    $imagesByItem = [];
    foreach ($images as $image) {
        $itemId = $image['item_id'];
        unset($image['item_id']);
        $imagesByItem[$itemId][] = $image;
    }
    
    // Fetch stores for items
    $stmtStores = $pdo->prepare("
        SELECT 
            si.item_id,
            si.store_id,
            s.name AS store_name,
            s.physical_address
        FROM store_items si
        INNER JOIN stores s ON si.store_id = s.id
        WHERE si.item_id IN ($placeholders)
        ORDER BY si.item_id ASC
    ");
    $stmtStores->execute($itemIds);
    $stores = $stmtStores->fetchAll(PDO::FETCH_ASSOC);
    
    $storesByItem = [];
    foreach ($stores as $store) {
        $itemId = $store['item_id'];
        unset($store['item_id']);
        $storesByItem[$itemId][] = $store;
    }
    
    // Attach data to items
    foreach ($items as &$item) {
        $itemId = $item['id'];
        $item['supported_models'] = $modelsByItem[$itemId] ?? [];
        $item['images'] = $imagesByItem[$itemId] ?? [];
        $item['stores'] = $storesByItem[$itemId] ?? [];
    }
    unset($item);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Items fetched successfully.',
        'supplier' => $supplier,
        'data' => $items,
        'count' => count($items)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching items: ' . $e->getMessage()
    ]);
}
?>

