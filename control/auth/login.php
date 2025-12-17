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
 * Login Endpoint
 * POST /auth/login.php
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
    // Get admin by email (including lockout fields and role)
    $stmt = $pdo->prepare("
        SELECT a.id, a.name, a.surname, a.email, a.password_hash, a.is_active,
               a.failed_attempts, a.locked_until, a.role_id,
               r.role_name, r.description as role_description, r.status as role_status
        FROM admins a
        LEFT JOIN roles r ON a.role_id = r.id
        WHERE a.email = ?
    ");
    $stmt->execute([$input['email']]);
    $admin = $stmt->fetch();

    // Get IP address and user agent for logging
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Check if admin exists
    if (!$admin) {
        // Log failed login attempt (invalid email)
        logError('auth/login', 'Login attempt with invalid email', [
            'email' => $input['email'],
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ]);
        
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Check if account is locked
    if ($admin['locked_until'] && strtotime($admin['locked_until']) > time()) {
        $locked_until = strtotime($admin['locked_until']);
        $remaining_minutes = ceil(($locked_until - time()) / 60);
        
        logError('auth/login', 'Login attempt on locked account', [
            'admin_id' => $admin['id'],
            'email' => $admin['email'],
            'locked_until' => $admin['locked_until'],
            'remaining_minutes' => $remaining_minutes,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ]);
        
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

    // Check if user's role is blocked/inactive
    if ($admin['role_id'] && isset($admin['role_status']) && $admin['role_status'] != 1) {
        // Log failed login attempt (blocked role)
        $stmt = $pdo->prepare("
            INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$admin['id'], $ip_address, $user_agent]);
        
        logError('auth/login', 'Login attempt with blocked/inactive role', [
            'admin_id' => $admin['id'],
            'email' => $admin['email'],
            'role_id' => $admin['role_id'],
            'role_name' => $admin['role_name'],
            'role_status' => $admin['role_status'],
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ]);
        
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your role has been blocked. Please contact administrator.']);
        exit;
    }

    // Check if admin is active
    if (!$admin['is_active']) {
        // Log failed login attempt (inactive account)
        $stmt = $pdo->prepare("
            INSERT INTO login_logs (admin_id, ip_address, user_agent, success)
            VALUES (?, ?, ?, 0)
        ");
        $stmt->execute([$admin['id'], $ip_address, $user_agent]);
        
        logError('auth/login', 'Login attempt on inactive account', [
            'admin_id' => $admin['id'],
            'email' => $admin['email'],
            'ip_address' => $ip_address,
            'user_agent' => $user_agent
        ]);
        
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

    // Get permissions for the user's role
    $permissions = [];
    if ($admin['role_id']) {
        $stmtPermissions = $pdo->prepare("
            SELECT
                rmp.module_id,
                m.module_name,
                rmp.can_read,
                rmp.can_create,
                rmp.can_update,
                rmp.can_delete
            FROM role_module_permissions rmp
            INNER JOIN modules m ON rmp.module_id = m.id
            WHERE rmp.role_id = ?
            ORDER BY m.module_name ASC
        ");
        $stmtPermissions->execute([$admin['role_id']]);
        $permissions = $stmtPermissions->fetchAll(PDO::FETCH_ASSOC);
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
    $token_payload = [
        'sub' => (int) $admin['id'],
        'admin_id' => (int) $admin['id'],
        'email' => $admin['email']
    ];

    $access_token = generateJWT($token_payload, 15);

    // Set refresh token as HTTP-only cookie
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
            $refresh_token,
            [
                'expires' => $refresh_token_expiry,
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
            $refresh_token,
            [
                'expires' => $refresh_token_expiry,
                'path' => '/',
                'domain' => $cookieDomain,
                'secure' => true, // HTTPS required for cross-domain cookies
                'httponly' => true,
                'samesite' => 'None' // Required for cross-site cookies
            ]
        );
    }

    // Return response with user info, role, permissions and access token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'user' => [
                'id' => (int)$admin['id'],
                'name' => $admin['name'],
                'surname' => $admin['surname'],
                'email' => $admin['email']
            ],
            'role' => $admin['role_id'] ? [
                'id' => (int)$admin['role_id'],
                'role_name' => $admin['role_name'],
                'description' => $admin['role_description'],
                'status' => $admin['role_status']
            ] : null,
            'permissions' => $permissions,
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 900 // 15 minutes in seconds
        ]
    ]);

} catch (PDOException $e) {
    logException('auth/login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during login: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('auth/login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during login: ' . $e->getMessage()
    ]);
}
?>

