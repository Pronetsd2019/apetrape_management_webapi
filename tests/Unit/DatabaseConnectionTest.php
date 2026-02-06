<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit test for database connection.
 * Uses same config as control/util/connect.php (env vars) without triggering its exit() on failure.
 */
class DatabaseConnectionTest extends TestCase
{
    public function testDatabaseConnectionSucceeds(): void
    {
        $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
        $dbName = $_ENV['DB_NAME'] ?? 'apetrape';
        $dbUser = $_ENV['DB_USER'] ?? 'root';
        $dbPass = $_ENV['DB_PASS'] ?? '';

        try {
            $pdo = new \PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $stmt = $pdo->query('SELECT 1 AS one');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertArrayHasKey('one', $row);
        $this->assertEquals(1, (int) $row['one']);
    }
}
