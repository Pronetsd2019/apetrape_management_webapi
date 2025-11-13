<?php
/**
 * Delete Country Endpoint
 * DELETE /country/delete.php
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

// Get country ID from query string or JSON body
$country_id = null;
if (isset($_GET['id'])) {
    $country_id = $_GET['id'];
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $country_id = $input['id'] ?? null;
}

if (!$country_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Country ID is required.']);
    exit;
}

try {
    // Check if country exists
    $stmt = $pdo->prepare("SELECT id, name FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    $country = $stmt->fetch();

    if (!$country) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Country not found.']);
        exit;
    }

    // Check if any region uses this country
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_regions FROM region WHERE country_id = ?");
    $stmt->execute([$country_id]);
    $regionCount = $stmt->fetchColumn();

    if ($regionCount > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete country. It is referenced by existing regions.'
        ]);
        exit;
    }

    // Delete country
    $stmt = $pdo->prepare("DELETE FROM country WHERE id = ?");
    $stmt->execute([$country_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Country deleted successfully.',
        'data' => [
            'id' => $country['id'],
            'name' => $country['name']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting country: ' . $e->getMessage()
    ]);
}
?>


