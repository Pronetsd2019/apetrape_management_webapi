<?php
/**
 * Delete Store Endpoint
 * DELETE /stores/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get store ID from query string or JSON body
$store_id = null;
if (isset($_GET['id'])) {
    $store_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $store_id = $input['id'] ?? null;
}

if (!$store_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Store ID is required.']);
    exit;
}

try {
    // Check if store exists
    $stmt = $pdo->prepare("
        SELECT s.id, s.supplier_id, s.physical_address
        FROM stores s WHERE s.id = ?
    ");
    $stmt->execute([$store_id]);
    $store = $stmt->fetch();

    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found.']);
        exit;
    }

    // Delete store (CASCADE will handle operating hours)
    $stmt = $pdo->prepare("DELETE FROM stores WHERE id = ?");
    $stmt->execute([$store_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Store deleted successfully.',
        'data' => $store
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting store: ' . $e->getMessage()
    ]);
}
?>

