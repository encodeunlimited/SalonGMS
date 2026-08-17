<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use App\Services\WhatsAppService;

// Load Env
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Build Container
$containerBuilder = new ContainerBuilder();
$settings = require __DIR__ . '/../config/settings.php';
$containerBuilder->addDefinitions([
    'settings' => $settings
]);
$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

/** @var WhatsAppService $whatsAppService */
$whatsAppService = $container->get(WhatsAppService::class);

echo "Checking for package expirations...\n";

// 1. Mark packages as expired
$expiredStmt = $pdo->prepare("
    UPDATE customer_packages 
    SET status = 'expired'
    WHERE expires_at IS NOT NULL 
      AND expires_at < CURRENT_TIMESTAMP
      AND status = 'active'
");
$expiredStmt->execute();
$expiredCount = $expiredStmt->rowCount();
echo "Marked {$expiredCount} packages as expired.\n";

// 2. Find packages expiring in exactly 7 days
// We need to match the date ignoring the time.
$isSqlite = ($settings['db']['connection'] === 'sqlite');

if ($isSqlite) {
    $expiringQuery = "
        SELECT cp.id, cp.expires_at, p.name as package_name, c.name, c.phone, t.id as tenant_id
        FROM customer_packages cp
        JOIN packages p ON cp.package_id = p.id
        JOIN customers c ON cp.customer_id = c.id
        JOIN tenants t ON cp.tenant_id = t.id
        WHERE cp.status = 'active'
          AND cp.expires_at IS NOT NULL
          AND date(cp.expires_at) = date('now', '+7 days')
    ";
} else {
    $expiringQuery = "
        SELECT cp.id, cp.expires_at, p.name as package_name, c.name, c.phone, t.id as tenant_id
        FROM customer_packages cp
        JOIN packages p ON cp.package_id = p.id
        JOIN customers c ON cp.customer_id = c.id
        JOIN tenants t ON cp.tenant_id = t.id
        WHERE cp.status = 'active'
          AND cp.expires_at IS NOT NULL
          AND DATE(cp.expires_at) = CURDATE() + INTERVAL 7 DAY
    ";
}

$stmt = $pdo->query($expiringQuery);
$expiringPackages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($expiringPackages) . " packages expiring in 7 days.\n";

foreach ($expiringPackages as $pkg) {
    if (!empty($pkg['phone'])) {
        $message = "Hi {$pkg['name']},\n\nFriendly reminder: your package '{$pkg['package_name']}' will expire in 7 days on " . date('M d, Y', strtotime($pkg['expires_at'])) . ".\n\nPlease make sure to book your remaining services before it expires!\n\nBest Regards,\nSalonMS";
        
        echo "Sending WhatsApp notification to {$pkg['phone']} for package CP#{$pkg['id']}...\n";
        $whatsAppService->sendMessage($pkg['phone'], $message);
    }
}

echo "Expiration check completed successfully.\n";
