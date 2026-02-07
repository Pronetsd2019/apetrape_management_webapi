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
require_once __DIR__ . '/../../../control/util/otp_store.php';
require_once __DIR__ . '/../../../control/util/email_sender.php';
require_once __DIR__ . '/../../../control/util/sms_sender.php';

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
        'message' => 'Email/phone and password are required.'
    ]);
    exit;
}

$identifier = trim($input['email']); // frontend uses one field for email OR phone
$password = $input['password'];

// Determine whether identifier is email or phone
$email = null;
$phone = null;
if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
    $email = $identifier;
} else {
    $phone = preg_replace('/\s+/', '', $identifier);
    if (!$phone || !preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Invalid email or phone number format.'
        ]);
        exit;
    }
}

// Lockout configuration
define('MAX_FAILED_ATTEMPTS_STAGE_1', 5);  // First lockout after 5 attempts
define('MAX_FAILED_ATTEMPTS_STAGE_2', 3);  // Second lockout after 3 attempts
define('MAX_FAILED_ATTEMPTS_STAGE_3', 3);  // Permanent lockout after 3 attempts
define('LOCKOUT_DURATION_STAGE_1_MINUTES', 5);   // 5 minutes
define('LOCKOUT_DURATION_STAGE_2_MINUTES', 10);  // 10 minutes
define('LOCKOUT_STAGE_PERMANENT', 3);  // Permanent lockout stage

try {
    // Get user by email OR phone (email/password + phone/password users) - including lockout fields
    if ($email !== null) {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, avatar, password_hash, provider, status, activated,
                   COALESCE(failed_attempts, 0) as failed_attempts,
                   locked_until,
                   COALESCE(lockout_stage, 0) as lockout_stage,
                   created_at, updated_at
            FROM users
            WHERE LOWER(email) = LOWER(?) AND provider IN ('email','phone')
        ");
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, name, surname, email, cell, avatar, password_hash, provider, status, activated,
                   COALESCE(failed_attempts, 0) as failed_attempts,
                   locked_until,
                   COALESCE(lockout_stage, 0) as lockout_stage,
                   created_at, updated_at
            FROM users
            WHERE cell = ? AND provider IN ('email','phone')
        ");
        $stmt->execute([$phone]);
    }
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists
    if (!$user) {
        logError('mobile_auth_login', 'Login attempt with invalid identifier', [
            'identifier' => $identifier,
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
        'email' => $user['email'] ?? ''
    ];

    $access_token = generateJWT($token_payload, 60); // 60 minutes = 3600 seconds

    // Handle optional FCM token save (best-effort, don't fail login if this fails)
    if (isset($input['fcm_token']) && !empty(trim($input['fcm_token']))) {
        try {
            $fcm_token = trim($input['fcm_token']);
            
            // Normalize device_id: treat empty string as NULL
            $device_id = isset($input['device_id']) && !empty(trim($input['device_id'])) 
                ? trim($input['device_id']) 
                : null;
            
            // Validate and normalize platform
            $platform = null;
            if (isset($input['platform']) && !empty(trim($input['platform']))) {
                $platformInput = strtolower(trim($input['platform']));
                $allowedPlatforms = ['android', 'ios', 'web'];
                
                if (in_array($platformInput, $allowedPlatforms)) {
                    $platform = $platformInput;
                }
            }
            
            // Upsert FCM token
            // Check if a row exists for this (user_id, device_id)
            if ($device_id === null) {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM user_fcm_tokens 
                    WHERE user_id = ? AND device_id IS NULL
                    LIMIT 1
                ");
                $checkStmt->execute([$user['id']]);
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT id FROM user_fcm_tokens 
                    WHERE user_id = ? AND device_id = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$user['id'], $device_id]);
            }
            
            $existingRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRow) {
                // Update existing row
                $updateStmt = $pdo->prepare("
                    UPDATE user_fcm_tokens 
                    SET fcm_token = ?, platform = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([$fcm_token, $platform, $existingRow['id']]);
            } else {
                // Check if token exists elsewhere and delete it (token reassignment)
                $tokenCheckStmt = $pdo->prepare("
                    SELECT id FROM user_fcm_tokens WHERE fcm_token = ? LIMIT 1
                ");
                $tokenCheckStmt->execute([$fcm_token]);
                $existingToken = $tokenCheckStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingToken) {
                    $deleteStmt = $pdo->prepare("DELETE FROM user_fcm_tokens WHERE id = ?");
                    $deleteStmt->execute([$existingToken['id']]);
                }
                
                // Insert new row
                $insertStmt = $pdo->prepare("
                    INSERT INTO user_fcm_tokens (user_id, fcm_token, device_id, platform, updated_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $insertStmt->execute([$user['id'], $fcm_token, $device_id, $platform]);
            }
            
            logError('mobile_auth_login', 'FCM token saved during login', [
                'user_id' => $user['id'],
                'device_id' => $device_id,
                'platform' => $platform
            ]);
        } catch (Exception $fcmError) {
            // Log error but don't fail login
            logException('mobile_auth_login_fcm', $fcmError);
        }
    }

    // If account not activated, send OTP to email or cell (whichever user has) so they can verify
    $otpSent = false;
    $otpChannel = null;
    $otpExpiresIn = 600;
    $otpId = null;
    if ((int)($user['activated'] ?? 0) === 0) {
        $hasEmail = !empty(trim((string)($user['email'] ?? ''))) && filter_var(trim($user['email']), FILTER_VALIDATE_EMAIL);
        $hasCell = !empty(trim((string)($user['cell'] ?? '')));
        $otpDestination = null;
        if ($hasEmail) {
            $otpChannel = 'email';
            $otpDestination = trim($user['email']);
        } elseif ($hasCell) {
            $otpChannel = 'sms';
            $otpDestination = trim($user['cell']);
        }
        if ($otpDestination && $otpChannel) {
            try {
                $otpRow = createRegistrationOtp($pdo, (int)$user['id'], $otpChannel, $otpDestination);
                $otpId = (int)($otpRow['otp_id'] ?? 0);
                $otpCode = (string)($otpRow['otp_code'] ?? '');
                if ($otpChannel === 'email') {
                    sendOtpEmail($otpDestination, (string)($user['name'] ?? 'User'), $otpCode, [
                        'user_id' => $user['id'],
                        'otp_id' => $otpId,
                        'source' => 'login'
                    ]);
                    $otpSent = true;
                } else {
                    sendOtpSms($otpDestination, $otpCode, [
                        'user_id' => $user['id'],
                        'otp_id' => $otpId,
                        'source' => 'login'
                    ]);
                    $otpSent = true;
                }
            } catch (Throwable $e) {
                logException('mobile_auth_login_otp', $e, [
                    'user_id' => $user['id'],
                    'channel' => $otpChannel
                ]);
            }
        }
    }

    // Return response matching Flutter expectations
    $responsePayload = [
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
            'activated' => (bool)((int)($user['activated'] ?? 0)),
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ]
    ];
    if ($otpChannel !== null) {
        $responsePayload['otp_sent'] = $otpSent;
        $responsePayload['otp_channel'] = $otpChannel;
        $responsePayload['otp_expires_in'] = $otpExpiresIn;
        $responsePayload['otp_id'] = $otpId;
    }
    http_response_code(200);
    echo json_encode($responsePayload);

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

