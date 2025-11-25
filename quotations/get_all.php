<?php
/**
 * Get All Quotations Endpoint
 * GET /quotations/get_all.php
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
    // Get all quotations with item count and total
    $stmt = $pdo->query("
        SELECT 
            q.id,
            q.user_id,
            q.status,
            q.quote_no,
            q.customer_name,
            q.customer_cell,
            q.customer_address,
            q.sent_date,
            q.created_at,
            q.updated_at,
            COUNT(qi.id) AS item_count,
            COALESCE(SUM(qi.total), 0) AS quote_total
        FROM quotations q
        LEFT JOIN quotation_items qi ON qi.quote_id = q.id
        GROUP BY 
            q.id, 
            q.user_id, 
            q.status, 
            q.quote_no, 
            q.customer_name, 
            q.customer_cell, 
            q.customer_address, 
            q.sent_date, 
            q.created_at, 
            q.updated_at
        ORDER BY q.created_at DESC
    ");
    
    $quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotations fetched successfully.',
        'data' => $quotations,
        'count' => count($quotations)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching quotations: ' . $e->getMessage()
    ]);
}
?>

