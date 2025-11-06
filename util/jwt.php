<?php
/**
 * JWT Utility Functions
 * Uses Firebase JWT library for generating and validating tokens
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// JWT Secret Key - Change this to a strong random string in production
define('JWT_SECRET', 'your-secret-key-change-this-in-production-min-32-chars');
define('JWT_ALGORITHM', 'HS256');

/**
 * Generate JWT Token
 * @param array $payload Data to encode in the token
 * @param int $expiration_minutes Token expiration time in minutes
 * @return string JWT token
 */
function generateJWT($payload, $expiration_minutes = 15) {
    $issued_at = time();
    $expiration = $issued_at + ($expiration_minutes * 60);

    $payload['iat'] = $issued_at;
    $payload['exp'] = $expiration;

    return JWT::encode($payload, JWT_SECRET, JWT_ALGORITHM);
}

/**
 * Validate and Decode JWT Token
 * @param string $token JWT token
 * @return array|false Decoded payload or false if invalid
 */
function validateJWT($token) {
    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, JWT_ALGORITHM));
        return (array) $decoded;
    } catch (\Exception $e) {
        // Token is invalid, expired, or malformed
        return false;
    }
}

/**
 * Generate Refresh Token
 * @return string Random refresh token
 */
function generateRefreshToken() {
    return bin2hex(random_bytes(32));
}
?>

