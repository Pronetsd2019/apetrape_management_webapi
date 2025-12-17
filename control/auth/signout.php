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
 * Signout Endpoint
 * POST /auth/signout.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/error_logger.php';
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get refresh token from cookie
$refresh_token = $_COOKIE['refresh_token'] ?? null;

try {
    // If refresh token exists, delete it from database
    if ($refresh_token) {
        $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);
    }

    // Detect environment: localhost vs production
    $isLocalhost = (
        $_SERVER['HTTP_HOST'] === 'localhost' || 
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0
    );
    
    // Clear/expire the refresh token cookie (must use same settings as when set)
    if ($isLocalhost) {
        // Localhost settings
        setcookie(
            'refresh_token',
            '',
            [
                'expires' => time() - 3600, // Set to past time to delete
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    } else {
        // Production settings - use .apetrape.com domain to match the domain used when setting the cookie
        $cookieDomain = '.apetrape.com';
        
        setcookie(
            'refresh_token',
            '',
            [
                'expires' => time() - 3600, // Set to past time to delete
                'path' => '/',
                'domain' => $cookieDomain,
                'secure' => true, // HTTPS required for cross-domain cookies
                'httponly' => true,
                'samesite' => 'None' // Required for cross-site cookies
            ]
        );
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Signed out successfully.'
    ]);

} catch (PDOException $e) {
    logException('auth/signout', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during signout: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('auth/signout', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during signout: ' . $e->getMessage()
    ]);
}
?>

