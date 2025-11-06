<?php
/**
 * Delete Manufacturer Endpoint
 * DELETE /manufacturers/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get manufacturer ID from query string or JSON body
$manufacturer_id = null;
if (isset($_GET['id'])) {
    $manufacturer_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $manufacturer_id = $input['id'] ?? null;
}

if (!$manufacturer_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Manufacturer ID is required.']);
    exit;
}

try {
    // Check if manufacturer exists
    $stmt = $pdo->prepare("SELECT id, name FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    if (!$manufacturer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // Delete manufacturer (CASCADE will handle vehicle_models)
    $stmt = $pdo->prepare("DELETE FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer deleted successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting manufacturer: ' . $e->getMessage()
    ]);
}
?>

