<?php
/**
 * Login Endpoint
 * POST /auth/login.php
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['email']) || !isset($input['password'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

try {
    // Get admin by email
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, password_hash, is_active 
        FROM admins 
        WHERE email = ?
    ");
    $stmt->execute([$input['email']]);
    $admin = $stmt->fetch();

    // Check if admin exists and password is correct
    if (!$admin || !password_verify($input['password'], $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Check if admin is active
    if (!$admin['is_active']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is inactive.']);
        exit;
    }

    // Generate refresh token
    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60); // 7 days

    // Store refresh token in database
    $stmt = $pdo->prepare("
        INSERT INTO refresh_tokens (admin_id, token, expires_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$admin['id'], $refresh_token, $refresh_token_expiry]);

    // Generate access token (JWT) - valid for 15 minutes
    $access_token = generateJWT([
        'admin_id' => $admin['id'],
        'email' => $admin['email']
    ], 15);

    // Set refresh token as HTTP-only cookie
    setcookie(
        'refresh_token',
        $refresh_token,
        [
            'expires' => $refresh_token_expiry,
            'path' => '/',
            'domain' => '',
            'secure' => false, // Set to true in production with HTTPS
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );

    // Return response with user info and access token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'name' => $admin['name'],
            'surname' => $admin['surname'],
            'email' => $admin['email'],
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 900 // 15 minutes in seconds
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during login: ' . $e->getMessage()
    ]);
}
?>

