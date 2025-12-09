<?php
/**
 * Delete Manufacturer Endpoint
 * DELETE /manufacturers/delete.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'delete')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to delete manufacturers.']);
     exit;
 }

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

