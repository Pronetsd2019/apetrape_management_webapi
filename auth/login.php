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

// Configuration
define('MAX_FAILED_ATTEMPTS', 5);
define('LOCKOUT_DURATION_MINUTES', 30); // Lock account for 30 minutes

try {
    // Get admin by email (including lockout fields)
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, password_hash, is_active, 
               failed_attempts, locked_until
        FROM admins 
        WHERE email = ?
    ");
    $stmt->execute([$input['email']]);
    $admin = $stmt->fetch();

    // Get IP address and user agent for logging
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Check if admin exists
    if (!$admin) {
        // Can't log invalid email since admin_id is required
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Check if account is locked
    if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
        $locked_until = strtotime($admin['locked_until']);
        $remaining_minutes = ceil(($locked_until - time()) / 60);
        
        http_response_code(423); // 423 Locked
        echo json_encode([
            'success' => false, 
            'message' => "Account is locked due to too many failed login attempts. Please try again in {$remaining_minutes} minute(s) or contact administrator."
        ]);
        exit;
    }

    // If lockout period has expired, reset failed attempts and locked_until
    if ($admin['locked_until'] && strtotime($admin['locked_until']) <= time()) {
        $stmt = $pdo->prepare("
            UPDATE admins 
            SET failed_attempts = 0, locked_until = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$admin['id']]);
        $admin['failed_attempts'] = 0;
        $admin['locked_until'] = null;
    }

    // Check if password is correct
    $password_correct = password_verify($input['password'], $admin['password_hash']);
    
    if (!$password_correct) {
        // Increment failed attempts
        $new_failed_attempts = ($admin['failed_attempts'] ?? 0) + 1;
        
        // Lock account if max attempts reached
        if ($new_failed_attempts >= MAX_FAILED_ATTEMPTS) {
            $lockout_until = date('Y-m-d H:i:s', time() + (LOCKOUT_DURATION_MINUTES * 60));
            $stmt = $pdo->prepare("
                UPDATE admins 
                SET failed_attempts = ?, locked_until = ? 
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $lockout_until, $admin['id']]);
            
            // Log failed login attempt (wrong password)
            $stmt = $pdo->prepare("
                INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$admin['id'], $ip_address, $user_agent]);
            
            http_response_code(423); // 423 Locked
            echo json_encode([
                'success' => false, 
                'message' => "Account has been locked due to too many failed login attempts. Please try again in " . LOCKOUT_DURATION_MINUTES . " minute(s) or contact administrator."
            ]);
            exit;
        } else {
            // Update failed attempts counter
            $stmt = $pdo->prepare("
                UPDATE admins 
                SET failed_attempts = ? 
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $admin['id']]);
            
            $remaining_attempts = MAX_FAILED_ATTEMPTS - $new_failed_attempts;
            
            // Log failed login attempt (wrong password)
            $stmt = $pdo->prepare("
                INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$admin['id'], $ip_address, $user_agent]);
            
            http_response_code(401);
            echo json_encode([
                'success' => false, 
                'message' => "Invalid email or password. {$remaining_attempts} attempt(s) remaining."
            ]);
            exit;
        }
    }

    // Check if admin is active
    if (!$admin['is_active']) {
        // Log failed login attempt (inactive account)
        $stmt = $pdo->prepare("
            INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$admin['id'], $ip_address, $user_agent]);
        
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is inactive.']);
        exit;
    }

    // Reset failed attempts on successful login
    if ($admin['failed_attempts'] > 0 || $admin['locked_until']) {
        $stmt = $pdo->prepare("
            UPDATE admins 
            SET failed_attempts = 0, locked_until = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$admin['id']]);
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

    // Log successful login
    $stmt = $pdo->prepare("
        INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$admin['id'], $ip_address, $user_agent]);

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

