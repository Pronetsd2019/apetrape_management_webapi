<?php
/**
 * Get All Categories Endpoint (Flat List)
 * GET /category/get_all.php
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
            c.id,
            c.name,
            c.parent_id,
            pc.name AS parent_name,
            c.slug,
            c.sort_order,
            c.created_at,
            c.updated_at,
            (SELECT COUNT(*) FROM categories WHERE parent_id = c.id) AS children_count
        FROM categories c
        LEFT JOIN categories pc ON c.parent_id = pc.id
        ORDER BY c.sort_order ASC, c.name ASC
    ");
    
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Categories fetched successfully.',
        'data' => $categories,
        'count' => count($categories)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching categories: ' . $e->getMessage()
    ]);
}
?>

