<?php
/**
 * PHPUnit bootstrap: load autoloader and .env for tests.
 */

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/vendor/autoload.php';

// Load .env so tests can use DB_HOST, SMTP, SMS vars (same as connect.php)
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
}

date_default_timezone_set('Africa/Mbabane');
