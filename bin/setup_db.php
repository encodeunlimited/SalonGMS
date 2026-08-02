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

// 1. Tenants Table
$tables[] = "
    CREATE TABLE IF NOT EXISTS tenants (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        name VARCHAR(255) NOT NULL,
        subdomain VARCHAR(100) UNIQUE NOT NULL,
        settings TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
";

// 2. Users (Employees/Stylists/Admins)
$tables[] = "
    CREATE TABLE IF NOT EXISTS users (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        role VARCHAR(50) DEFAULT 'stylist',
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        commission_rate DECIMAL(5,2) DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// 3. Customers
$tables[] = "
    CREATE TABLE IF NOT EXISTS customers (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NULL,
        email VARCHAR(255) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// 4. Services
$tables[] = "
    CREATE TABLE IF NOT EXISTS services (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        duration_minutes INT NOT NULL DEFAULT 30,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    );
";

// 5. Appointments
$tables[] = "
    CREATE TABLE IF NOT EXISTS appointments (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        customer_id INT NULL, 
        service_id INT NULL, 
        user_id INT NULL,
        customer_name VARCHAR(255) NOT NULL, -- Fallback if not linked
        service VARCHAR(255) NOT NULL, -- Fallback
        stylist VARCHAR(255) NOT NULL, -- Fallback
        apt_date DATE NOT NULL,
        apt_time VARCHAR(50) NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    );
";

// 6. Invoices (POS)
$tables[] = "
    CREATE TABLE IF NOT EXISTS invoices (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        appointment_id INT NULL,
        customer_id INT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(50) DEFAULT 'unpaid',
        payment_method VARCHAR(50) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
    );
";

// 7. Invoice Items
$tables[] = "
    CREATE TABLE IF NOT EXISTS invoice_items (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        invoice_id INT NOT NULL,
        service_id INT NULL,
        description VARCHAR(255) NOT NULL,
        quantity INT NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
    );
";

// 8. Commissions
$tables[] = "
    CREATE TABLE IF NOT EXISTS commissions (
        id " . ($isSqlite ? "INTEGER PRIMARY KEY AUTOINCREMENT" : "INT AUTO_INCREMENT PRIMARY KEY") . ",
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        invoice_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
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
    $stmt->execute([$tenantId, 'Jane Doe', 'Haircut', 'Anna', date('Y-m-d'), '10:00']);
    $stmt->execute([$tenantId, 'John Smith', 'Coloring', 'Marcus', date('Y-m-d'), '13:00']);
    
    echo "Dummy Data Seeded!\n";
} else {
    echo "Data already exists. Skipping seed.\n";
}
