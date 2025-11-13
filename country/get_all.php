<?php
/**
 * Get All Countries Endpoint
 * GET /country/get_all.php
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
            c.id AS country_id,
            c.name AS country_name,
            c.entry AS country_entry,
            COUNT(DISTINCT r.id) AS total_regions,
            COUNT(DISTINCT ci.id) AS total_cities,
            COUNT(DISTINCT s.id) AS total_stores
        FROM country c
        LEFT JOIN region r ON r.country_id = c.id
        LEFT JOIN city ci ON ci.region_id = r.id
        LEFT JOIN stores s ON s.city_id = ci.id
        GROUP BY c.id, c.name, c.entry
        ORDER BY c.name ASC
    ");

    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Countries fetched successfully.',
        'data' => $countries,
        'count' => count($countries)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching countries: ' . $e->getMessage()
    ]);
}
?>


