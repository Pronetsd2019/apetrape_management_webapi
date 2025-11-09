<?php
/**
 * Create Vehicle Model Endpoint
 * POST /vehicle_models/create.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../middleware/auth_middleware.php';

// Ensure the request is authenticated
requireJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['manufacturer_id', 'model_name'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

try {
    // Validate manufacturer_id exists
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE id = ?");
    $stmt->execute([$input['manufacturer_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Manufacturer not found.']);
        exit;
    }

    // Insert vehicle model
    $stmt = $pdo->prepare("
        INSERT INTO vehicle_models (manufacturer_id, model_name, variant, year_from, year_to, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $variant = $input['variant'] ?? null;
    $year_from = isset($input['year_from']) ? (int)$input['year_from'] : null;
    $year_to = isset($input['year_to']) ? (int)$input['year_to'] : null;
    $description = $input['description'] ?? null;

    $stmt->execute([
        $input['manufacturer_id'],
        $input['model_name'],
        $variant,
        $year_from,
        $year_to,
        $description
    ]);

    $vehicle_model_id = $pdo->lastInsertId();

    // Fetch created vehicle model
    $stmt = $pdo->prepare("
        SELECT vm.id, vm.manufacturer_id, vm.model_name, vm.variant, 
               vm.year_from, vm.year_to, vm.description, vm.created_at, vm.updated_at,
               m.name as manufacturer_name
        FROM vehicle_models vm
        INNER JOIN manufacturers m ON vm.manufacturer_id = m.id
        WHERE vm.id = ?
    ");
    $stmt->execute([$vehicle_model_id]);
    $vehicle_model = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Vehicle model created successfully.',
        'data' => $vehicle_model
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating vehicle model: ' . $e->getMessage()
    ]);
}
?>

