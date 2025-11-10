<?php
/**
 * Change Supplier Status Endpoint
 * PUT /suppliers/change_status.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['id', 'status'];
foreach ($required_fields as $field) {
    if (!isset($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

$supplier_id = $input['id'];
$status = $input['status'];

if (!is_numeric($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Status must be a numeric value.']);
    exit;
}

$status = (int)$status;

try {
    // Check if supplier exists
    $stmt = $pdo->prepare("SELECT id, status FROM suppliers WHERE id = ?");
    $stmt->execute([$supplier_id]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("
        UPDATE suppliers
        SET status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$status, $supplier_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Supplier status updated successfully.',
        'data' => [
            'id' => $supplier_id,
            'previous_status' => (int)$supplier['status'],
            'current_status' => $status
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating supplier status: ' . $e->getMessage()
    ]);
}
?>


