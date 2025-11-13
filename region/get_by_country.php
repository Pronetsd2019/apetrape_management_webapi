<?php
/**
 * Get Regions by Country Endpoint
 * GET /region/get_by_country.php
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

$country_id = $_GET['country_id'] ?? null;

if (!$country_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Country ID is required.']);
    exit;
}

try {
    // Validate country exists
    $stmt = $pdo->prepare("SELECT id, name FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    $country = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$country) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Country not found.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            r.id AS region_id,
            r.name AS region_name,
            r.country_id,
            :country_name AS country_name,
            COUNT(DISTINCT ci.id) AS total_cities,
            COUNT(DISTINCT s.id) AS total_stores
        FROM region r
        LEFT JOIN city ci ON ci.region_id = r.id
        LEFT JOIN stores s ON s.city_id = ci.id
        WHERE r.country_id = :country_id
        GROUP BY r.id, r.name, r.country_id
        ORDER BY r.name ASC
    ");
    $stmt->execute([
        ':country_id' => $country_id,
        ':country_name' => $country['name']
    ]);

    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Regions fetched successfully.',
        'country' => $country,
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


