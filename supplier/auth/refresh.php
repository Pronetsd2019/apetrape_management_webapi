<?php
/**
 * Supplier Refresh Token Endpoint
 * POST /supplier/auth/refresh.php
 */

require_once __DIR__ . '/../../control/util/connect.php';
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

if (!$refresh_token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Refresh token not found.']);
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
    if ($token_data['status'] !== 'active') {
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

    // Rotate refresh token (issue new token and update cookie)
    $refresh_token_ttl = 7 * 24 * 60 * 60; // 7 days
    $new_refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + $refresh_token_ttl;

    $stmt = $pdo->prepare("
        UPDATE supplier_refresh_tokens
        SET token = ?, expires_at = FROM_UNIXTIME(?)
        WHERE id = ?
    ");
    $stmt->execute([$new_refresh_token, $refresh_token_expiry, $token_data['id']]);

    setcookie(
        'supplier_refresh_token',
        $new_refresh_token,
        [
            'expires' => $refresh_token_expiry,
            'path' => '/',
            'domain' => '',
            'secure' => false, // Set to true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );

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
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error refreshing token: ' . $e->getMessage()
    ]);
}
?>
