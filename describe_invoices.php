<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$containerBuilder = new \DI\ContainerBuilder();
$dependencies = require __DIR__ . '/config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

try {
    $pdo = $container->get(PDO::class);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'sqlite') {
        $stmt = $pdo->query("PRAGMA table_info(invoices)");
    } else {
        $stmt = $pdo->query("DESCRIBE invoices");
    }
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
