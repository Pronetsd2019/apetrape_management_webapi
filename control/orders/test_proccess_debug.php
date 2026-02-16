<?php
/**
 * Run from project root: php control/orders/test_proccess_debug.php
 * Or from control/orders: php test_proccess_debug.php
 * This shows which require or step fails so you can see the real error.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$base = dirname(__DIR__); // control/
echo "Base (control): $base\n";

echo "1. Requiring error_logger... ";
require_once $base . '/util/error_logger.php';
echo "OK\n";

echo "2. Requiring connect... ";
require_once $base . '/util/connect.php';
echo "OK\n";

echo "3. Requiring auth_middleware... ";
require_once $base . '/middleware/auth_middleware.php';
echo "OK\n";

echo "4. Requiring check_permission... ";
require_once $base . '/util/check_permission.php';
echo "OK\n";

echo "5. Checking sourcing_calls table exists... ";
$stmt = $pdo->query("SHOW TABLES LIKE 'sourcing_calls'");
if ($stmt->rowCount() === 0) {
    echo "MISSING. Run: control/database/sourcing_calls.sql\n";
    exit(1);
}
echo "OK\n";

echo "All checks passed.\n";
echo "If the web request still returns 500, the debug log is written to: " . sys_get_temp_dir() . "/proccess_debug.txt\n";
echo "Check that file after reproducing the 500 (it will contain 'request started' and either 'FATAL' or 'THROWABLE' with the error).\n";
