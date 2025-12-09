<?php
/**
 * Get All Cities Endpoint
 * GET /city/get_all.php
 */

 require_once __DIR__ . '/../util/connect.php';
 require_once __DIR__ . '/../middleware/auth_middleware.php';
 require_once __DIR__ . '/../util/check_permission.php';
 
 // Ensure the request is authenticated
 requireJwtAuth();
 
 header('Content-Type: application/json');
 
 // Get the authenticated user's ID from the JWT payload
 $authUser = $GLOBALS['auth_user'] ?? null;
 $userId = $authUser['admin_id'] ?? null;
 
 if (!$userId) {
     http_response_code(401);
     echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated user.']);
     exit;
 }
 
 // Check if the user has permission to create a country
 if (!checkUserPermission($userId, 'locations', 'read')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to read cities.']);
     exit;
 }
 

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT 
            ci.id AS city_id,
            ci.name AS city_name,
            ci.region_id,
            r.name AS region_name,
            r.country_id,
            c.name AS country_name,
            COUNT(DISTINCT s.id) AS total_stores,
            ci.entry
        FROM city ci
        LEFT JOIN region r ON ci.region_id = r.id
        LEFT JOIN country c ON r.country_id = c.id
        LEFT JOIN stores s ON s.city_id = ci.id
        GROUP BY ci.id, ci.name, ci.region_id, r.name, r.country_id, c.name, ci.entry
        ORDER BY ci.name ASC
    ");

    $cities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Cities fetched successfully.',
        'data' => $cities,
        'count' => count($cities)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching cities: ' . $e->getMessage()
    ]);
}
?>


