<?php
/**
 * Get All Manufacturers Endpoint
 * GET /manufacturers/get_all.php
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
 if (!checkUserPermission($userId, 'manufacturers', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read manufacturers.']);
     exit;
 }

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT 
            m.id AS manufacturer_id,
            m.name AS manufacturer_name,
            m.created_at,
            m.updated_at,
            COUNT(DISTINCT vm.id) AS total_models,
            COUNT(DISTINCT ivm.item_id) AS total_items
        FROM manufacturers m
        LEFT JOIN vehicle_models vm ON vm.manufacturer_id = m.id
        LEFT JOIN item_vehicle_models ivm ON ivm.vehicle_model_id = vm.id
        GROUP BY m.id, m.name, m.created_at, m.updated_at
        ORDER BY m.name ASC
    ");

    $manufacturers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturers fetched successfully.',
        'data' => $manufacturers,
        'count' => count($manufacturers)
    ]);

} catch (PDOException $e) {
    logException('manufacturer_get_all', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
}
?>


