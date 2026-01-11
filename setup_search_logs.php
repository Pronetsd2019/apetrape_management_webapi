<?php
/**
 * Setup script for search logs table
 * Run this once to create the search logs table
 */

require_once __DIR__ . '/control/util/connect.php';

try {
    echo "Setting up search logs table...\n";

    // Create search logs table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_logs (
            id INT PRIMARY KEY AUTO_INCREMENT,
            search_query TEXT,
            manufacturer_id INT,
            category_id INT,
            model_id INT,
            sort_option VARCHAR(20),
            page INT DEFAULT 1,
            page_size INT DEFAULT 10,
            results_count INT DEFAULT 0,
            user_agent TEXT,
            ip_address VARCHAR(45),
            search_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_timestamp (search_timestamp),
            INDEX idx_query (search_query(50)),
            INDEX idx_manufacturer (manufacturer_id),
            INDEX idx_category (category_id),
            INDEX idx_model (model_id),
            INDEX idx_results (results_count)
        )
    ");

    echo "Table created successfully.\n";

    // Create a view for no-results searches (most important for business intelligence)
    $pdo->exec("
        CREATE OR REPLACE VIEW search_no_results AS
        SELECT
            id,
            search_query,
            manufacturer_id,
            category_id,
            model_id,
            sort_option,
            results_count,
            user_agent,
            ip_address,
            search_timestamp
        FROM search_logs
        WHERE results_count = 0
        ORDER BY search_timestamp DESC
    ");

    echo "View created successfully.\n";

    // Create summary view for analytics
    $pdo->exec("
        CREATE OR REPLACE VIEW search_analytics AS
        SELECT
            DATE(search_timestamp) as date,
            COUNT(*) as total_searches,
            COUNT(CASE WHEN results_count = 0 THEN 1 END) as no_results_searches,
            COUNT(DISTINCT search_query) as unique_queries,
            AVG(results_count) as avg_results_per_search
        FROM search_logs
        WHERE search_timestamp >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
        GROUP BY DATE(search_timestamp)
        ORDER BY date DESC
    ");

    echo "Analytics view created successfully.\n";

    echo "Setup completed successfully!\n";
    echo "\nYou can now query:\n";
    echo "- SELECT * FROM search_logs ORDER BY search_timestamp DESC LIMIT 10;\n";
    echo "- SELECT * FROM search_no_results LIMIT 10;\n";
    echo "- SELECT * FROM search_analytics;\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
