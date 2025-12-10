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
 * Update Quotation Status Endpoint
 * PUT /quotations/update_status.php
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
 if (!checkUserPermission($userId, 'quotations', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update quotations.']);
     exit;
 }

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['quotation_id']) || empty($input['quotation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "quote id" is required.']);
    exit;
}

if (!isset($input['status']) || empty(trim($input['status']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Field "status" is required.']);
    exit;
}

$quote_id = $input['quotation_id'];
$status = trim($input['status']);

try {
    // Check if quotation exists
    $stmt = $pdo->prepare("SELECT id, status FROM quotations WHERE id = ?");
    $stmt->execute([$quote_id]);
    $existing_quote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing_quote) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Quotation not found.']);
        exit;
    }

    // Update status
    $stmt = $pdo->prepare("UPDATE quotations SET status = ? WHERE id = ?");
    $stmt->execute([$status, $quote_id]);

    // Fetch updated quotation
    $stmt = $pdo->prepare("
        SELECT 
            id,
            user_id,
            status,
            quote_no,
            customer_name,
            customer_cell,
            customer_address,
            sent_date,
            created_at,
            updated_at
        FROM quotations
        WHERE id = ?
    ");
    $stmt->execute([$quote_id]);
    $updated_quote = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Quotation status updated successfully.',
        'data' => $updated_quote
    ]);

} catch (PDOException $e) {
    logException('quotations_update_status', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error updating quotation status: ' . $e->getMessage()
    ]);
}
?>

