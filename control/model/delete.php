<?php
/**
 * Delete Vehicle Model Endpoint
 * DELETE /vehicle_models/delete.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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
 if (!checkUserPermission($userId, 'manufacturers', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete models.']);
     exit;
 }

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

    // Check if any items are attached to this vehicle model
    $stmt = $pdo->prepare("SELECT id, item_id FROM item_vehicle_models WHERE vehicle_model_id = ?");
    $stmt->execute([$vehicle_model_id]);
    $attachedItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($attachedItems)) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete vehicle model. It is linked to one or more items.',
            'data' => $attachedItems
        ]);
        exit;
    }

    // Delete vehicle model
    $stmt = $pdo->prepare("DELETE FROM vehicle_models WHERE id = ?");
    $stmt->execute([$vehicle_model_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle model deleted successfully.',
        'data' => $vehicle_model
    ]);

} catch (PDOException $e) {
    logException('model_delete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting vehicle model: ' . $e->getMessage()
    ]);
}
?>

