<?php
/**
 * Get All Manufacturers Endpoint
 * GET /manufacturers/get_all.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching manufacturers: ' . $e->getMessage()
    ]);
}
?>


