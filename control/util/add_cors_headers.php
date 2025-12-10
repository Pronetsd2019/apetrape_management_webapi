<?php
/**
 * Script to automatically add CORS headers to all endpoints
 * 
 * Usage: php control/util/add_cors_headers.php
 * 
 * This script will add CORS headers to all endpoint PHP files
 */

// Get the project root directory
$scriptDir = __DIR__;
$baseDir = dirname($scriptDir); // This is the 'control' directory
$projectRoot = dirname($baseDir); // This is the project root

$endpointDirs = [
    $projectRoot . '/control',
    $projectRoot . '/supplier'
];

$filesProcessed = 0;
$filesUpdated = 0;
$errors = [];

// Directories to skip
$skipDirs = ['util', 'middleware', 'assets', 'logs', 'uploads', 'vendor', 'database'];

// CORS code to add
$corsCode = <<<'PHP'
// CORS headers for subdomain support
$allowedOriginPattern = '/^https:\/\/([a-z0-9-]+)\.apetrape\.com$/i';

if (isset($_SERVER['HTTP_ORIGIN']) && preg_match($allowedOriginPattern, $_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

PHP;

foreach ($endpointDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        
        $filePath = $file->getPathname();
        $relativePath = str_replace($projectRoot . '/', '', $filePath);
        
        // Skip utility files, middleware, and this script
        $shouldSkip = false;
        foreach ($skipDirs as $skipDir) {
            if (strpos($relativePath, $skipDir . '/') !== false) {
                $shouldSkip = true;
                break;
            }
        }
        
        if ($shouldSkip || basename($filePath) === 'add_cors_headers.php') {
            continue;
        }
        
        $filesProcessed++;
        $content = file_get_contents($filePath);
        $originalContent = $content;
        
        // Skip if it's not an endpoint file (doesn't start with <?php)
        if (strpos($content, '<?php') !== 0 && strpos($content, '<?') !== 0) {
            continue;
        }
        
        // Check if CORS headers are already present
        if (strpos($content, 'Access-Control-Allow-Origin') !== false) {
            continue; // Already has CORS headers
        }
        
        // Find the position after the opening PHP tag
        $phpTagPos = strpos($content, '<?php');
        if ($phpTagPos === false) {
            $phpTagPos = strpos($content, '<?');
            if ($phpTagPos === false) {
                continue; // No PHP tag found
            }
        }
        
        // Find the end of the opening PHP tag line
        $nextLinePos = strpos($content, "\n", $phpTagPos);
        if ($nextLinePos === false) {
            $nextLinePos = strlen($content);
        }
        
        // Check if there's already a newline after the PHP tag
        $insertPos = $nextLinePos + 1;
        
        // If there's already content, add a blank line before CORS code
        $nextChar = substr($content, $insertPos, 1);
        if ($nextChar !== "\n" && $nextChar !== "\r") {
            $corsCodeToInsert = "\n" . $corsCode;
        } else {
            $corsCodeToInsert = $corsCode;
        }
        
        // Insert CORS code after the PHP opening tag
        $content = substr_replace($content, $corsCodeToInsert, $insertPos, 0);
        
        // Write back if changed
        if ($content !== $originalContent) {
            if (file_put_contents($filePath, $content) !== false) {
                $filesUpdated++;
                echo "✓ Updated: $relativePath\n";
            } else {
                $errors[] = "Failed to write: $relativePath";
                echo "✗ Failed: $relativePath\n";
            }
        }
    }
}

echo "\n";
echo "========================================\n";
echo "Summary:\n";
echo "Files processed: $filesProcessed\n";
echo "Files updated: $filesUpdated\n";
echo "Errors: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

echo "\nDone!\n";
