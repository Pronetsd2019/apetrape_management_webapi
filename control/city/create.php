<?php
/**
 * Create City Endpoint
 * POST /city/create.php
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
 if (!checkUserPermission($userId, 'locations', 'create')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to write a city.']);
     exit;
 }
 

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['city_name']) || empty(trim($input['city_name']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "city name" is required.']);
    exit;
}

if (!isset($input['region_id']) || empty($input['region_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "region_id" is required.']);
    exit;
}

$name = trim($input['city_name']);
$region_id = $input['region_id'];
$entry = isset($input['entry']) && $input['entry'] !== '' ? trim($input['entry']) : null;

try {
    // Validate region exists and fetch country
    $stmt = $pdo->prepare("
        SELECT r.id, r.name AS region_name, r.country_id, c.name AS country_name
        FROM region r
        LEFT JOIN country c ON r.country_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$region) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Region not found.']);
        exit;
    }

    // Check for duplicate city name within the same region (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM city WHERE LOWER(name) = LOWER(?) AND region_id = ?");
    $stmt->execute([$name, $region_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'City name already exists for this region.']);
        exit;
    }

    // Insert city
    $stmt = $pdo->prepare("
        INSERT INTO city (name, region_id, entry)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$name, $region_id, $entry]);

    $city_id = $pdo->lastInsertId();

    // Fetch created city with region and country details
    $stmt = $pdo->prepare("
        SELECT 
            ci.id AS city_id,
            ci.name AS city_name,
            ci.region_id,
            r.name AS region_name,
            r.country_id,
            c.name AS country_name,
            ci.entry,
            ci.updated_at
        FROM city ci
        LEFT JOIN region r ON ci.region_id = r.id
        LEFT JOIN country c ON r.country_id = c.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$city_id]);
    $city = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'City created successfully.',
        'data' => $city
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating city: ' . $e->getMessage()
    ]);
}
?>


