<?php
/**
 * Delete Item for Supplier Endpoint
 * DELETE /supplier/items/delete.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
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

// Get item ID from query string or JSON body
$item_id = null;
if (isset($_GET['id'])) {
    $item_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $item_id = $input['id'] ?? null;
}

if (!$item_id || !is_numeric($item_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid item ID is required.']);
    exit;
}

try {
    // Check if item exists and belongs to this supplier
    $stmt = $pdo->prepare("SELECT id, name, sku FROM items WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$item_id, $supplierId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found or does not belong to this supplier.']);
        exit;
    }

    // Check if item is currently in any orders (can't delete items that are in orders)
    $stmt = $pdo->prepare("SELECT COUNT(*) as order_count FROM order_items WHERE item_id = ?");
    $stmt->execute([$item_id]);
    $orderCheck = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($orderCheck['order_count'] > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete item that is currently in orders.',
            'item' => $item,
            'orders_count' => (int)$orderCheck['order_count']
        ]);
        exit;
    }

    // Remove store associations
    $stmt = $pdo->prepare("DELETE FROM store_items WHERE item_id = ?");
    $stmt->execute([$item_id]);

    // Remove vehicle model associations
    $stmt = $pdo->prepare("DELETE FROM item_vehicle_models WHERE item_id = ?");
    $stmt->execute([$item_id]);

    // Remove item images
    $stmt = $pdo->prepare("DELETE FROM item_images WHERE item_id = ?");
    $stmt->execute([$item_id]);

    // Delete item (CASCADE will handle any remaining relations)
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ? AND supplier_id = ?");
    $result = $stmt->execute([$item_id, $supplierId]);

    if ($result && $stmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Item deleted successfully.',
            'data' => $item
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete item.'
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting item: ' . $e->getMessage()
    ]);
}
?>
