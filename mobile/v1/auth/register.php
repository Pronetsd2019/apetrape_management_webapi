<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


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
 * Mobile User Registration Endpoint
 * POST /mobile/auth/register.php
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
$requiredFields = ['name', 'email', 'password'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => "Field '{$field}' is required.",
            'details' => [
                $field => ["The {$field} field is required."]
            ]
        ]);
        exit;
    }
}

$name = trim($input['name']);
$identifier = trim($input['email']); // Frontend uses one field for email OR cell
$password = $input['password'];
$phone = isset($input['phone']) ? trim($input['phone']) : null; // optional legacy support

// If the frontend provided only one field, decide whether it's an email or a cellphone number
$email = null;
if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
    $email = $identifier;
    // If phone wasn't sent separately, keep as null
} else {
    // Treat identifier as cellphone number
    $phone = $identifier;
}

// Additional validation
if ($email === null) {
    // Basic phone validation (allows + then 7-15 digits)
    $normalizedPhone = preg_replace('/\s+/', '', (string)$phone);
    if (!$normalizedPhone || !preg_match('/^\+?[0-9]{7,15}$/', $normalizedPhone)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Invalid email or phone number format.',
            'details' => [
                'email' => ['Provide a valid email address or a valid cellphone number.']
            ]
        ]);
        exit;
    }
    $phone = $normalizedPhone;
} else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Invalid email address format.',
        'details' => [
            'email' => ['The email must be a valid email address.']
        ]
    ]);
    exit;
}

if (strlen($name) < 2) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Name must be at least 2 characters long.',
        'details' => [
            'name' => ['The name must be at least 2 characters.']
        ]
    ]);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Password must be at least 6 characters long.',
        'details' => [
            'password' => ['The password must be at least 6 characters.']
        ]
    ]);
    exit;
}

try {
    // Check if user with same identifier already exists (email OR phone)
    if ($email !== null) {
        $stmt = $pdo->prepare("SELECT id, email, cell, status FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$email]);
    } else {
        $stmt = $pdo->prepare("SELECT id, email, cell, status FROM users WHERE cell = ?");
        $stmt->execute([$phone]);
    }
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    // Hash password
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    if ($existingUser) {
        // If user exists and is deactivated (status -2), reactivate and update password
        if ($existingUser['status'] == -2) {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET name = ?, 
                    email = ?,
                    cell = ?, 
                    password_hash = ?, 
                    status = 1,
                    failed_attempts = 0,
                    locked_until = NULL,
                    lockout_stage = 0,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $result = $stmt->execute([$name, $email, $phone, $password_hash, $existingUser['id']]);
            $userId = $existingUser['id'];
        } else {
            // User exists and is not deactivated - identifier already taken
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Validation failed',
                'message' => ($email !== null) ? 'Email already exists' : 'Phone number already exists',
                'details' => [
                    'email' => [($email !== null) ? 'The email has already been taken.' : 'The phone number has already been taken.']
                ]
            ]);
            exit;
        }
    } else {
        // Insert new user (surname is NULL for mobile users)
        $stmt = $pdo->prepare("
            INSERT INTO users (name, surname, email, cell, password_hash, provider, status)
            VALUES (?, NULL, ?, ?, ?, ?, 1)
        ");
        $provider = ($email !== null) ? 'email' : 'phone';
        $result = $stmt->execute([$name, $email, $phone, $password_hash, $provider]);
        $userId = $pdo->lastInsertId();
    }

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create user account.'
        ]);
        exit;
    }

    // Only get lastInsertId if we inserted a new user
    if (!isset($userId)) {
        $userId = $pdo->lastInsertId();
    }

    // Fetch the created user
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, avatar, created_at, updated_at
        FROM users
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate refresh token
    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60); // 7 days

    // Store refresh token in database
    $stmt = $pdo->prepare("
        INSERT INTO mobile_refresh_tokens (user_id, token, expires_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$userId, $refresh_token, $refresh_token_expiry]);

    // Generate access token (JWT) - valid for 1 hour (3600 seconds) as per Flutter spec
    $token_payload = [
        'sub' => (int) $user['id'],
        'user_id' => (int) $user['id'],
        'email' => $user['email'] ?? ''
    ];

    $access_token = generateJWT($token_payload, 60); // 60 minutes = 3600 seconds

    // OTP: create OTP row now, but do delivery after response flush
    // This prevents email/SMS provider slowness from blocking the API response.
    $otpSent = false; // delivery result (best-effort; may be attempted after response)
    $otpChannel = ($email !== null) ? 'email' : 'sms';
    $otpExpiresIn = 600; // seconds (10 minutes)
    $otpId = null;
    $otpCode = null;
    $otpDestination = ($otpChannel === 'email') ? (string)$email : (string)$phone;
    try {
        if (!empty($otpDestination)) {
            $otpRow = createRegistrationOtp($pdo, (int)$user['id'], $otpChannel, $otpDestination);
            $otpId = (int)($otpRow['otp_id'] ?? 0);
            $otpCode = (string)($otpRow['otp_code'] ?? '');
        }
    } catch (Throwable $e) {
        logException('mobile_auth_register_otp_create', $e, [
            'user_id' => (int)$user['id'],
            'channel' => $otpChannel
        ]);
    }

    // Return response matching Flutter expectations
    http_response_code(200);
    $responsePayload = [
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'otp_sent' => $otpSent,
        'otp_channel' => $otpChannel,
        'otp_expires_in' => $otpExpiresIn,
        'otp_id' => $otpId,
        'user' => [
            'id' => (string)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['cell'],
            'avatar' => $user['avatar'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ]
    ];
    echo json_encode($responsePayload);

    // Flush response early (if supported), then attempt OTP delivery in background.
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        @ob_flush();
        @flush();
    }

    // Best-effort OTP delivery (does not affect the already-sent response)
    try {
        if (!empty($otpDestination) && !empty($otpCode)) {
            if ($otpChannel === 'email') {
                logError('mobile_auth_register_otp_send', 'Sending OTP email', ['to' => $otpDestination, 'otp_id' => $otpId]);
                sendOtpEmail($otpDestination, (string)$user['name'], $otpCode, [
                    'user_id' => (int)$user['id'],
                    'otp_id' => $otpId,
                    'channel' => 'email'
                ]);
            } else {
                logError('mobile_auth_register_otp_send', 'Sending OTP sms', ['to' => $otpDestination, 'otp_id' => $otpId]);
                sendOtpSms($otpDestination, $otpCode, [
                    'user_id' => (int)$user['id'],
                    'otp_id' => $otpId,
                    'channel' => 'sms'
                ]);
            }
        }
    } catch (Throwable $e) {
        logException('mobile_auth_register_otp_send', $e, [
            'user_id' => (int)$user['id'],
            'channel' => $otpChannel,
            'otp_id' => $otpId
        ]);
    }

} catch (PDOException $e) {
    logException('mobile_auth_register', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during registration. Please try again later.',
        'error_details' => 'Error during registration: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_auth_register', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during registration. Please try again later.',
        'error_details' => 'Error during registration: ' . $e->getMessage()
    ]);
}
?>

