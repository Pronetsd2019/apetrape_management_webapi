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
 * Change Admin Password Endpoint
 * PUT /control/admin/change_password.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
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

// Get the authenticated admin's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$adminId = $authUser['admin_id'] ?? null;

if (!$adminId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unable to identify authenticated admin.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['current_password', 'new_password', 'confirm_password'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Field '$field' is required."]);
        exit;
    }
}

// Validate password confirmation matches
if ($input['new_password'] !== $input['confirm_password']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password confirmation does not match.']);
    exit;
}

// Validate new password is different from current password
if ($input['current_password'] === $input['new_password']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be different from the current password.']);
    exit;
}

// Validate password length (minimum 6 characters)
if (strlen($input['new_password']) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'New password must be at least 6 characters long.']);
    exit;
}

try {
    // Get admin details including password hash
    $stmt = $pdo->prepare("
        SELECT id, email, password_hash, is_active
        FROM admins
        WHERE id = ?
    ");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Check if admin is active
    $is_active = (int)$admin['is_active'];
    if ($is_active !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is not active.']);
        exit;
    }

    // Verify current password
    $password_correct = password_verify($input['current_password'], $admin['password_hash']);

    if (!$password_correct) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    // Hash new password
    $new_password_hash = password_hash($input['new_password'], PASSWORD_DEFAULT);

    // Update password and reset lock stats
    $stmt = $pdo->prepare("
        UPDATE admins
        SET password_hash = ?, failed_attempts = 0, locked_until = NULL, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$new_password_hash, $adminId]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully.'
    ]);

} catch (PDOException $e) {
    logException('admin_change_password', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error changing password: ' . $e->getMessage()
    ]);
}
?>
