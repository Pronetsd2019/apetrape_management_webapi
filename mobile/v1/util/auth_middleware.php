<?php

// Set timezone for consistent datetime handling
require_once __DIR__ . '/../../../control/util/timezone_config.php';

/**
 * Mobile Authentication Middleware
 * Use requireMobileJwtAuth() at the top of any mobile endpoint that requires authentication.
 * Returns the decoded JWT payload on success and sends a JSON error response on failure.
 */

require_once __DIR__ . '/../../../control/util/jwt.php';

/**
 * Extract Authorization header value from request.
 *
 * @return string|null
 */
function getAuthorizationHeader()
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            return trim($headers['Authorization']);
        }
        // Some servers (e.g., nginx) use lowercase header keys
        if (isset($headers['authorization'])) {
            return trim($headers['authorization']);
        }
    }

    // Fallbacks for different server environments
    if (isset($_SERVER['Authorization'])) {
        return trim($_SERVER['Authorization']);
    }
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    return null;
}

/**
 * Middleware: Require Mobile JWT Authentication
 * Validates JWT token and ensures it contains user_id (for mobile users)
 *
 * @return array Decoded JWT payload with user_id
 */
function requireMobileJwtAuth()
{
    $authHeader = getAuthorizationHeader();

    if (!$authHeader) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Authentication required. Please provide a valid JWT token.'
        ]);
        exit;
    }

    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid Authorization header format. Expected "Bearer <token>".'
        ]);
        exit;
    }

    $token = trim($matches[1]);

    if (empty($token)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'JWT token is empty.'
        ]);
        exit;
    }

    $payload = validateJWT($token);

    if ($payload === false) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid or expired token. Please login again.'
        ]);
        exit;
    }

    // Validate that payload contains user_id (mobile user token)
    if (!isset($payload['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid token. User ID not found in token.'
        ]);
        exit;
    }

    // Make payload available globally
    $GLOBALS['auth_user'] = $payload;

    return $payload;
}
?>

