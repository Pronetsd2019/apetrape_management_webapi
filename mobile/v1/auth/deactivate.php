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
 * Mobile User Account Deactivation Endpoint
 * POST /mobile/v1/auth/deactivate.php
 * Requires JWT authentication - user must be logged in to deactivate their account
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/../util/auth_middleware.php';

// Ensure the request is authenticated
requireMobileJwtAuth();

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
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
if (!isset($input['reasons']) || !is_array($input['reasons']) || empty($input['reasons'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'At least one reason is required.'
    ]);
    exit;
}

$reasons = $input['reasons'];
$other_reason = isset($input['other_reason']) ? trim($input['other_reason']) : null;

// Validate that if "Other" is in reasons, other_reason must be provided
if (in_array('Other', $reasons) && (empty($other_reason) || strlen($other_reason) < 3)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Please provide a detailed explanation when selecting "Other" as a reason.'
    ]);
    exit;
}

// Validate other_reason length if provided
if ($other_reason && strlen($other_reason) > 1000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Other reason must be 1000 characters or less.'
    ]);
    exit;
}

try {
    // Verify user exists and is not already deactivated
    $stmt = $pdo->prepare("SELECT id, email, status FROM users WHERE id = ?");
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

    if ($user['status'] == -2) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Already deactivated',
            'message' => 'Your account is already deactivated.'
        ]);
        exit;
    }

    // Start transaction
    $pdo->beginTransaction();

    // Update user status to -2 (deactivated)
    $stmt = $pdo->prepare("
        UPDATE users 
        SET status = -2, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);

    // Insert deactivation reasons
    $deactivation_id = null;
    $stmt = $pdo->prepare("
        INSERT INTO user_deactivations (user_id, other_reason)
        VALUES (?, ?)
    ");
    $stmt->execute([$user_id, $other_reason]);
    $deactivation_id = $pdo->lastInsertId();

    // Insert individual reasons
    $stmt = $pdo->prepare("
        INSERT INTO user_deactivation_reasons (deactivation_id, reason)
        VALUES (?, ?)
    ");
    
    foreach ($reasons as $reason) {
        $stmt->execute([$deactivation_id, trim($reason)]);
    }

    // Delete all refresh tokens for this user
    $stmt = $pdo->prepare("DELETE FROM mobile_refresh_tokens WHERE user_id = ?");
    $stmt->execute([$user_id]);

    // Commit transaction
    $pdo->commit();

    // Log the deactivation
    logError('mobile_auth_deactivate', 'User account deactivated', [
        'user_id' => $user_id,
        'email' => $user['email'],
        'reasons' => $reasons,
        'has_other_reason' => !empty($other_reason),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Your account has been deactivated successfully. We\'re sorry to see you go.'
    ]);

} catch (PDOException $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logException('mobile_auth_deactivate', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while deactivating your account. Please try again later.',
        'error_details' => 'Error deactivating account: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logException('mobile_auth_deactivate', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while deactivating your account. Please try again later.',
        'error_details' => 'Error deactivating account: ' . $e->getMessage()
    ]);
}
?>

