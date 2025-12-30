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
 * Mobile User Login Endpoint
 * POST /mobile/v1/auth/login.php
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/jwt.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';

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
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Email and password are required.'
    ]);
    exit;
}

$email = trim($input['email']);
$password = $input['password'];

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Invalid email address format.'
    ]);
    exit;
}

// Lockout configuration
define('MAX_FAILED_ATTEMPTS_STAGE_1', 5);  // First lockout after 5 attempts
define('MAX_FAILED_ATTEMPTS_STAGE_2', 3);  // Second lockout after 3 attempts
define('MAX_FAILED_ATTEMPTS_STAGE_3', 3);  // Permanent lockout after 3 attempts
define('LOCKOUT_DURATION_STAGE_1_MINUTES', 5);   // 5 minutes
define('LOCKOUT_DURATION_STAGE_2_MINUTES', 10);  // 10 minutes
define('LOCKOUT_STAGE_PERMANENT', 3);  // Permanent lockout stage

try {
    // Get user by email (only email/password users) - including lockout fields
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, avatar, password_hash, provider, status, 
               COALESCE(failed_attempts, 0) as failed_attempts,
               locked_until,
               COALESCE(lockout_stage, 0) as lockout_stage,
               created_at, updated_at
        FROM users
        WHERE LOWER(email) = LOWER(?) AND provider = 'email'
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists
    if (!$user) {
        logError('mobile_auth_login', 'Login attempt with invalid email', [
            'email' => $email,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid email or password.'
        ]);
        exit;
    }

    // Check if user account is deactivated (status -2 = self-deactivated)
    if ($user['status'] == -2) {
        logError('mobile_auth_login', 'Login attempt on deactivated account', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        // Return invalid credentials message (don't reveal account status)
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid email or password.'
        ]);
        exit;
    }

    // Check if user account is inactive (status 0 = admin deactivated)
    if ($user['status'] != 1) {
        logError('mobile_auth_login', 'Login attempt on inactive account', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Your account has been deactivated. Please contact support.'
        ]);
        exit;
    }

    // Check for permanent lockout (stage 3)
    if ($user['lockout_stage'] == LOCKOUT_STAGE_PERMANENT) {
        logError('mobile_auth_login', 'Login attempt on permanently locked account', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(423); // 423 Locked
        echo json_encode([
            'success' => false,
            'error' => 'Account locked',
            'message' => 'Your account has been permanently locked due to multiple failed login attempts. Please contact support to unlock your account.'
        ]);
        exit;
    }

    // Check if account is currently locked
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $locked_until = strtotime($user['locked_until']);
        $remaining_seconds = $locked_until - time();
        $remaining_minutes = ceil($remaining_seconds / 60);
        
        logError('mobile_auth_login', 'Login attempt on locked account', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'lockout_stage' => $user['lockout_stage'],
            'locked_until' => $user['locked_until'],
            'remaining_minutes' => $remaining_minutes,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        
        http_response_code(423); // 423 Locked
        echo json_encode([
            'success' => false,
            'error' => 'Account locked',
            'message' => "Your account is temporarily locked due to too many failed login attempts. Please try again in {$remaining_minutes} minute(s)."
        ]);
        exit;
    }

    // If lockout period has expired, reset failed attempts but keep lockout_stage
    if ($user['locked_until'] && strtotime($user['locked_until']) <= time()) {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET failed_attempts = 0, locked_until = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        $user['failed_attempts'] = 0;
        $user['locked_until'] = null;
    }

    // Check if password is correct
    if (!$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        // Increment failed attempts
        $new_failed_attempts = $user['failed_attempts'] + 1;
        $lockout_stage = (int)$user['lockout_stage'];
        $lockout_until = null;
        $new_lockout_stage = $lockout_stage;
        
        // Determine lockout based on stage
        if ($lockout_stage == 0) {
            // Stage 0: First lockout after 5 attempts
            if ($new_failed_attempts >= MAX_FAILED_ATTEMPTS_STAGE_1) {
                $lockout_until = date('Y-m-d H:i:s', time() + (LOCKOUT_DURATION_STAGE_1_MINUTES * 60));
                $new_lockout_stage = 1;
            }
        } elseif ($lockout_stage == 1) {
            // Stage 1: Second lockout after 3 attempts
            if ($new_failed_attempts >= MAX_FAILED_ATTEMPTS_STAGE_2) {
                $lockout_until = date('Y-m-d H:i:s', time() + (LOCKOUT_DURATION_STAGE_2_MINUTES * 60));
                $new_lockout_stage = 2;
            }
        } elseif ($lockout_stage == 2) {
            // Stage 2: Permanent lockout after 3 attempts
            if ($new_failed_attempts >= MAX_FAILED_ATTEMPTS_STAGE_3) {
                $lockout_until = null; // Permanent lockout (no expiry)
                $new_lockout_stage = LOCKOUT_STAGE_PERMANENT;
            }
        }
        
        // Update failed attempts and lockout status
        if ($lockout_until !== null || $new_lockout_stage == LOCKOUT_STAGE_PERMANENT) {
            // Lock the account
            $stmt = $pdo->prepare("
                UPDATE users 
                SET failed_attempts = ?, locked_until = ?, lockout_stage = ? 
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $lockout_until, $new_lockout_stage, $user['id']]);
            
            logError('mobile_auth_login', 'Account locked due to failed attempts', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'failed_attempts' => $new_failed_attempts,
                'lockout_stage' => $new_lockout_stage,
                'locked_until' => $lockout_until,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            if ($new_lockout_stage == LOCKOUT_STAGE_PERMANENT) {
                http_response_code(423);
                echo json_encode([
                    'success' => false,
                    'error' => 'Account locked',
                    'message' => 'Your account has been permanently locked due to multiple failed login attempts. Please contact support to unlock your account.'
                ]);
            } else {
                $lockout_minutes = ($new_lockout_stage == 1) ? LOCKOUT_DURATION_STAGE_1_MINUTES : LOCKOUT_DURATION_STAGE_2_MINUTES;
                http_response_code(423);
                echo json_encode([
                    'success' => false,
                    'error' => 'Account locked',
                    'message' => "Your account has been locked due to too many failed login attempts. Please try again in {$lockout_minutes} minute(s)."
                ]);
            }
        } else {
            // Just update failed attempts counter
            $stmt = $pdo->prepare("
                UPDATE users 
                SET failed_attempts = ? 
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $user['id']]);
            
            $remaining_attempts = 0;
            if ($lockout_stage == 0) {
                $remaining_attempts = MAX_FAILED_ATTEMPTS_STAGE_1 - $new_failed_attempts;
            } elseif ($lockout_stage == 1) {
                $remaining_attempts = MAX_FAILED_ATTEMPTS_STAGE_2 - $new_failed_attempts;
            } elseif ($lockout_stage == 2) {
                $remaining_attempts = MAX_FAILED_ATTEMPTS_STAGE_3 - $new_failed_attempts;
            }
            
            logError('mobile_auth_login', 'Login attempt with wrong password', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'failed_attempts' => $new_failed_attempts,
                'remaining_attempts' => $remaining_attempts,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => 'Invalid email or password.' . ($remaining_attempts > 0 ? " {$remaining_attempts} attempt(s) remaining." : '')
            ]);
        }
        exit;
    }

    // Reset failed attempts and lockout on successful login
    if ($user['failed_attempts'] > 0 || $user['locked_until']) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET failed_attempts = 0, locked_until = NULL
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
    }

    // Generate refresh token
    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60); // 7 days

    // Delete old refresh tokens for this user (optional: keep only one active token per user)
    $stmt = $pdo->prepare("DELETE FROM mobile_refresh_tokens WHERE user_id = ?");
    $stmt->execute([$user['id']]);

    // Store refresh token in database
    $stmt = $pdo->prepare("
        INSERT INTO mobile_refresh_tokens (user_id, token, expires_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$user['id'], $refresh_token, $refresh_token_expiry]);

    // Generate access token (JWT) - valid for 1 hour (3600 seconds)
    $token_payload = [
        'sub' => (int) $user['id'],
        'user_id' => (int) $user['id'],
        'email' => $user['email']
    ];

    $access_token = generateJWT($token_payload, 60); // 60 minutes = 3600 seconds

    // Return response matching Flutter expectations
    http_response_code(200);
    echo json_encode([
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'user' => [
            'id' => (string)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['cell'],
            'avatar' => $user['avatar'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_auth_login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during login. Please try again later.',
        'error_details' => 'Error during login: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_auth_login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during login. Please try again later.',
        'error_details' => 'Error during login: ' . $e->getMessage()
    ]);
}
?>

