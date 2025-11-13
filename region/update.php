<?php
/**
 * Update Region Endpoint
 * PUT /region/update.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required field
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Region ID is required.']);
    exit;
}

$region_id = $input['id'];

try {
    // Fetch existing region
    $stmt = $pdo->prepare("SELECT id, name, country_id, entry FROM region WHERE id = ?");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$region) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Region not found.']);
        exit;
    }

    $update_fields = [];
    $params = [];

    // Update name if provided
    if (isset($input['region_name'])) {
        $name = trim($input['region_name']);
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Region name cannot be empty.']);
            exit;
        }

        $country_id_for_unique_check = isset($input['country_id']) && $input['country_id'] !== ''
            ? $input['country_id']
            : $region['country_id'];

        $stmt = $pdo->prepare("SELECT id FROM region WHERE LOWER(name) = LOWER(?) AND country_id = ? AND id != ?");
        $stmt->execute([$name, $country_id_for_unique_check, $region_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Region name already exists for this country.']);
            exit;
        }

        $update_fields[] = "name = ?";
        $params[] = $name;
    }

    // Update country_id if provided
    if (isset($input['country_id'])) {
        if ($input['country_id'] === null || $input['country_id'] === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Country ID cannot be empty.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM country WHERE id = ?");
        $stmt->execute([$input['country_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Country not found.']);
            exit;
        }

        $update_fields[] = "country_id = ?";
        $params[] = $input['country_id'];
    }

    // Update entry if provided
    if (array_key_exists('entry', $input)) {
        $update_fields[] = "entry = ?";
        $params[] = $input['entry'] !== '' ? $input['entry'] : null;
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    $update_fields[] = "updated_at = NOW()";

    $params[] = $region_id;

    $sql = "UPDATE region SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated region
    $stmt = $pdo->prepare("SELECT id, name, country_id, entry, updated_at FROM region WHERE id = ?");
    $stmt->execute([$region_id]);
    $updated_region = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Region updated successfully.',
        'data' => $updated_region
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating region: ' . $e->getMessage()
    ]);
}
?>


