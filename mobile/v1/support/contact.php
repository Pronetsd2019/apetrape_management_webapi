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
 * Mobile Support Contact Endpoint
 * POST /mobile/v1/support/contact.php
 * Handles contact/support messages from both authenticated and unauthenticated users
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
if (!isset($input['category']) || empty(trim($input['category']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Category is required.'
    ]);
    exit;
}

if (!isset($input['message']) || empty(trim($input['message']))) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Message is required.'
    ]);
    exit;
}

$category = trim($input['category']);
$message = trim($input['message']);

// Try to get user info from JWT token (optional authentication)
$user_id = null;
$user_name = null;
$user_email = null;
$user_cell = null;
$is_authenticated = false;

// Extract Authorization header (same logic as auth_middleware.php)
$auth_header = null;
if (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $auth_header = trim($headers['Authorization']);
    } elseif (isset($headers['authorization'])) {
        $auth_header = trim($headers['authorization']);
    }
}

// Fallbacks for different server environments
if (!$auth_header && isset($_SERVER['Authorization'])) {
    $auth_header = trim($_SERVER['Authorization']);
}
if (!$auth_header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
}

// Check for Authorization header and validate token
if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $token = trim($matches[1]);
    
    if (!empty($token)) {
        $decoded = validateJWT($token);
        
        // Check for user_id or sub (JWT standard claim)
        if ($decoded !== false && is_array($decoded)) {
            $user_id_from_token = null;
            
            if (isset($decoded['user_id'])) {
                $user_id_from_token = (int)$decoded['user_id'];
            } elseif (isset($decoded['sub'])) {
                $user_id_from_token = (int)$decoded['sub'];
            }
            
            if ($user_id_from_token) {
                $is_authenticated = true;
                $user_id = $user_id_from_token;
                
                // Fetch user details from database
                try {
                    $stmt = $pdo->prepare("SELECT id, name, surname, email, cell FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        $user_name = trim($user['name'] . ' ' . ($user['surname'] ?? ''));
                        $user_email = $user['email'];
                        $user_cell = $user['cell'];
                    } else {
                        // User not found in database, continue as unauthenticated
                        $is_authenticated = false;
                        $user_id = null;
                    }
                } catch (PDOException $e) {
                    // If user lookup fails, continue as unauthenticated
                    logException('mobile_support_contact_auth', $e);
                    $is_authenticated = false;
                    $user_id = null;
                }
            }
        }
    }
}

// If not authenticated, require name and cell
if (!$is_authenticated) {
    if (!isset($input['name']) || empty(trim($input['name']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Name is required for unauthenticated users.'
        ]);
        exit;
    }
    
    if (!isset($input['cell']) || empty(trim($input['cell']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Cell phone number is required for unauthenticated users.'
        ]);
        exit;
    }
    
    $user_name = trim($input['name']);
    $user_cell = trim($input['cell']);
}

// Validate category length
if (strlen($category) > 100) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Category must be 100 characters or less.'
    ]);
    exit;
}

// Validate message length
if (strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Message must be 5000 characters or less.'
    ]);
    exit;
}

try {
    // Insert the support contact message
    $stmt = $pdo->prepare("
        INSERT INTO support_contacts (user_id, name, cell, email, category, message, status)
        VALUES (?, ?, ?, ?, ?, ?, 'pending')
    ");
    
    $result = $stmt->execute([
        $user_id,
        $user_name,
        $user_cell,
        $user_email,
        $category,
        $message
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to submit your message. Please try again later.',
            'error_details' => 'Failed to insert support contact message.'
        ]);
        exit;
    }

    $contact_id = $pdo->lastInsertId();

    // Fetch the created contact message
    $stmt = $pdo->prepare("
        SELECT id, user_id, name, cell, email, category, message, status, created_at, updated_at
        FROM support_contacts
        WHERE id = ?
    ");
    $stmt->execute([$contact_id]);
    $contact = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Your message has been submitted successfully. We will get back to you soon.',
        'data' => [
            'id' => (int)$contact['id'],
            'category' => $contact['category'],
            'status' => $contact['status'],
            'created_at' => $contact['created_at']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_support_contact', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while submitting your message. Please try again later.',
        'error_details' => 'Error submitting support contact: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_support_contact', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while submitting your message. Please try again later.',
        'error_details' => 'Error submitting support contact: ' . $e->getMessage()
    ]);
}
?>

