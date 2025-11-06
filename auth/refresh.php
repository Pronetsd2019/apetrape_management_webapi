<?php
/**
 * Refresh Token Endpoint
 * POST /auth/refresh.php
 */

require_once __DIR__ . '/../util/connect.php';
require_once __DIR__ . '/../util/jwt.php';
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
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired refresh token.']);
        exit;
    }

    // Check if admin is still active
    if (!$token_data['is_active']) {
        // Delete the refresh token
        $stmt = $pdo->prepare("DELETE FROM refresh_tokens WHERE token = ?");
        $stmt->execute([$refresh_token]);
        
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
    $access_token = generateJWT([
        'admin_id' => $admin['id'],
        'email' => $admin['email']
    ], 15);

    // Return new access token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Token refreshed successfully.',
        'data' => [
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 900 // 15 minutes in seconds
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

