<?php

// Set timezone for consistent datetime handling
require_once __DIR__ . '/timezone_config.php';
/**
 * Email Configuration Utility
 * Configure SMTP settings here or via environment variables
 */

// Load .env file if it exists
$envFile = dirname(dirname(__DIR__)) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Set environment variable if not already set
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
}

// Email configuration sourced strictly from environment variables
$emailConfig = [
    'smtp_host' => $_ENV['SMTP_HOST'] ?? null,
    'smtp_port' => isset($_ENV['SMTP_PORT']) ? (int)$_ENV['SMTP_PORT'] : null,
    'smtp_secure' => $_ENV['SMTP_SECURE'] ?? null, // expected: tls or ssl
    'smtp_username' => $_ENV['SMTP_USERNAME'] ?? null,
    'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? null,
    'from_email' => $_ENV['FROM_EMAIL'] ?? null,
    'from_name' => $_ENV['FROM_NAME'] ?? null,
    'reply_to_email' => $_ENV['REPLY_TO_EMAIL'] ?? null,
    'reply_to_name' => $_ENV['REPLY_TO_NAME'] ?? null,
    // Base URL for email images (e.g. https://apetrape.com) - no trailing slash
    'assets_base_url' => rtrim($_ENV['ASSETS_BASE_URL'] ?? $_ENV['APP_URL'] ?? '', '/')
];

/**
 * Get email configuration
 * @return array
 */
function getEmailConfig() {
    global $emailConfig;
    return $emailConfig;
}
