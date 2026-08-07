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

    // ---------------------------------------------------------
    // Create deployment wrapper files in the build directory
    // ---------------------------------------------------------

    // 1. Create index.php wrapper
    $indexPhpContent = "<?php\nrequire_once __DIR__ . '/app.phar';\n";
    file_put_contents($buildDir . '/index.php', $indexPhpContent);

    // 2. Create .htaccess for URL rewriting
    $htaccessContent = "RewriteEngine On\n"
                     . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                     . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                     . "RewriteRule ^(.*)$ index.php [QSA,L]\n";
    file_put_contents($buildDir . '/.htaccess', $htaccessContent);

    // 3. Copy .env file if it exists (so it can be configured on the server)
    if (file_exists($baseDir . '/.env')) {
        copy($baseDir . '/.env', $buildDir . '/.env');
    }

    echo "PHAR and deployment files built successfully in the build/ directory.\n";
    
    
} catch (Exception $e) {
    echo "Error building PHAR: " . $e->getMessage() . "\n";
    exit(1);
}
