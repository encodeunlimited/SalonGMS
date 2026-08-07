<?php

$pharName = 'app.phar';
$buildDir = __DIR__ . '/../build';

// Create build directory if it doesn't exist
if (!is_dir($buildDir)) {
    mkdir($buildDir);
}

$pharFile = $buildDir . '/' . $pharName;

// Remove existing phar if it exists
if (file_exists($pharFile)) {
    unlink($pharFile);
}

try {
    $phar = new Phar($pharFile, 0, $pharName);
    $phar->startBuffering();

    $baseDir = dirname(__DIR__);
    
    // Directories to include in the PHAR
    $dirs = ['src', 'vendor', 'config', 'templates', 'public'];

    foreach ($dirs as $dir) {
        if (!is_dir($baseDir . '/' . $dir)) continue;
        
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            // Convert backslashes to forward slashes for the PHAR path
            $path = str_replace('\\', '/', $path);
            $phar->addFile($file->getPathname(), $path);
        }
    }

    // Add composer files if needed
    if (file_exists($baseDir . '/composer.json')) {
        $phar->addFile($baseDir . '/composer.json', 'composer.json');
    }
    if (file_exists($baseDir . '/.env')) {
        $phar->addFile($baseDir . '/.env', '.env');
    }

    // Set the entry point to public/index.php
    $phar->setStub($phar->createDefaultStub('public/index.php'));
    $phar->stopBuffering();

    echo "PHAR built successfully at: $pharFile\n";
    
} catch (Exception $e) {
    echo "Error building PHAR: " . $e->getMessage() . "\n";
    exit(1);
}
