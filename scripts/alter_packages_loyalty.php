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

echo "Connected to Database.\n";

$isSqlite = ($settings['db']['connection'] === 'sqlite');
$autoIncrement = $isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY";

$tables = [];

// 1. Packages
$tables[] = "
    CREATE TABLE IF NOT EXISTS packages (
        id {$autoIncrement},
        tenant_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        active BOOLEAN DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// 2. Package Items (Services inside a package)
$tables[] = "
    CREATE TABLE IF NOT EXISTS package_items (
        id {$autoIncrement},
        package_id INT NOT NULL,
        service_id INT NOT NULL,
        FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    );
";

// 3. Loyalty Transactions
$tables[] = "
    CREATE TABLE IF NOT EXISTS loyalty_transactions (
        id {$autoIncrement},
        tenant_id INT NOT NULL,
        customer_id INT NOT NULL,
        invoice_id INT NULL,
        points_earned INT DEFAULT 0,
        points_spent INT DEFAULT 0,
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
    );
";

// Execute table creations
foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        echo "Table created successfully.\n";
    } catch (Exception $e) {
        echo "Error creating table: " . $e->getMessage() . "\n";
    }
}

// Add loyalty_points to customers if not exists
try {
    $pdo->exec("ALTER TABLE customers ADD COLUMN loyalty_points INT DEFAULT 0");
    echo "Added loyalty_points column to customers.\n";
} catch (Exception $e) {
    // Column might already exist
    echo "Notice: loyalty_points might already exist: " . $e->getMessage() . "\n";
}

echo "Database update completed.\n";
