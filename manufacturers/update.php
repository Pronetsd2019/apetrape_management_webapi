<?php
/**
 * Update Manufacturer Endpoint
 * PUT /manufacturers/update.php
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

// Validate manufacturer_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Manufacturer ID is required.']);
    exit;
}

$manufacturer_id = $input['id'];

try {
    // Check if manufacturer exists
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // Build update query dynamically
    $update_fields = [];
    $params = [];

    if (isset($input['name'])) {
        // Check if name already exists for another manufacturer
        $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ? AND id != ?");
        $stmt->execute([$input['name'], $manufacturer_id]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Manufacturer name already exists.']);
            exit;
        }
        $update_fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['description'])) {
        $update_fields[] = "description = ?";
        $params[] = $input['description'];
    }

    if (empty($update_fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update.']);
        exit;
    }

    $params[] = $manufacturer_id;

    // Execute update
    $sql = "UPDATE manufacturers SET " . implode(', ', $update_fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Fetch updated manufacturer
    $stmt = $pdo->prepare("SELECT id, name, description, created_at, updated_at FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer updated successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating manufacturer: ' . $e->getMessage()
    ]);
}
?>

