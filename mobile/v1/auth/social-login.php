<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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
 * Mobile Social Login/Registration Endpoint
 * POST /mobile/auth/social-login.php
 * Handles both login and registration for social providers (Facebook, Google, Instagram)
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
if (!isset($input['provider']) || !isset($input['provider_user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request',
        'message' => 'Provider and provider_user_id are required.',
        'input' => $input
    ]);
    exit;
}

$provider = strtolower(trim($input['provider']));
$provider_user_id = trim($input['provider_user_id']);
$access_token = isset($input['access_token']) ? trim($input['access_token']) : null;
$id_token = isset($input['id_token']) ? trim($input['id_token']) : null;
$email = isset($input['email']) ? trim($input['email']) : null;
$name = isset($input['name']) ? trim($input['name']) : null;
$avatar = isset($input['avatar']) ? trim($input['avatar']) : null;

// Validate provider
$allowedProviders = ['facebook', 'google', 'instagram'];
if (!in_array($provider, $allowedProviders)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid provider',
        'message' => 'Provider must be one of: ' . implode(', ', $allowedProviders)
    ]);
    exit;
}

// Validate access_token or id_token (at least one required)
if (!$access_token && !$id_token) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid token',
        'message' => 'Either access_token or id_token is required.'
    ]);
    exit;
}

// For Google, id_token is preferred
if ($provider === 'google' && !$id_token) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid token',
        'message' => 'id_token is required for Google authentication.'
    ]);
    exit;
}

try {
    // TODO: Verify token with provider API
    // For now, we'll trust the token structure and proceed
    // Full verification should be implemented:
    // - Google: Verify id_token with Google's tokeninfo endpoint
    // - Facebook: Verify access_token with Facebook's debug endpoint
    // - Instagram: Verify access_token with Instagram's API
    
    // Look up user by provider_user_id and provider
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, avatar, provider, provider_user_id, status, activated, created_at, updated_at
        FROM users
        WHERE provider = ? AND provider_user_id = ?
    ");
    $stmt->execute([$provider, $provider_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $isNewUser = false;

    if (!$user) {
        // User not found by provider_user_id and provider
        // Check if user exists by email (if email is provided)
        if ($email) {
            $stmt = $pdo->prepare("
                SELECT id, name, surname, email, cell, avatar, provider, provider_user_id, status, activated, created_at, updated_at
                FROM users
                WHERE LOWER(email) = LOWER(?)
            ");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                // User exists with this email
                if ($existingUser['provider'] && $existingUser['provider_user_id']) {
                    // User is already a social login user (maybe different provider)
                    // Log them in and optionally update provider info if needed
                    $user = $existingUser;
                    
                    // Update provider info if it's different (user switching providers)
                    if ($existingUser['provider'] !== $provider || $existingUser['provider_user_id'] !== $provider_user_id) {
                        $stmt = $pdo->prepare("
                            UPDATE users 
                            SET provider = ?, provider_user_id = ?, updated_at = NOW() 
                            WHERE id = ?
                        ");
                        $stmt->execute([$provider, $provider_user_id, $existingUser['id']]);
                        $user['provider'] = $provider;
                        $user['provider_user_id'] = $provider_user_id;
                    }
                } else {
                    // User exists with email/password; allow social login (link provider to existing account)
                    $user = $existingUser;
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET provider = ?, provider_user_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$provider, $provider_user_id, $existingUser['id']]);
                    $user['provider'] = $provider;
                    $user['provider_user_id'] = $provider_user_id;
                    if ($avatar) $user['avatar'] = $avatar;
                    if ($name) $user['name'] = $name;
                }
            }
        }

        // If user still not found, create new account
        if (!$user) {
            $isNewUser = true;

            // Validate required fields for new user
            if (!$name) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => 'Validation failed',
                    'message' => 'Name is required for new user registration.'
                ]);
                exit;
            }

            // Insert new user (surname is NULL for social login users; activated = true for social sign-up)
            $stmt = $pdo->prepare("
                INSERT INTO users (name, surname, email, cell, provider, provider_user_id, avatar, status, activated)
                VALUES (?, NULL, ?, ?, ?, ?, ?, 1, 1)
            ");
            $stmt->execute([$name, $email, null, $provider, $provider_user_id, $avatar]);

            $userId = $pdo->lastInsertId();

            // Fetch the created user
            $stmt = $pdo->prepare("
                SELECT id, name, email, cell, avatar, provider, provider_user_id, status, activated, created_at, updated_at
                FROM users
                WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else {
        // User exists, update last login info (optional: update avatar/name if changed)
        if ($avatar && $avatar !== $user['avatar']) {
            $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$avatar, $user['id']]);
            $user['avatar'] = $avatar;
        }
        
        if ($name && $name !== $user['name']) {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $user['id']]);
            $user['name'] = $name;
        }

    }

    // Check if user account is active (applies to all: found by provider, linked email, or new)
    if ((int)($user['status'] ?? 0) != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Account inactive',
            'message' => 'Your account has been deactivated. Please contact support.'
        ]);
        exit;
    }

    // Generate refresh token
    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60); // 7 days

    // Store refresh token in database (delete old tokens for this user if any)
    $stmt = $pdo->prepare("DELETE FROM mobile_refresh_tokens WHERE user_id = ?");
    $stmt->execute([$user['id']]);

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
            'activated' => (bool)($user['activated'] ?? false),
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ],
        'is_new_user' => $isNewUser
    ]);

} catch (PDOException $e) {
    logException('mobile_auth_social_login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during social login. Please try again later.',
        'error_details' => 'Error during social login: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_auth_social_login', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid token',
        'message' => 'The provided access token is invalid or expired.',
        'error_details' => 'Error during social login: ' . $e->getMessage()
    ]);
}
?>

