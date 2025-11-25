<?php
/**
 * Database Connection Utility
 * Shared database connection file for all API endpoints
 */

// Database configuration
$db_host = '198.251.88.49';
$db_name = 'apetrape_apetrape'; // Update with your database name
$db_user = 'apetrape_banele'; // Update with your database user
$db_pass = '#@13021proSD'; // Update with your database password

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

