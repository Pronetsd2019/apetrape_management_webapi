<?php
/**
 * Script to fix incorrect endpoint names in logException calls
 * 
 * Usage: php control/util/fix_endpoint_names.php
 */

$scriptDir = __DIR__;
$baseDir = dirname($scriptDir); // This is the 'control' directory
$projectRoot = dirname($baseDir); // This is the project root

$files = [];

// Find all PHP files with logException
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/control', RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filePath = $file->getPathname();
        $content = file_get_contents($filePath);
        
        if (strpos($content, 'logException') !== false) {
            $relativePath = str_replace($projectRoot . '/', '', $filePath);
            $endpointName = str_replace(['.php', '/'], ['', '_'], $relativePath);
            
            // Fix incorrect endpoint names (those with full paths)
            if (preg_match("/logException\s*\(\s*['\"][^'\"]*Applications[^'\"]*['\"]/", $content)) {
                $content = preg_replace(
                    "/logException\s*\(\s*['\"][^'\"]*['\"]/",
                    "logException('$endpointName'",
                    $content
                );
                file_put_contents($filePath, $content);
                echo "Fixed: $relativePath\n";
            }
        }
    }
}

// Do the same for supplier directory
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot . '/supplier', RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filePath = $file->getPathname();
        $content = file_get_contents($filePath);
        
        if (strpos($content, 'logException') !== false) {
            $relativePath = str_replace($projectRoot . '/', '', $filePath);
            $endpointName = str_replace(['.php', '/'], ['', '_'], $relativePath);
            
            // Fix incorrect endpoint names (those with full paths)
            if (preg_match("/logException\s*\(\s*['\"][^'\"]*Applications[^'\"]*['\"]/", $content)) {
                $content = preg_replace(
                    "/logException\s*\(\s*['\"][^'\"]*['\"]/",
                    "logException('$endpointName'",
                    $content
                );
                file_put_contents($filePath, $content);
                echo "Fixed: $relativePath\n";
            }
        }
    }
}

echo "Done!\n";
