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
 * Create Region Endpoint
 * POST /region/create.php
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
    echo json_encode(['success' => false, 'message' => 'You do not have permission to write a region.']);
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
    logException('region_create', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error creating region: ' . $e->getMessage()
    ]);
}
?>


