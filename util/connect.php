<?php
/**
 * Database Connection Utility
 * Shared database connection file for all API endpoints
 */

// Database configuration
$db_host = 'localhost';
$db_name = 'your_database_name'; // Update with your database name
$db_user = 'root'; // Update with your database user
$db_pass = ''; // Update with your database password

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

