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
$required_fields = ['manufacturer_id', 'model_name', 'variant', 'year_from', 'year_to'];
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

    if (!is_numeric($input['year_from']) || !is_numeric($input['year_to'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Fields \'year_from\' and \'year_to\' must be numeric.']);
        exit;
    }

    $year_from = (int)$input['year_from'];
    $year_to = (int)$input['year_to'];

    if ($year_from <= 0 || $year_to <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Fields \'year_from\' and \'year_to\' must be positive integers.']);
        exit;
    }

    if ($year_to < $year_from) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '\'year_to\' cannot be less than \'year_from\'.']);
        exit;
    }

    // Insert vehicle model
    $stmt = $pdo->prepare("
        INSERT INTO vehicle_models (manufacturer_id, model_name, variant, year_from, year_to)
        VALUES (?, ?, ?, ?, ?)
    ");

    $variant = $input['variant'];

    $stmt->execute([
        $input['manufacturer_id'],
        $input['model_name'],
        $variant,
        $year_from,
        $year_to
    ]);

    $vehicle_model_id = $pdo->lastInsertId();

    // Fetch created vehicle model
    $stmt = $pdo->prepare("
        SELECT vm.id, vm.manufacturer_id, vm.model_name, vm.variant, 
               vm.year_from, vm.year_to, vm.created_at, vm.updated_at,
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

