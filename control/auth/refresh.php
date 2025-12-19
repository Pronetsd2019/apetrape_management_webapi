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
 * Refresh Token Endpoint
 * POST /auth/refresh.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/jwt.php';
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

if (!$refresh_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Refresh token not found.']);
    exit;
}

try {
    // Validate refresh token in database
    $stmt = $pdo->prepare("
        SELECT rt.id, rt.admin_id, rt.expires_at, a.is_active
        FROM refresh_tokens rt
        INNER JOIN admins a ON rt.admin_id = a.id
        WHERE rt.token = ? AND rt.expires_at > NOW()
    ");
    $stmt->execute([$refresh_token]);
    $token_data = $stmt->fetch();

    if (!$token_data) {
        logError('auth/refresh', 'Invalid or expired refresh token', [
            'token_preview' => substr($refresh_token, 0, 10) . '...',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired refresh token.']);
        exit;
    }

    // Check if admin is still active
    if (!$token_data['is_active']) {
        // Delete the refresh token
        $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);
        
        logError('auth/refresh', 'Token refresh attempt for inactive account', [
            'admin_id' => $token_data['admin_id'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is inactive.']);
        exit;
    }

    // Get admin details
    $stmt = $pdo->prepare("SELECT id, email FROM admins WHERE id = ?");
    $stmt->execute([$token_data['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Admin not found.']);
        exit;
    }

    // Generate new access token (JWT) - valid for 15 minutes
    $token_payload = [
        'sub' => (int) $admin['id'],
        'admin_id' => (int) $admin['id'],
        'email' => $admin['email']
    ];

    $access_token = generateJWT($token_payload, 15);

    // Rotate refresh token (concurrency-safe).
    // If two refresh requests happen at the same time, only one should rotate the token.
    $refresh_token_ttl = 7 * 24 * 60 * 60; // 7 days
    $new_refresh_token = generateRefreshToken();
    $new_refresh_token_expiry = time() + $refresh_token_ttl;

    // Compare-and-swap update: only rotate if the stored token is still the one we validated.
    $stmt = $pdo->prepare("
        UPDATE refresh_tokens
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
            FROM refresh_tokens
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
        $_SERVER['HTTP_HOST'] === 'localhost' || 
        strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0
    );
    
    if ($isLocalhost) {
        // Localhost settings - no domain restriction, no secure flag
        setcookie(
            'refresh_token',
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
        // This allows cookies set by webapi.apetrape.com to be accessible by admin.apetrape.com and supplier.apetrape.com
        // Admin uses 'refresh_token' cookie name, supplier uses 'supplier_refresh_token' - so they don't conflict
        $cookieDomain = '.apetrape.com';
        
        setcookie(
            'refresh_token',
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
    logException('auth/refresh', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error refreshing token: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('auth/refresh', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error refreshing token: ' . $e->getMessage()
    ]);
}
?>

