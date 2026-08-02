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

// SQL Statements
$tables = [];

// Tenants Table
$tables[] = "
    CREATE TABLE IF NOT EXISTS tenants (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        name VARCHAR(255) NOT NULL,
        subdomain VARCHAR(100) UNIQUE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
";

// Users Table (SaaS Users per tenant)
$tables[] = "
    CREATE TABLE IF NOT EXISTS users (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'stylist',
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// Appointments Table
$tables[] = "
    CREATE TABLE IF NOT EXISTS appointments (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        customer_name VARCHAR(255) NOT NULL,
        service VARCHAR(255) NOT NULL,
        stylist VARCHAR(255) NOT NULL,
        apt_date DATE NOT NULL,
        apt_time VARCHAR(50) NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// Execute
foreach ($tables as $sql) {
    $pdo->exec($sql);
}
echo "Schema initialized successfully.\n";

// Seed Dummy Tenant if none exists
$stmt = $pdo->query("SELECT count(*) FROM tenants");
if ($stmt->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO tenants (name, subdomain) VALUES ('Demo Salon', 'demo')");
    $tenantId = $pdo->lastInsertId();
    
    // Seed Demo User
    $stmt = $pdo->prepare("INSERT INTO users (tenant_id, role, name, email, password) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$tenantId, 'admin', 'Admin User', 'admin@demosalon.com', password_hash('password', PASSWORD_DEFAULT)]);
    
    // Seed Demo Appointments
    $stmt = $pdo->prepare("INSERT INTO appointments (tenant_id, customer_name, service, stylist, apt_date, apt_time) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$tenantId, 'Jane Doe', 'Haircut', 'Anna', date('Y-m-d'), '10:00 AM']);
    $stmt->execute([$tenantId, 'John Smith', 'Coloring', 'Marcus', date('Y-m-d'), '13:00 PM']);
    
    echo "Dummy Data Seeded!\n";
} else {
    echo "Data already exists. Skipping seed.\n";
}
