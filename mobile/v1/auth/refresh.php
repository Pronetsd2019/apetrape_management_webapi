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
 * Mobile Token Refresh Endpoint
 * POST /mobile/auth/refresh.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/jwt.php';
require_once __DIR__ . '/../../control/util/error_logger.php';

header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Get refresh token from request body (mobile apps don't use cookies)
$refresh_token = isset($input['refresh_token']) ? trim($input['refresh_token']) : null;

if (!$refresh_token) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Refresh token not found.'
    ]);
    exit;
}

try {
    // Validate refresh token in database
    $stmt = $pdo->prepare("
        SELECT rt.id, rt.user_id, rt.expires_at, u.status
        FROM mobile_refresh_tokens rt
        INNER JOIN users u ON rt.user_id = u.id
        WHERE rt.token = ? AND rt.expires_at > NOW()
    ");
    $stmt->execute([$refresh_token]);
    $token_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token_data) {
        logError('mobile_auth_refresh', 'Invalid or expired refresh token', [
            'token_preview' => substr($refresh_token, 0, 10) . '...',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid or expired refresh token.'
        ]);
        exit;
    }

    // Check if user account is still active
    if ($token_data['status'] != 1) {
        // Delete the refresh token
        $stmt = $pdo->prepare("DELETE FROM mobile_refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);
        
        logError('mobile_auth_refresh', 'Token refresh attempt for inactive account', [
            'user_id' => $token_data['user_id'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Account is inactive.'
        ]);
        exit;
    }

    // Get user details
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->execute([$token_data['user_id']]);
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

    // Generate new access token (JWT) - valid for 1 hour (3600 seconds)
    $token_payload = [
        'sub' => (int) $user['id'],
        'user_id' => (int) $user['id'],
        'email' => $user['email']
    ];

    $access_token = generateJWT($token_payload, 60); // 60 minutes = 3600 seconds

    // Rotate refresh token (security best practice - prevents token reuse)
    $refresh_token_ttl = 7 * 24 * 60 * 60; // 7 days
    $new_refresh_token = generateRefreshToken();
    $new_refresh_token_expiry = time() + $refresh_token_ttl;

    // Compare-and-swap update: only rotate if the stored token is still the one we validated
    $stmt = $pdo->prepare("
        UPDATE mobile_refresh_tokens
        SET token = ?, expires_at = FROM_UNIXTIME(?)
        WHERE id = ? AND token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$new_refresh_token, $new_refresh_token_expiry, $token_data['id'], $refresh_token]);

    // Determine which token/expiry we should return
    $cookie_token = $new_refresh_token;
    $cookie_expiry = $new_refresh_token_expiry;

    if ($stmt->rowCount() === 0) {
        // Token was already rotated by another request, fetch current token
        $stmtCurrent = $pdo->prepare("
            SELECT token, UNIX_TIMESTAMP(expires_at) AS expires_ts
            FROM mobile_refresh_tokens
            WHERE id = ?
        ");
        $stmtCurrent->execute([$token_data['id']]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

        if (!$current || empty($current['token'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired refresh token.'
            ]);
            exit;
        }

        $cookie_token = $current['token'];
        $cookie_expiry = (int)$current['expires_ts'];
    }

    // Calculate remaining refresh token TTL
    $refresh_token_ttl = max(0, (int)$cookie_expiry - time());

    // Return new access token and refresh token
    http_response_code(200);
    echo json_encode([
        'access_token' => $access_token,
        'refresh_token' => $cookie_token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_expires_in' => $refresh_token_ttl
    ]);

} catch (PDOException $e) {
    logException('mobile_auth_refresh', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while refreshing your token. Please try again later.',
        'error_details' => 'Error refreshing token: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_auth_refresh', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while refreshing your token. Please try again later.',
        'error_details' => 'Error refreshing token: ' . $e->getMessage()
    ]);
}
?>

