<?php
/**
 * Delete Vehicle Model Endpoint
 * DELETE /vehicle_models/delete.php
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

// Get vehicle model ID from query string or JSON body
$vehicle_model_id = null;
if (isset($_GET['id'])) {
    $vehicle_model_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $vehicle_model_id = $input['id'] ?? null;
}

if (!$vehicle_model_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vehicle model ID is required.']);
    exit;
}

try {
    // Check if vehicle model exists
    $stmt = $pdo->prepare("SELECT id, model_name FROM vehicle_models WHERE id = ?");
    $stmt->execute([$vehicle_model_id]);
    $vehicle_model = $stmt->fetch();

    if (!$vehicle_model) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Vehicle model not found.']);
        exit;
    }

    // Delete vehicle model (CASCADE will handle item_vehicle_models)
    $stmt = $pdo->prepare("DELETE FROM vehicle_models WHERE id = ?");
    $stmt->execute([$vehicle_model_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle model deleted successfully.',
        'data' => $vehicle_model
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting vehicle model: ' . $e->getMessage()
    ]);
}
?>

