<?php

// CORS headers for subdomain support and localhost
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';
$isLocalhostOrigin = isset($_SERVER['HTTP_ORIGIN']) && (
    strpos($_SERVER['HTTP_ORIGIN'], 'http://localhost') === 0 ||
    strpos($_SERVER['HTTP_ORIGIN'], 'http://127.0.0.1') === 0
);

if ((isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) || $isLocalhostOrigin) {
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
 * Mobile User Update Profile Endpoint
 * PUT /mobile/v1/user/update.php
 * Requires JWT authentication - user must be logged in to update their profile
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow PUT method
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use PUT.']);
    exit;
}

// Get the authenticated user's ID from the JWT payload
$authUser = $GLOBALS['auth_user'] ?? null;
$user_id = (int)($authUser['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Unable to identify authenticated user.'
    ]);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required_fields = ['name', 'email', 'cell'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => "Field '{$field}' is required."
        ]);
        exit;
    }
}

$name = trim($input['name']);
$email = trim($input['email']);
$cell = trim($input['cell']);

// Validate name length
if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Name must be at least 2 characters long.'
    ]);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Invalid email address format.'
    ]);
    exit;
}

// Validate cell length
if (strlen($cell) < 7) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Cell phone number must be at least 7 digits.'
    ]);
    exit;
}

try {
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, email, cell, status FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Not found',
            'message' => 'User not found.'
        ]);
        exit;
    }

    // Check if user account is active
    if ($user['status'] != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Your account is not active. Please contact support.'
        ]);
        exit;
    }

    // Check for email conflicts (excluding current user)
    if (strtolower($email) !== strtolower($user['email'])) {
        $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id != ?");
        $checkEmailStmt->execute([$email, $user_id]);
        $emailConflict = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);

        if ($emailConflict) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Conflict',
                'message' => 'Email address is already in use by another user.'
            ]);
            exit;
        }
    }

    // Check for cell conflicts (excluding current user)
    if ($cell !== $user['cell']) {
        $checkCellStmt = $pdo->prepare("SELECT id FROM users WHERE cell = ? AND id != ?");
        $checkCellStmt->execute([$cell, $user_id]);
        $cellConflict = $checkCellStmt->fetch(PDO::FETCH_ASSOC);

        if ($cellConflict) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Conflict',
                'message' => 'Cell phone number is already in use by another user.'
            ]);
            exit;
        }
    }

    // Update the user
    $updateStmt = $pdo->prepare("
        UPDATE users
        SET name = ?, email = ?, cell = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $updateStmt->execute([$name, $email, $cell, $user_id]);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to update profile. Please try again later.',
            'error_details' => 'Failed to update user.'
        ]);
        exit;
    }

    // Fetch updated user
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, avatar, created_at, updated_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Log the update
    logError('mobile_user_update', 'User profile updated', [
        'user_id' => $user_id,
        'email' => $email,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'data' => [
            'id' => (string)$updated_user['id'],
            'name' => $updated_user['name'],
            'email' => $updated_user['email'],
            'phone' => $updated_user['cell'],
            'avatar' => $updated_user['avatar'],
            'created_at' => $updated_user['created_at'],
            'updated_at' => $updated_user['updated_at']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_user_update', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your profile. Please try again later.',
        'error_details' => 'Error updating user: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_user_update', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while updating your profile. Please try again later.',
        'error_details' => 'Error updating user: ' . $e->getMessage()
    ]);
}
?>

