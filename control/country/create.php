<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
/**
 * Create Country Endpoint
 * POST /country/create.php
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
if (!checkUserPermission($userId, 'locations', 'create')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to create a country.']);
    exit;
}

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
    logException('country_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating country: ' . $e->getMessage()
    ]);
}
?>


