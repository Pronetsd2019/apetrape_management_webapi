<?php
/**
 * Update Country Endpoint
 * PUT /country/update.php
 */

 require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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
 if (!checkUserPermission($userId, 'locations', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update a country.']);
     exit;
 }
 

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['id', 'country_name'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

$country_id = $input['id'];
$name = trim($input['country_name']);

try {
    // Check if country exists
    $stmt = $pdo->prepare("SELECT id FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Country not found.']);
        exit;
    }

    // Check for duplicate name (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM country WHERE LOWER(name) = LOWER(?) AND id != ?");
    $stmt->execute([$name, $country_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Country name already exists.']);
        exit;
    }

    // Update country name
    $stmt = $pdo->prepare("UPDATE country SET name = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$name, $country_id]);

    // Fetch updated country
    $stmt = $pdo->prepare("SELECT id, name, entry, entry, updated_at FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    $country = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Country updated successfully.',
        'data' => $country
    ]);

} catch (PDOException $e) {
    logException('country_update', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating country: ' . $e->getMessage()
    ]);
}
?>


