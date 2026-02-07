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
 * Mobile Support Feedback Endpoint
 * POST /mobile/v1/support/feedback.php
 * Handles feedback submissions from both authenticated and unauthenticated users
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

// Validate rating if provided (optional field)
$rating = null;
if (isset($input['rating'])) {
    if (!is_numeric($input['rating'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Rating must be a number.'
        ]);
        exit;
    }
    
    $rating = (int)$input['rating'];
    
    // Validate rating range (typically 1-5)
    if ($rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Rating must be between 1 and 5.'
        ]);
        exit;
    }
}

$message = isset($input['message']) ? trim($input['message']) : null;
$type = isset($input['type']) ? trim($input['type']) : null;

// Try to get user info from JWT token (optional authentication)
$user_id = null;
$user_name = null;
$user_email = null;
$user_cell = null;
$is_authenticated = false;
$auth_debug = []; // Initialize debug array for authentication troubleshooting

// Extract Authorization header (same logic as auth_middleware.php)
$auth_header = null;

if (function_exists('getallheaders')) {
    $headers = getallheaders();
    $auth_debug['getallheaders_exists'] = true;
    $auth_debug['headers_keys'] = array_keys($headers ?? []);
    if (isset($headers['Authorization'])) {
        $auth_header = trim($headers['Authorization']);
        $auth_debug['header_source'] = 'getallheaders_Authorization';
    } elseif (isset($headers['authorization'])) {
        $auth_header = trim($headers['authorization']);
        $auth_debug['header_source'] = 'getallheaders_authorization';
    }
} else {
    $auth_debug['getallheaders_exists'] = false;
}

// Fallbacks for different server environments
if (!$auth_header && isset($_SERVER['Authorization'])) {
    $auth_header = trim($_SERVER['Authorization']);
    $auth_debug['header_source'] = 'SERVER_Authorization';
}
if (!$auth_header && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
    $auth_debug['header_source'] = 'SERVER_HTTP_AUTHORIZATION';
}

$auth_debug['auth_header_found'] = !empty($auth_header);
$auth_debug['auth_header_length'] = $auth_header ? strlen($auth_header) : 0;

// Check for Authorization header and validate token
if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    $token = trim($matches[1]);
    $auth_debug['token_extracted'] = true;
    $auth_debug['token_length'] = strlen($token);
    $auth_debug['token_preview'] = substr($token, 0, 20) . '...';
    
    if (!empty($token)) {
        $decoded = validateJWT($token);
        $auth_debug['jwt_validation_result'] = (!isset($decoded['error']) && is_array($decoded)) ? 'success' : 'failed';
        if (isset($decoded['error'])) {
            $auth_debug['jwt_error'] = $decoded['error'];
        }
        
        // Check for user_id or sub (JWT standard claim)
        if (!isset($decoded['error']) && is_array($decoded)) {
            $auth_debug['decoded_payload'] = array_keys($decoded);
            $user_id_from_token = null;
            
            if (isset($decoded['user_id'])) {
                $user_id_from_token = (int)$decoded['user_id'];
                $auth_debug['user_id_source'] = 'user_id';
                $auth_debug['user_id_value'] = $user_id_from_token;
            } elseif (isset($decoded['sub'])) {
                $user_id_from_token = (int)$decoded['sub'];
                $auth_debug['user_id_source'] = 'sub';
                $auth_debug['user_id_value'] = $user_id_from_token;
            } else {
                $auth_debug['user_id_source'] = 'none';
                $auth_debug['decoded_keys'] = array_keys($decoded);
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
                        $auth_debug['user_found_in_db'] = true;
                        $auth_debug['authentication_status'] = 'success';
                    } else {
                        // User not found in database, continue as unauthenticated
                        $is_authenticated = false;
                        $user_id = null;
                        $auth_debug['user_found_in_db'] = false;
                        $auth_debug['authentication_status'] = 'failed_user_not_found';
                        $auth_debug['user_id_searched'] = $user_id_from_token;
                    }
                } catch (PDOException $e) {
                    // If user lookup fails, continue as unauthenticated
                    $is_authenticated = false;
                    $user_id = null;
                    $auth_debug['authentication_status'] = 'failed_db_error';
                    $auth_debug['db_error'] = $e->getMessage();
                }
            } else {
                $auth_debug['authentication_status'] = 'failed_no_user_id';
                $auth_debug['decoded_payload_keys'] = array_keys($decoded ?? []);
            }
        } else {
            $auth_debug['authentication_status'] = 'failed_invalid_token';
            $auth_debug['decoded_type'] = gettype($decoded);
            $auth_debug['decoded_value'] = $decoded;
        }
    } else {
        $auth_debug['authentication_status'] = 'failed_empty_token';
    }
} else {
    $auth_debug['authentication_status'] = 'failed_no_header_or_invalid_format';
    if ($auth_header) {
        $auth_debug['header_format_valid'] = false;
        $auth_debug['header_preview'] = substr($auth_header, 0, 50);
    }
}

// If not authenticated, require name and cell
if (!$is_authenticated) {
    if (!isset($input['name']) || empty(trim($input['name']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Name is required for unauthenticated users.',
            'auth_debug' => $auth_debug
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    if (!isset($input['cell']) || empty(trim($input['cell']))) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation failed',
            'message' => 'Cell phone number is required for unauthenticated users.',
            'auth_debug' => $auth_debug
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    $user_name = trim($input['name']);
    $user_cell = trim($input['cell']);
}

// Validate message length if provided
if ($message && strlen($message) > 5000) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Message must be 5000 characters or less.'
    ]);
    exit;
}

// Validate type length if provided
if ($type && strlen($type) > 100) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Validation failed',
        'message' => 'Type must be 100 characters or less.'
    ]);
    exit;
}

try {
    // Insert the feedback
    $stmt = $pdo->prepare("
        INSERT INTO support_feedback (user_id, name, cell, email, rating, type, message)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $user_id,
        $user_name,
        $user_cell,
        $user_email,
        $rating,
        $type,
        $message
    ]);

    if (!$result) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error',
            'message' => 'Failed to submit your feedback. Please try again later.',
            'error_details' => 'Failed to insert feedback.'
        ]);
        exit;
    }

    $feedback_id = $pdo->lastInsertId();

    // Fetch the created feedback
    $stmt = $pdo->prepare("
        SELECT id, user_id, name, cell, email, rating, type, message, created_at, updated_at
        FROM support_feedback
        WHERE id = ?
    ");
    $stmt->execute([$feedback_id]);
    $feedback = $stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Thank you for your feedback!',
        'data' => [
            'id' => (int)$feedback['id'],
            'rating' => $feedback['rating'] ? (int)$feedback['rating'] : null,
            'type' => $feedback['type'],
            'created_at' => $feedback['created_at']
        ]
    ]);

} catch (PDOException $e) {
    logException('mobile_support_feedback', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while submitting your feedback. Please try again later.',
        'error_details' => 'Error submitting feedback: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    logException('mobile_support_feedback', $e);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'An error occurred while submitting your feedback. Please try again later.',
        'error_details' => 'Error submitting feedback: ' . $e->getMessage()
    ]);
}
?>

