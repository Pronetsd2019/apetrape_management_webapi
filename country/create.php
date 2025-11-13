<?php
/**
 * Create Country Endpoint
 * POST /country/create.php
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

// Validate required field
if (!isset($input['country_name']) || empty(trim($input['country_name']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "country name" is required.']);
    exit;
}

$name = trim($input['country_name']);
$entry = isset($input['entry']) && $input['entry'] !== '' ? trim($input['entry']) : null;

try {
    // Check for duplicate country name (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM country WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Country name already exists.']);
        exit;
    }

    // Insert country
    $stmt = $pdo->prepare("
        INSERT INTO country (name, entry)
        VALUES (?, ?)
    ");
    $stmt->execute([$name, $entry]);

    $country_id = $pdo->lastInsertId();

    // Fetch created country
    $stmt = $pdo->prepare("
        SELECT id, name, entry, updated_at
        FROM country
        WHERE id = ?
    ");
    $stmt->execute([$country_id]);
    $country = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Country created successfully.',
        'data' => $country
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating country: ' . $e->getMessage()
    ]);
}
?>


