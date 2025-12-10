<?php
/**
 * Get Location Arrays Endpoint
 * GET /util/locaion_array.php
 */

 require_once __DIR__ . '/../../control/util/connect.php';
 require_once __DIR__ . '/../../control/middleware/auth_middleware.php';

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
    // Fetch countries
    $stmt = $pdo->query("
        SELECT id, name
        FROM country
        ORDER BY name ASC
    ");
    $countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch regions
    $stmt = $pdo->query("
        SELECT id, name, country_id
        FROM region
        ORDER BY name ASC
    ");
    $regions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch cities
    $stmt = $pdo->query("
        SELECT id, name, region_id
        FROM city
        ORDER BY name ASC
    ");
    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Locations fetched successfully.',
        'data' => [
            'countries' => $countries,
            'regions' => $regions,
            'cities' => $cities
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching locations: ' . $e->getMessage()
    ]);
}
?>


