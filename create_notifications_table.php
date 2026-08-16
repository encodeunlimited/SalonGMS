<?php
require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$containerBuilder = new \DI\ContainerBuilder();
$dependencies = require __DIR__ . '/config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

try {
    $pdo = $container->get(PDO::class);
    
    $sql = "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_id` int(11) NOT NULL,
        `type` varchar(50) DEFAULT 'system',
        `title` varchar(255) NOT NULL,
        `message` text NOT NULL,
        `is_read` tinyint(1) DEFAULT 0,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $pdo->exec($sql);
    echo "Notifications table created successfully!\n";
    
} catch (\Exception $e) {
    echo $e->getMessage();
}
