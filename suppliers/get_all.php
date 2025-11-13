<?php
/**
 * Get All Suppliers Endpoint
 * GET /suppliers/get_all.php
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
            s.id AS supplier_id,
            s.name AS supplier_name,
            s.cellphone,
            s.telephone,
            s.email,
            s.status,
            s.locked_until,
            s.created_at AS entry_date,
            COUNT(DISTINCT st.id) AS number_of_stores,
            COUNT(DISTINCT i.id) AS total_items
        FROM suppliers s
        LEFT JOIN stores st ON st.supplier_id = s.id
        LEFT JOIN items i ON i.supplier_id = s.id
        GROUP BY s.id, s.name, s.cellphone, s.email, s.status, s.locked_until, s.created_at
        ORDER BY s.created_at DESC
    ");

    $suppliers = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Suppliers fetched successfully.',
        'data' => $suppliers,
        'count' => count($suppliers)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching suppliers: ' . $e->getMessage()
    ]);
}
?>


