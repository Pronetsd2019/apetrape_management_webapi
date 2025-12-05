<?php
/**
 * General Statistics Count Endpoint
 * GET /stats/general_count.php
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
    // Single optimized query with multiple subqueries
    $stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM suppliers) AS total_suppliers,
            (SELECT COUNT(*) FROM manufacturers) AS total_manufacturers,
            (SELECT COUNT(*) FROM items) AS total_items,
            (SELECT COUNT(*) FROM part_find_requests) AS total_part_find_requests,
            (SELECT COUNT(*) FROM orders) AS total_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'draft') AS total_draft_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'confirmed') AS total_pending_orders,
            (SELECT COUNT(*) FROM orders WHERE status IN ('in-transit','in_transit')) AS total_in_transit_orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'delivered') AS total_delivered_orders,
            (SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()) AS orders_today,
            (SELECT COUNT(*) FROM part_find_requests WHERE DATE(created_at) = CURDATE()) AS requests_today
    ");

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $stats = [
        'users' => (int)$result['total_users'],
        'suppliers' => (int)$result['total_suppliers'],
        'manufacturers' => (int)$result['total_manufacturers'],
        'items' => (int)$result['total_items'],
        'orders' => [
            'total' => (int)$result['total_orders'],
            'draft' => (int)$result['total_draft_orders'],
            'pending' => (int)$result['total_pending_orders'],
            'in_transit' => (int)$result['total_in_transit_orders'],
            'delivered' => (int)$result['total_delivered_orders']
        ],
        'part_find_requests' => (int)$result['total_part_find_requests'],
        'orders_today' => (int)$result['orders_today'],
        'part_find_requests_today' => (int)$result['requests_today']
    ];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'General statistics retrieved successfully.',
        'data' => $stats
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error retrieving statistics: ' . $e->getMessage()
    ]);
}
?>
