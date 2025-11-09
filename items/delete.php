<?php
/**
 * Delete Item/Stock Endpoint
 * DELETE /items/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
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

if (!$item_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Item ID is required.']);
    exit;
}

try {
    // Check if item exists
    $stmt = $pdo->prepare("SELECT id, name, sku FROM items WHERE id = ?");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found.']);
        exit;
    }

    // Delete item (CASCADE will handle store_items and item_vehicle_models)
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$item_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Item deleted successfully.',
        'data' => $item
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting item: ' . $e->getMessage()
    ]);
}
?>

