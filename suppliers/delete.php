<?php
/**
 * Delete Supplier Endpoint
 * DELETE /suppliers/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get supplier ID from query string or JSON body
$supplier_id = null;
if (isset($_GET['id'])) {
    $supplier_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $supplier_id = $input['id'] ?? null;
}

if (!$supplier_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required.']);
    exit;
}

try {
    // Check if supplier exists
    $stmt = $pdo->prepare("SELECT id, name, email FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Delete supplier (CASCADE will handle stores and operating hours)
    $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier deleted successfully.',
        'data' => $supplier
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting supplier: ' . $e->getMessage()
    ]);
}
?>

