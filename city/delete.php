<?php
/**
 * Delete City Endpoint
 * DELETE /city/delete.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow DELETE method
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use DELETE.']);
    exit;
}

// Get city ID from query string or JSON body
$city_id = null;
if (isset($_GET['id'])) {
    $city_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $city_id = $input['id'] ?? null;
}

if (!$city_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'City ID is required.']);
    exit;
}

try {
    // Check if city exists
    $stmt = $pdo->prepare("
        SELECT ci.id, ci.name, ci.region_id, r.name AS region_name
        FROM city ci
        LEFT JOIN region r ON ci.region_id = r.id
        WHERE ci.id = ?
    ");
    $stmt->execute([$city_id]);
    $city = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$city) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'City not found.']);
        exit;
    }

    // Check if any stores reference this city
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE city_id = ?");
    $stmt->execute([$city_id]);
    $storeCount = $stmt->fetchColumn();

    if ($storeCount > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete city. It is referenced by existing stores.'
        ]);
        exit;
    }

    // Delete city
    $stmt = $pdo->prepare("DELETE FROM city WHERE id = ?");
    $stmt->execute([$city_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'City deleted successfully.',
        'data' => [
            'id' => $city['id'],
            'name' => $city['name'],
            'region_id' => $city['region_id']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting city: ' . $e->getMessage()
    ]);
}
?>


