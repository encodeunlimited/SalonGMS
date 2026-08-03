<?php
require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$containerBuilder = new ContainerBuilder();
$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

$db = $container->get(PDO::class);

$columns = [
    'commission_rate' => 'DECIMAL(5,2) DEFAULT 0.00',
    'profile_image' => 'VARCHAR(255)',
    'specialist_areas' => 'TEXT'
];

foreach ($columns as $column => $def) {
    try {
        $db->exec("ALTER TABLE users ADD COLUMN $column $def;");
        echo "Added $column\n";
    } catch (Exception $e) {
        echo "Skipped $column: " . $e->getMessage() . "\n";
    }
}
