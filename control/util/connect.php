<?php
/**
 * Database Connection Utility
 * Shared database connection file for all API endpoints
 */

// Set timezone for consistent datetime handling
require_once __DIR__ . '/timezone_config.php';

// Load .env: dev = project root; prod = parent of project root (same folder as apetrape_management_webapi)
$projectRoot = dirname(dirname(__DIR__));
$loadEnv = function ($envPath, $overwrite) {
    if (!is_file($envPath)) {
        return;
    }
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            if ($overwrite || !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
};
$loadEnv($projectRoot . '/.env', false);           // dev: inside project
$loadEnv(dirname($projectRoot) . '/.env', true);   // prod: same folder as project root

// Database configuration - loaded from environment variables
$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? 'apetrape';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';

try {
    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Return error response
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}
?>

