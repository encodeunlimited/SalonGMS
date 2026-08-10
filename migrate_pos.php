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
    
    // Add columns to invoices table
    $alterQueries = [];
    
    if ($driver === 'sqlite') {
        $alterQueries = [
            "ALTER TABLE invoices ADD COLUMN tender_amount DECIMAL(10,2) DEFAULT NULL",
            "ALTER TABLE invoices ADD COLUMN change_amount DECIMAL(10,2) DEFAULT NULL",
            "ALTER TABLE invoices ADD COLUMN split_details TEXT DEFAULT NULL"
        ];
    } else {
        $alterQueries = [
            "ALTER TABLE invoices ADD COLUMN tender_amount DECIMAL(10,2) DEFAULT NULL AFTER payment_method",
            "ALTER TABLE invoices ADD COLUMN change_amount DECIMAL(10,2) DEFAULT NULL AFTER tender_amount",
            "ALTER TABLE invoices ADD COLUMN split_details TEXT DEFAULT NULL AFTER change_amount"
        ];
    }

    foreach ($alterQueries as $query) {
        try {
            $pdo->exec($query);
            echo "Successfully ran: $query\n";
        } catch (Exception $e) {
            echo "Notice (likely already exists): " . $e->getMessage() . "\n";
        }
    }
    
    echo "POS database migration complete.\n";

} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
