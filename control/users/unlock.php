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
 * Unlock User Account Endpoint
 * POST /control/users/unlock.php
 * Unlocks a locked user account by resetting failed attempts, lockout time, and lockout stage
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

// Check if the user has permission to update users
if (!checkUserPermission($userId, 'users', 'update')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to update users.']);
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

// Validate user_id
if (!isset($input['id']) || empty($input['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

$user_id = $input['id'];

try {
    // Check if user exists
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, 
               COALESCE(failed_attempts, 0) as failed_attempts,
               locked_until,
               COALESCE(lockout_stage, 0) as lockout_stage
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    // Check if account is actually locked
    $is_locked = $user['locked_until'] && strtotime($user['locked_until']) > time();
    $has_failed_attempts = $user['failed_attempts'] > 0;
    $is_permanently_locked = $user['lockout_stage'] == 3;

    if (!$is_locked && !$has_failed_attempts && !$is_permanently_locked) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Account is not locked.',
            'data' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'surname' => $user['surname'],
                'email' => $user['email'],
                'failed_attempts' => 0,
                'locked_until' => null,
                'lockout_stage' => 0
            ]
        ]);
        exit;
    }

    // Unlock the account - reset all lockout fields
    $stmt = $pdo->prepare("
        UPDATE users 
        SET failed_attempts = 0, 
            locked_until = NULL,
            lockout_stage = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);

    // Fetch updated user
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, 
               COALESCE(failed_attempts, 0) as failed_attempts,
               locked_until,
               COALESCE(lockout_stage, 0) as lockout_stage
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $unlocked_user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Log the unlock action
    logError('users_unlock', 'User account unlocked by admin', [
        'user_id' => $user_id,
        'unlocked_by_admin_id' => $userId,
        'previous_lockout_stage' => $user['lockout_stage'],
        'previous_failed_attempts' => $user['failed_attempts'],
        'previous_locked_until' => $user['locked_until']
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Account unlocked successfully.',
        'data' => [
            'id' => $unlocked_user['id'],
            'name' => $unlocked_user['name'],
            'surname' => $unlocked_user['surname'],
            'email' => $unlocked_user['email'],
            'failed_attempts' => 0,
            'locked_until' => null,
            'lockout_stage' => 0
        ]
    ]);

} catch (PDOException $e) {
    logException('users_unlock', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while unlocking the account.',
        'error_details' => 'Error unlocking account: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('users_unlock', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while unlocking the account.',
        'error_details' => 'Error unlocking account: ' . $e->getMessage()
    ]);
}
?>

