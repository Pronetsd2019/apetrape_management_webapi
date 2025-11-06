<?php
/**
 * Create Manufacturer Endpoint
 * POST /manufacturers/create.php
 */

require_once __DIR__ . '/../util/connect.php';
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
if (!isset($input['name']) || empty($input['name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "name" is required.']);
    exit;
}

try {
    // Check if manufacturer name already exists
    $stmt = $pdo->prepare("SELECT id FROM manufacturers WHERE name = ?");
    $stmt->execute([$input['name']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Manufacturer name already exists.']);
        exit;
    }

    // Insert manufacturer
    $stmt = $pdo->prepare("
        INSERT INTO manufacturers (name, description)
        VALUES (?, ?)
    ");

    $description = $input['description'] ?? null;

    $stmt->execute([
        $input['name'],
        $description
    ]);

    $manufacturer_id = $pdo->lastInsertId();

    // Fetch created manufacturer
    $stmt = $pdo->prepare("SELECT id, name, description, created_at, updated_at FROM manufacturers WHERE id = ?");
    $stmt->execute([$manufacturer_id]);
    $manufacturer = $stmt->fetch();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Manufacturer created successfully.',
        'data' => $manufacturer
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating manufacturer: ' . $e->getMessage()
    ]);
}
?>

