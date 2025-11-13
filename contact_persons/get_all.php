<?php
/**
 * Get All Contact Persons Endpoint
 * GET /contact_persons/get_all.php
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
            cp.id,
            cp.supplier_id,
            cp.name,
            cp.surname,
            cp.email,
            cp.cell,
            cp.created_at,
            cp.updated_at,
            COUNT(s.id) AS store_count
        FROM contact_persons cp
        LEFT JOIN stores s ON cp.supplier_id = s.supplier_id
        GROUP BY 
            cp.id,
            cp.supplier_id,
            cp.name,
            cp.surname,
            cp.email,
            cp.cell,
            cp.created_at,
            cp.updated_at
        ORDER BY cp.name ASC, cp.surname ASC
    ");

    $contact_persons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Contact persons fetched successfully.',
        'data' => $contact_persons,
        'count' => count($contact_persons)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching contact persons: ' . $e->getMessage()
    ]);
}
?>


