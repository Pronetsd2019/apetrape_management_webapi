<?php
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

// Email configuration - can be overridden by environment variables
$emailConfig = [
    'smtp_host' => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
    'smtp_port' => (int)($_ENV['SMTP_PORT'] ?? 587),
    'smtp_secure' => $_ENV['SMTP_SECURE'] ?? 'tls', // 'tls' or 'ssl'
    'smtp_username' => $_ENV['SMTP_USERNAME'] ?? 'sales@apetrape.com',
    'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? '',
    'from_email' => $_ENV['FROM_EMAIL'] ?? 'sales@apetrape.com',
    'from_name' => $_ENV['FROM_NAME'] ?? 'APE Trape PTY Ltd',
    'reply_to_email' => $_ENV['REPLY_TO_EMAIL'] ?? 'sales@apetrape.com',
    'reply_to_name' => $_ENV['REPLY_TO_NAME'] ?? 'APE Trape PTY Ltd'
];

/**
 * Get email configuration
 * @return array
 */
function getEmailConfig() {
    global $emailConfig;
    return $emailConfig;
}
