<?php

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Dotenv\Dotenv;

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

echo "Running Database Migrations for GitHub Action...\n";

// Add loyalty_points to customers
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN loyalty_points INT DEFAULT 0");
    echo "Added loyalty_points to customers.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column loyalty_points already exists.\n";
    } else {
        echo "Error altering customers: " . $e->getMessage() . "\n";
    }
}

// Add POS columns to invoices
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN tender_amount DECIMAL(10,2) DEFAULT NULL");
    echo "Added tender_amount to invoices.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column tender_amount already exists.\n";
    } else {
        echo "Error altering invoices: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN change_amount DECIMAL(10,2) DEFAULT NULL");
    echo "Added change_amount to invoices.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column change_amount already exists.\n";
    } else {
        echo "Error altering invoices: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN split_details TEXT DEFAULT NULL");
    echo "Added split_details to invoices.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column split_details already exists.\n";
    } else {
        echo "Error altering invoices: " . $e->getMessage() . "\n";
    }
}

echo "Migrations complete.\n";
