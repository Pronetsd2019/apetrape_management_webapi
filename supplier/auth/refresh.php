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
 * Supplier Refresh Token Endpoint
 * POST /supplier/auth/refresh.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/util/jwt.php';
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Get refresh token from cookie
$refresh_token = $_COOKIE['supplier_refresh_token'] ?? null;

// Debug: Log cookie information
logError('supplier_auth_refresh', 'Cookie check', [
    'cookie_exists' => isset($_COOKIE['supplier_refresh_token']),
    'cookie_value_preview' => $refresh_token ? substr($refresh_token, 0, 10) . '...' : null,
    'all_cookies' => array_keys($_COOKIE),
    'http_host' => $_SERVER['HTTP_HOST'] ?? null,
    'http_origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? null
]);

if (!$refresh_token) {
    http_response_code(401);
    echo json_encode([
        'success' => false, 
        'message' => 'Refresh token not found.',
        'debug' => [
            'cookies_received' => array_keys($_COOKIE),
            'http_host' => $_SERVER['HTTP_HOST'] ?? null,
            'http_origin' => $_SERVER['HTTP_ORIGIN'] ?? null
        ]
    ]);
    exit;
}

try {
    // Validate refresh token in database
    $stmt = $pdo->prepare("
        SELECT rt.id, rt.supplier_id, rt.expires_at, s.status
        FROM supplier_refresh_tokens rt
        INNER JOIN suppliers s ON rt.supplier_id = s.id
        WHERE rt.token = ? AND rt.expires_at > NOW()
    ");
    $stmt->execute([$refresh_token]);
    $token_data = $stmt->fetch();

    if (!$token_data) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired refresh token.']);
        exit;
    }

    // Check if supplier is still active
    // Convert to int to handle string "1" from database
    $status = (int)$token_data['status'];
    if ($status !== 1) {
        // Delete the refresh token
        $stmt = $pdo->prepare("DELETE FROM supplier_refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);

        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is not active.']);
        exit;
    }

    // Get supplier details
    $stmt = $pdo->prepare("SELECT id, email FROM suppliers WHERE id = ?");
    $stmt->execute([$token_data['supplier_id']]);
    $supplier = $stmt->fetch();

    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
        exit;
    }

    // Generate new access token (JWT) - valid for 15 minutes
    $token_payload = [
        'sub' => (int) $supplier['id'],
        'supplier_id' => (int) $supplier['id'],
        'email' => $supplier['email'],
        'type' => 'supplier'
    ];

    $access_token = generateJWT($token_payload, 15);

    // Rotate refresh token (concurrency-safe).
    // If two refresh requests happen at the same time, only one should rotate the token.
    $refresh_token_ttl = 7 * 24 * 60 * 60; // 7 days
    $new_refresh_token = generateRefreshToken();
    $new_refresh_token_expiry = time() + $refresh_token_ttl;

    // Compare-and-swap update: only rotate if the stored token is still the one we validated.
    $stmt = $pdo->prepare("
        UPDATE supplier_refresh_tokens
        SET token = ?, expires_at = FROM_UNIXTIME(?)
        WHERE id = ? AND token = ? AND expires_at > NOW()
    ");
    $stmt->execute([$new_refresh_token, $new_refresh_token_expiry, $token_data['id'], $refresh_token]);

    // Determine which token/expiry we should set as cookie.
    // If another request already rotated it, fetch the current token and set it (prevents logout/race).
    $cookie_token = $new_refresh_token;
    $cookie_expiry = $new_refresh_token_expiry;

    if ($stmt->rowCount() === 0) {
        $stmtCurrent = $pdo->prepare("
            SELECT token, UNIX_TIMESTAMP(expires_at) AS expires_ts
            FROM supplier_refresh_tokens
            WHERE id = ?
        ");
        $stmtCurrent->execute([$token_data['id']]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);

        if (!$current || empty($current['token'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired refresh token.']);
            exit;
        }

        $cookie_token = $current['token'];
        $cookie_expiry = (int)$current['expires_ts'];
    }

    // For response metadata
    $refresh_token_ttl = max(0, (int)$cookie_expiry - time());

    // Detect environment: localhost vs production
    $isLocalhost = (
        ($_SERVER['HTTP_HOST'] ?? '') === 'localhost' ||
        strpos(($_SERVER['HTTP_HOST'] ?? ''), '127.0.0.1') !== false ||
        strpos(($_SERVER['HTTP_HOST'] ?? ''), 'localhost:') === 0
    );
    
    if ($isLocalhost) {
        // Localhost settings - no domain restriction, no secure flag
        setcookie(
            'supplier_refresh_token',
            $cookie_token,
            [
                'expires' => $cookie_expiry,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    } else {
        // Production settings - .apetrape.com domain to share cookies across all subdomains
        // This allows cookies set by webapi.apetrape.com to be accessible by supplier.apetrape.com
        $cookieDomain = '.apetrape.com';
        
        setcookie(
            'supplier_refresh_token',
            $cookie_token,
            [
                'expires' => $cookie_expiry,
                'path' => '/',
                'domain' => $cookieDomain,
                'secure' => true, // HTTPS required for cross-domain cookies
                'httponly' => true,
                'samesite' => 'None' // Required for cross-site cookies
            ]
        );
    }

    // Return new access token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Token refreshed successfully.',
        'data' => [
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 900, // 15 minutes in seconds
            'refresh_expires_in' => $refresh_token_ttl
        ]
    ]);

} catch (PDOException $e) {
    logException('supplier_auth_refresh', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error refreshing token: ' . $e->getMessage()
    ]);
}
?>
