<?php
/**
 * Get Roles Dropdown Endpoint
 * GET /roles/get_dropdown.php
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
        SELECT id, role_name as name, description
        FROM roles
        ORDER BY name ASC
    ");
    $roles = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Roles fetched successfully.',
        'data' => $roles
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching roles: ' . $e->getMessage()
    ]);
}
?>


