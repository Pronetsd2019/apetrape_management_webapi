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
 * Reset Admin Password Endpoint
 * POST /admin/password_reset.php
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
 if (!checkUserPermission($userId, 'administration', 'update')) {
     http_response_code(403);
     echo json_encode(['success' => false, 'message' => 'You do not have permission to update administrators.']);
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
$required_fields = ['id', 'password', 'password_confirmation'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// Validate password confirmation
if ($input['password'] !== $input['password_confirmation']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password confirmation does not match.']);
    exit;
}

$admin_id = $input['id'];

try {
    // Check if admin exists
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email
        FROM admins
        WHERE id = ?
    ");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Hash new password
    $password_hash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Update password and reset lock status
    $stmt = $pdo->prepare("
        UPDATE admins
        SET password_hash = ?, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$password_hash, $admin_id]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully.',
        'data' => [
            'id' => $admin['id'],
            'name' => $admin['name'],
            'surname' => $admin['surname'],
            'email' => $admin['email']
        ]
    ]);

} catch (PDOException $e) {
    logException('admin_password_reset', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error resetting password: ' . $e->getMessage()
    ]);
}
?>


