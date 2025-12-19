<?php

// Set timezone for consistent datetime handling
require_once __DIR__ . '/../util/timezone_config.php';
/**
 * Authentication Middleware
 * Use requireJwtAuth() at the top of any endpoint that requires authentication.
 * Returns the decoded JWT payload on success and sends a JSON error response on failure.
 */

require_once __DIR__ . '/../util/jwt.php';

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
 * Middleware: Require JWT Authentication
 *
 * @return array Decoded JWT payload
 */
function requireJwtAuth()
{
    $authHeader = getAuthorizationHeader();

    if (!$authHeader) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Authorization header missing.'
        ]);
        exit;
    }

    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid Authorization header format. Expected "Bearer <token>".'
        ]);
        exit;
    }

    $token = trim($matches[1]);

    if (empty($token)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
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
            'message' => 'Invalid or expired token.'
        ]);
        exit;
    }

    // Optionally make payload available globally
    $GLOBALS['auth_user'] = $payload;

    return $payload;
}
?>
