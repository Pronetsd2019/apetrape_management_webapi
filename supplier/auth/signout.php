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
 * Supplier Signout Endpoint
 * POST /supplier/auth/signout.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get supplier refresh token from cookie
$refresh_token = $_COOKIE['supplier_refresh_token'] ?? null;

try {
    // If refresh token exists, delete it from database
    if ($refresh_token) {
        $stmt = $pdo->prepare("DELETE FROM supplier_refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);
    }

    // Use .apetrape.com domain to match the domain used when setting the cookie
    $cookieDomain = '.apetrape.com';
    
    // Clear/expire the supplier refresh token cookie (must use same domain as when set)
    setcookie(
        'supplier_refresh_token',
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

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Signed out successfully.'
    ]);

} catch (PDOException $e) {
    logException('supplier_auth_signout', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during signout: ' . $e->getMessage()
    ]);
}
?>
