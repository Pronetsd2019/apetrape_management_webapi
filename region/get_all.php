<?php
/**
 * Get All Regions Endpoint
 * GET /region/get_all.php
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
            r.id AS region_id,
            r.name AS region_name,
            r.country_id,
            c.name AS country_name,
            COUNT(DISTINCT ci.id) AS total_cities,
            COUNT(DISTINCT s.id) AS total_stores
        FROM region r
        LEFT JOIN country c ON r.country_id = c.id
        LEFT JOIN city ci ON ci.region_id = r.id
        LEFT JOIN stores s ON s.city_id = ci.id
        GROUP BY r.id, r.name, r.country_id, c.name
        ORDER BY r.name ASC
    ");

    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Regions fetched successfully.',
        'data' => $regions,
        'count' => count($regions)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching regions: ' . $e->getMessage()
    ]);
}

?>


