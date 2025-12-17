<?php

// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, Accept");
    header("Vary: Origin"); 
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
/**
 * Supplier Login Endpoint
 * POST /supplier/auth/login.php
 */

 ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../control/util/connect.php';
require_once __DIR__ . '/../../control/util/error_logger.php';
require_once __DIR__ . '/../../control/util/jwt.php';
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
    // First try to get supplier from suppliers table
    $stmt = $pdo->prepare("
        SELECT id, name, email, cellphone, telephone, status, password_hash,
               locked_until, failed_attempts, created_at, updated_at
        FROM suppliers
        WHERE email = ?
    ");
    $stmt->execute([$input['email']]);
    $supplier = $stmt->fetch();

    $isFromApplication = false;

    // If not found in suppliers, check supplier_application
    if (!$supplier) {
        $stmt = $pdo->prepare("
            SELECT id, name, email, cell, address, reg, password_hash, status, created_at, updated_at
            FROM supplier_application
            WHERE email = ?
        ");
        $stmt->execute([$input['email']]);
        $application = $stmt->fetch();

        if ($application) {
            // Treat application as supplier for login logic
            $supplier = [
                'id' => $application['id'],
                'name' => $application['name'],
                'email' => $application['email'],
                'cellphone' => $application['cell'],
                'telephone' => null,
                'status' => $application['status'],
                'password_hash' => $application['password_hash'],
                'locked_until' => null, // Applications don't have lockout
                'failed_attempts' => 0, // Applications don't track failed attempts
                'created_at' => $application['created_at'],
                'updated_at' => $application['updated_at']
            ];
            $isFromApplication = true;
        }
    }

    // Get IP address and user agent for logging
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // Check if supplier exists
    if (!$supplier) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    // Check if account is locked (only for approved suppliers)
    if (!$isFromApplication && $supplier['locked_until'] && strtotime($supplier['locked_until']) > time()) {
        $locked_until = strtotime($supplier['locked_until']);
        $remaining_minutes = ceil(($locked_until - time()) / 60);

        http_response_code(423); // 423 Locked
        echo json_encode([
            'success' => false,
            'message' => "Account is locked due to too many failed login attempts. Please try again in {$remaining_minutes} minute(s) or contact administrator."
        ]);
        exit;
    }

    // If lockout period has expired, reset failed attempts and locked_until (only for approved suppliers)
    if (!$isFromApplication && $supplier['locked_until'] && strtotime($supplier['locked_until']) <= time()) {
        $stmt = $pdo->prepare("
            UPDATE suppliers
            SET failed_attempts = 0, locked_until = NULL
            WHERE id = ?
        ");
        $stmt->execute([$supplier['id']]);
        $supplier['failed_attempts'] = 0;
        $supplier['locked_until'] = null;
    }

    // Check if password is correct
    $password_correct = password_verify($input['password'], $supplier['password_hash']);

    if (!$password_correct) {
        // If this is from application table and password is wrong, just return invalid credentials
        if ($isFromApplication) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            exit;
        }

        // For approved suppliers, handle failed attempts and lockout
        $new_failed_attempts = ($supplier['failed_attempts'] ?? 0) + 1;

        // Lock account if max attempts reached
        if ($new_failed_attempts >= MAX_FAILED_ATTEMPTS) {
            $lockout_until = date('Y-m-d H:i:s', time() + (LOCKOUT_DURATION_MINUTES * 60));
            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET failed_attempts = ?, locked_until = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $lockout_until, $supplier['id']]);

            http_response_code(423); // 423 Locked
            echo json_encode([
                'success' => false,
                'message' => "Account has been locked due to too many failed login attempts. Please try again in " . LOCKOUT_DURATION_MINUTES . " minute(s) or contact administrator."
            ]);
            exit;
        } else {
            // Update failed attempts counter
            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET failed_attempts = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_failed_attempts, $supplier['id']]);

            $remaining_attempts = MAX_FAILED_ATTEMPTS - $new_failed_attempts;

            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => "Invalid email or password. {$remaining_attempts} attempt(s) remaining."
            ]);
            exit;
        }
    }

    // If this is from application table and password is correct, check application status
    if ($isFromApplication) {
        $appStatus = (int)$supplier['status'];
        if ($appStatus == 3) {
            // Application was rejected
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Your registration application has been rejected. Please contact administrator for more information.',
                'application_status' => 'rejected'
            ]);
            exit;
        } else {
            // Application is pending (status 1 or other)
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Your registration application is still pending approval. Please contact administrator for status updates.',
                'application_status' => 'pending'
            ]);
            exit;
        }
    }

    // Check if supplier is active
    // Convert to int to handle string "1" from database
    $status = (int)$supplier['status'];
    if ($status !== 1) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is not active.']);
        exit;
    }

    // Reset failed attempts on successful login
    if ($supplier['failed_attempts'] > 0 || $supplier['locked_until']) {
        $stmt = $pdo->prepare("
            UPDATE suppliers
            SET failed_attempts = 0, locked_until = NULL
            WHERE id = ?
        ");
        $stmt->execute([$supplier['id']]);
    }

    // Generate refresh token
    $refresh_token = generateRefreshToken();
    $refresh_token_expiry = time() + (7 * 24 * 60 * 60); // 7 days

    // Store refresh token in database
    $stmt = $pdo->prepare("
        INSERT INTO supplier_refresh_tokens (supplier_id, token, expires_at)
        VALUES (?, ?, FROM_UNIXTIME(?))
    ");
    $stmt->execute([$supplier['id'], $refresh_token, $refresh_token_expiry]);

    // Generate access token (JWT) - valid for 15 minutes
    $token_payload = [
        'sub' => (int) $supplier['id'],
        'supplier_id' => (int) $supplier['id'],
        'email' => $supplier['email'],
        'type' => 'supplier'
    ];

    $access_token = generateJWT($token_payload, 15);

    // Set refresh token as HTTP-only cookie
    // Use .apetrape.com domain to share cookies across all subdomains
    // This allows cookies set by webapi.apetrape.com to be accessible by supplier.apetrape.com
    $cookieDomain = '.apetrape.com';
    
    setcookie(
        'supplier_refresh_token',
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

    // Return response with supplier info and access token
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful.',
        'data' => [
            'supplier' => [
                'id' => (int)$supplier['id'],
                'name' => $supplier['name'],
                'email' => $supplier['email'],
                'cellphone' => $supplier['cellphone'],
                'telephone' => $supplier['telephone']
            ],
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => 900 // 15 minutes in seconds
        ]
    ]);

} catch (PDOException $e) {
    logException('supplier_auth_login', $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during login: ' . $e->getMessage()
    ]);
}
?>
