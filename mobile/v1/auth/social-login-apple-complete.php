<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * POST /mobile/v1/auth/social-login-apple-complete.php
 * Completes Apple sign-up when name was deferred (APPLE_NAME_REQUIRED flow).
 */

require_once __DIR__ . '/../../../control/util/connect.php';
require_once __DIR__ . '/../../../control/util/jwt.php';
require_once __DIR__ . '/../../../control/util/error_logger.php';
require_once __DIR__ . '/apple_id_token_verify.inc.php';

header('Content-Type: application/json');

$APPLE_ALLOWED_AUDIENCES = ['com.apetrape'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$apple_registration_token = isset($input['apple_registration_token']) ? trim((string)$input['apple_registration_token']) : '';
$name = isset($input['name']) ? trim((string)$input['name']) : '';
$id_token = isset($input['id_token']) ? trim((string)$input['id_token']) : '';

if ($apple_registration_token === '' || $id_token === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request',
        'message' => 'apple_registration_token and id_token are required.',
    ]);
    exit;
}

if ($name === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Name is required.',
    ]);
    exit;
}

try {
    $regClaims = validateJWT($apple_registration_token);
    if (isset($regClaims['error'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid token',
            'message' => 'Registration session expired or invalid. Please sign in with Apple again.',
        ]);
        exit;
    }

    if (($regClaims['purpose'] ?? '') !== 'apple_register') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid token',
            'message' => 'Invalid registration token purpose.',
        ]);
        exit;
    }

    $appleSubFromReg = trim((string)($regClaims['apple_sub'] ?? ''));
    if ($appleSubFromReg === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid token',
            'message' => 'Invalid registration token payload.',
        ]);
        exit;
    }

    $appleClaims = verifyAppleIdToken($id_token, $APPLE_ALLOWED_AUDIENCES);
    $sub = trim((string)$appleClaims['sub']);
    if ($sub === '' || $sub !== $appleSubFromReg) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid token',
            'message' => 'Apple identity does not match registration token.',
        ]);
        exit;
    }

    $emailFromReg = isset($regClaims['email']) && is_string($regClaims['email']) ? trim($regClaims['email']) : null;
    if ($emailFromReg === '') {
        $emailFromReg = null;
    }
    $emailFromToken = null;
    if (isset($appleClaims['email']) && is_string($appleClaims['email']) && trim($appleClaims['email']) !== '') {
        $emailFromToken = trim($appleClaims['email']);
    }
    $email = $emailFromReg ?: $emailFromToken;

    $provider = 'apple';
    $provider_user_id = $sub;

    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, cell, avatar, provider, provider_user_id, status, activated, created_at, updated_at
        FROM users
        WHERE provider = ? AND provider_user_id = ?
    ");
    $stmt->execute([$provider, $provider_user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $isNewUser = false;

    if (!$user) {
        $isNewUser = true;
        $stmt = $pdo->prepare("
            INSERT INTO users (name, surname, email, cell, provider, provider_user_id, avatar, status, activated)
            VALUES (?, NULL, ?, ?, ?, ?, NULL, 1, 1)
        ");
        $stmt->execute([$name, $email, null, $provider, $provider_user_id]);

        $userId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, avatar, provider, provider_user_id, status, activated, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Idempotent: user already created (e.g. double submit) — issue tokens without requiring name again.
        if ((int)($user['status'] ?? 0) != 1) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Account inactive',
                'message' => 'Your account has been deactivated. Please contact support.',
            ]);
            exit;
        }
    }

    if ((int)($user['status'] ?? 0) != 1) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Account inactive',
            'message' => 'Your account has been deactivated. Please contact support.',
        ]);
        exit;
    }

    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60);

    $stmt = $pdo->prepare("DELETE FROM mobile_refresh_tokens WHERE user_id = ?");
    $stmt->execute([$user['id']]);

    $stmt = $pdo->prepare("
        INSERT INTO mobile_refresh_tokens (user_id, token, expires_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$user['id'], $refresh_token, $refresh_token_expiry]);

    $token_payload = [
        'sub' => (int) $user['id'],
        'user_id' => (int) $user['id'],
        'email' => $user['email'],
    ];
    $access_token = generateJWT($token_payload, 60);

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
            'updated_at' => $user['updated_at'],
        ],
        'is_new_user' => $isNewUser,
    ]);
} catch (PDOException $e) {
    logException('mobile_auth_social_login_apple_complete', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred during registration. Please try again later.',
        'error_details' => 'Error during Apple registration complete: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    logException('mobile_auth_social_login_apple_complete', $e);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid token',
        'message' => $e->getMessage(),
    ]);
}
