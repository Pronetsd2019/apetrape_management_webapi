<?php
/**
 * Script to add timezone configuration to all PHP files
 * This ensures consistent timezone handling across the application
 */

$projectRoot = dirname(dirname(__DIR__));

// Directories to process
$directories = [
    $projectRoot . '/control',
    $projectRoot . '/supplier'
];

// Files to skip
$skipFiles = [
    'add_timezone_config.php',
    'timezone_config.php',
    'fix_endpoint_names.php',
    'add_error_logging.php',
    'add_cors_headers.php'
];

$filesProcessed = 0;
$filesUpdated = 0;
$filesSkipped = 0;

function processDirectory($dir, &$filesProcessed, &$filesUpdated, &$filesSkipped, $skipFiles, $projectRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filePath = $file->getPathname();
            $fileName = $file->getFilename();
            
            // Skip certain files
            if (in_array($fileName, $skipFiles)) {
                echo "Skipping: $filePath\n";
                $filesSkipped++;
                continue;
            }
            
            $content = file_get_contents($filePath);
            
            // Check if timezone_config is already included
            if (strpos($content, 'timezone_config.php') !== false) {
                echo "Already has timezone config: $filePath\n";
                $filesSkipped++;
                continue;
            }
            
            // Check if file starts with <?php
            if (!preg_match('/^<\?php\s*\n/', $content)) {
                echo "Doesn't start with <?php: $filePath\n";
                $filesSkipped++;
                continue;
            }
            
            // Calculate relative path to timezone_config.php
            $fileDir = dirname($filePath);
            $utilPath = $projectRoot . '/control/util';
            $relativePath = getRelativePath($fileDir, $utilPath);
            $requirePath = $relativePath . '/timezone_config.php';
            
            // Check if file already includes connect.php (which now includes timezone_config)
            if (strpos($content, 'connect.php') !== false) {
                echo "Already includes connect.php (which has timezone): $filePath\n";
                $filesSkipped++;
                continue;
            }
            
            // Add timezone config after opening <?php tag
            // Look for the first line after <?php
            $pattern = '/^(<\?php\s*\n)/';
            $replacement = "$1\n// Set timezone for consistent datetime handling\nrequire_once __DIR__ . '/$requirePath';\n";
            
            $newContent = preg_replace($pattern, $replacement, $content, 1);
            
            if ($newContent !== $content) {
                file_put_contents($filePath, $newContent);
                echo "Updated: $filePath\n";
                $filesUpdated++;
            } else {
                echo "No changes needed: $filePath\n";
                $filesSkipped++;
            }
            
            $filesProcessed++;
        }
    }
}

function getRelativePath($from, $to) {
    $from = explode('/', $from);
    $to = explode('/', $to);
    
    $relPath = '';
    
    // Find common base
    $commonLength = 0;
    for ($i = 0; $i < min(count($from), count($to)); $i++) {
        if ($from[$i] !== $to[$i]) {
            break;
        }
        $commonLength++;
    }
    
    // Go up from $from
    $upLevels = count($from) - $commonLength;
    for ($i = 0; $i < $upLevels; $i++) {
        $relPath .= '../';
    }
    
    // Go down to $to
    for ($i = $commonLength; $i < count($to); $i++) {
        $relPath .= $to[$i] . '/';
    }
    
    return rtrim($relPath, '/');
}

echo "Starting timezone configuration update...\n\n";

foreach ($directories as $dir) {
    echo "Processing directory: $dir\n";
    processDirectory($dir, $filesProcessed, $filesUpdated, $filesSkipped, $skipFiles, $projectRoot);
    echo "\n";
}

echo "\n=== Summary ===\n";
echo "Files processed: $filesProcessed\n";
echo "Files updated: $filesUpdated\n";
echo "Files skipped: $filesSkipped\n";
echo "\nTimezone configuration update complete!\n";
?>
