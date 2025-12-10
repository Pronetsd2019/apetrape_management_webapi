<?php
/**
 * Script to automatically add error logging to all endpoints
 * 
 * Usage: php control/util/add_error_logging.php
 * 
 * This script will:
 * 1. Find all endpoint PHP files
 * 2. Add error_logger.php require statement
 * 3. Update catch blocks to include logging
 */

// Get the project root directory (two levels up from this file)
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
$skipDirs = ['util', 'middleware', 'assets', 'logs', 'uploads', 'vendor'];

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
        
        if ($shouldSkip || basename($filePath) === 'add_error_logging.php') {
            continue;
        }
        
        $filesProcessed++;
        $content = file_get_contents($filePath);
        $originalContent = $content;
        $updated = false;
        
        // Skip if it's not an endpoint file (doesn't have require statements)
        if (strpos($content, 'require_once') === false && strpos($content, 'require ') === false) {
            continue;
        }
        
        // Step 1: Add error_logger.php require if not present
        if (strpos($content, 'error_logger.php') === false) {
            // Determine the correct path to error_logger.php
            $fileDir = dirname($filePath);
            $isSupplier = strpos($relativePath, 'supplier/') === 0;
            
            if ($isSupplier) {
                // Supplier endpoints: ../../control/util/error_logger.php
                $errorLoggerPath = '../../control/util/error_logger.php';
            } else {
                // Control endpoints: ../util/error_logger.php
                $errorLoggerPath = '../util/error_logger.php';
            }
            
            // Find the best place to insert (after connect.php or check_permission.php)
            $insertPatterns = [
                "/(require_once\s+__DIR__\s*\.\s*['\"][^'\"]*connect\.php['\"];)/",
                "/(require_once\s+__DIR__\s*\.\s*['\"][^'\"]*check_permission\.php['\"];)/",
                "/(require_once\s+__DIR__\s*\.\s*['\"][^'\"]*jwt\.php['\"];)/",
                "/(require_once\s+__DIR__\s*\.\s*['\"][^'\"]*auth_middleware\.php['\"];)/"
            ];
            
            $inserted = false;
            foreach ($insertPatterns as $pattern) {
                if (preg_match($pattern, $content, $matches)) {
                    $content = str_replace(
                        $matches[1],
                        $matches[1] . "\nrequire_once __DIR__ . '/" . $errorLoggerPath . "';",
                        $content
                    );
                    $inserted = true;
                    $updated = true;
                    break;
                }
            }
            
            // If no pattern matched, insert after first require_once
            if (!$inserted && preg_match("/(require_once\s+__DIR__\s*\.\s*['\"][^'\"]*;)/", $content, $matches)) {
                $content = str_replace(
                    $matches[1],
                    $matches[1] . "\nrequire_once __DIR__ . '/" . $errorLoggerPath . "';",
                    $content
                );
                $updated = true;
            }
        }
        
        // Step 2: Extract endpoint name for logging (clean path)
        $endpointName = str_replace([$projectRoot . '/', '.php'], '', $filePath);
        $endpointName = str_replace('/', '_', $endpointName);
        
        // Step 3: Update PDOException catch blocks
        if (preg_match('/catch\s*\(\s*PDOException\s+\$e\s*\)\s*\{/', $content)) {
            // Check if logException is already called
            if (strpos($content, 'logException') === false || 
                !preg_match('/catch\s*\(\s*PDOException\s+\$e\s*\)\s*\{[^}]*logException/s', $content)) {
                
                $content = preg_replace(
                    '/(catch\s*\(\s*PDOException\s+\$e\s*\)\s*\{)/',
                    "$1\n    logException('$endpointName', \$e);",
                    $content
                );
                $updated = true;
            }
        }
        
        // Step 4: Update Exception catch blocks (but not PDOException)
        if (preg_match('/catch\s*\(\s*Exception\s+\$e\s*\)\s*\{/', $content)) {
            // Check if logException is already called
            if (strpos($content, 'logException') === false || 
                !preg_match('/catch\s*\(\s*Exception\s+\$e\s*\)\s*\{[^}]*logException/s', $content)) {
                
                $content = preg_replace(
                    '/(catch\s*\(\s*Exception\s+\$e\s*\)\s*\{)/',
                    "$1\n    logException('$endpointName', \$e);",
                    $content
                );
                $updated = true;
            }
        }
        
        // Step 5: Update RuntimeException catch blocks
        if (preg_match('/catch\s*\(\s*RuntimeException\s+\$e\s*\)\s*\{/', $content)) {
            // Check if logException is already called
            if (strpos($content, 'logException') === false || 
                !preg_match('/catch\s*\(\s*RuntimeException\s+\$e\s*\)\s*\{[^}]*logException/s', $content)) {
                
                $content = preg_replace(
                    '/(catch\s*\(\s*RuntimeException\s+\$e\s*\)\s*\{)/',
                    "$1\n    logException('$endpointName', \$e);",
                    $content
                );
                $updated = true;
            }
        }
        
        
        // Write back if changed
        if ($updated && $content !== $originalContent) {
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
