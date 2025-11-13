<?php
/**
 * Create Region Endpoint
 * POST /region/create.php
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
if (!isset($input['region_name']) || empty(trim($input['region_name']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "region name" is required.']);
    exit;
}

if (!isset($input['country_id']) || empty($input['country_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "country_id" is required.']);
    exit;
}

$name = trim($input['region_name']);
$country_id = $input['country_id'];
$entry = isset($input['entry']) && $input['entry'] !== '' ? trim($input['entry']) : null;

try {
    // Validate country exists
    $stmt = $pdo->prepare("SELECT id FROM country WHERE id = ?");
    $stmt->execute([$country_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Country not found.']);
        exit;
    }

    // Check for duplicate region name within the same country (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM region WHERE LOWER(name) = LOWER(?) AND country_id = ?");
    $stmt->execute([$name, $country_id]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Region name already exists for this country.']);
        exit;
    }

    // Insert region
    $stmt = $pdo->prepare("
        INSERT INTO region (name, country_id, entry)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$name, $country_id, $entry]);

    $region_id = $pdo->lastInsertId();

    // Fetch created region
    $stmt = $pdo->prepare("
        SELECT id, name, country_id, entry, updated_at
        FROM region
        WHERE id = ?
    ");
    $stmt->execute([$region_id]);
    $region = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Region created successfully.',
        'data' => $region
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating region: ' . $e->getMessage()
    ]);
}
?>


