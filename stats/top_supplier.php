<?php
/**
 * Top Suppliers by Item Count Endpoint
 * GET /stats/top_supplier.php
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
    // Get total counts in a single query
    $stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM items) as total_items,
            (SELECT COUNT(*) FROM suppliers) as total_suppliers
    ");
    $totalsResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalItems = (int)$totalsResult['total_items'];
    $totalSuppliers = (int)$totalsResult['total_suppliers'];

    // Get top 5 suppliers by item count
    $stmt = $pdo->query("
        SELECT
            s.id AS supplier_id,
            s.name AS supplier_name,
            COUNT(DISTINCT i.id) AS number_of_items
        FROM suppliers s
        LEFT JOIN items i ON i.supplier_id = s.id
        GROUP BY s.id, s.name
        HAVING number_of_items > 0
        ORDER BY number_of_items DESC
        LIMIT 5
    ");

    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate percentages
    foreach ($suppliers as &$supplier) {
        $supplier['number_of_items'] = (int)$supplier['number_of_items'];
        $supplier['percentage_of_total'] = $totalItems > 0
            ? round(($supplier['number_of_items'] / $totalItems) * 100, 2)
            : 0.00;
    }
    unset($supplier);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Top suppliers by item count retrieved successfully.',
        'data' => [
            'total_suppliers' => $totalSuppliers,
            'total_items_in_system' => $totalItems,
            'top_suppliers' => $suppliers
        ],
        'count' => count($suppliers)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving top suppliers: ' . $e->getMessage()
    ]);
}
?>
