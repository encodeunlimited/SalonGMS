<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Services\CacheService;

echo "--- Clearing SalonGMS Cache ---\n";

$result = CacheService::clearAll();

foreach ($result['messages'] as $msg) {
    echo "  [+] $msg\n";
}

echo "Cache cleared successfully!\n";
exit(0);
