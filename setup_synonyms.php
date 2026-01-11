<?php
/**
 * Setup script for search synonyms and enhancements
 * Run this once to create the synonyms table and populate it
 */

require_once __DIR__ . '/control/util/connect.php';

try {
    echo "Setting up search synonyms table...\n";

    // Create synonyms table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_synonyms (
            id INT PRIMARY KEY AUTO_INCREMENT,
            term VARCHAR(100) NOT NULL,
            synonym VARCHAR(100) NOT NULL,
            weight DECIMAL(3,2) DEFAULT 1.0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_synonym (term, synonym),
            INDEX idx_term (term),
            INDEX idx_synonym (synonym)
        )
    ");

    echo "Table created successfully.\n";

    // Insert synonyms data
    $synonyms = [
        ['brake pad', 'brake pads', 1.0],
        ['brake pad', 'brake shoe', 0.8],
        ['tire', 'tyre', 1.0],
        ['tire', 'wheel', 0.6],
        ['oil filter', 'oil-filter', 1.0],
        ['air filter', 'cabin filter', 0.7],
        ['spark plug', 'spark plugs', 1.0],
        ['spark plug', 'ignition plug', 0.8],
        ['battery', 'car battery', 0.9],
        ['battery', 'automotive battery', 0.8],
        ['headlight', 'head lamp', 0.9],
        ['headlight', 'headlight bulb', 0.7],
        ['wiper', 'windshield wiper', 0.9],
        ['wiper', 'wiper blade', 0.8],
        ['radiator', 'radiator hose', 0.6],
        ['radiator', 'cooling system', 0.5],
        ['transmission', 'gearbox', 0.9],
        ['transmission', 'transmission fluid', 0.7],
        ['engine oil', 'motor oil', 1.0],
        ['engine oil', 'lubricant', 0.6],
        ['shock absorber', 'shock', 0.9],
        ['shock absorber', 'strut', 0.8],
        ['alternator', 'generator', 0.9],
        ['alternator', 'charging system', 0.6],
        ['fuel pump', 'fuel pump assembly', 0.8],
        ['fuel pump', 'fuel system', 0.5],
        ['catalytic converter', 'cat converter', 0.9],
        ['catalytic converter', 'exhaust system', 0.4],
        ['timing belt', 'timing chain', 0.7],
        ['timing belt', 'cam belt', 1.0]
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO search_synonyms (term, synonym, weight) VALUES (?, ?, ?)");

    foreach ($synonyms as $synonym) {
        $stmt->execute($synonym);
    }

    echo "Inserted " . count($synonyms) . " synonym pairs.\n";

    // Check current count
    $count = $pdo->query("SELECT COUNT(*) FROM search_synonyms")->fetchColumn();
    echo "Total synonyms in database: $count\n";

    echo "Setup completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
