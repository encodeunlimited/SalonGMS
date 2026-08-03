<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$connection = $_ENV['DB_CONNECTION'] ?? 'sqlite';

try {
    if ($connection === 'sqlite') {
        $dbPath = __DIR__ . '/../' . ($_ENV['DB_DATABASE'] ?? 'data/salon.sqlite');
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $schema = "
        CREATE TABLE IF NOT EXISTS booking_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE(tenant_id, name)
        );
        CREATE TABLE IF NOT EXISTS payment_types (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE(tenant_id, name)
        );
        ";
        $pdo->exec($schema);
    } else {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $dbname = $_ENV['DB_DATABASE'] ?? 'salonms';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';
        $port = $_ENV['DB_PORT'] ?? '3306';
        
        $options = [
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ];
        if (!empty($_ENV['DB_SSL_CA'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
        }

        $pdo = new PDO("mysql:host=$host;dbname=$dbname;port=$port", $user, $pass, $options);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $schema = "
        CREATE TABLE IF NOT EXISTS booking_types (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE KEY tenant_name (tenant_id, name)
        );
        CREATE TABLE IF NOT EXISTS payment_types (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT NOT NULL,
            name VARCHAR(100) NOT NULL,
            UNIQUE KEY tenant_name (tenant_id, name)
        );
        ";
        $pdo->exec($schema);
    }

    echo "Tables created successfully.\n";

    // Seed defaults for existing tenants (e.g., tenant_id 1)
    $tenantsStmt = $pdo->query("SELECT DISTINCT tenant_id FROM tenant_settings");
    $tenants = $tenantsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // In case there are no tenants yet, add 1 as default
    if (empty($tenants)) {
        $tenants = [1];
    }

    $insertBooking = $pdo->prepare("INSERT IGNORE INTO booking_types (tenant_id, name) VALUES (?, ?)");
    if ($connection === 'sqlite') {
        $insertBooking = $pdo->prepare("INSERT OR IGNORE INTO booking_types (tenant_id, name) VALUES (?, ?)");
    }
    
    $insertPayment = $pdo->prepare("INSERT IGNORE INTO payment_types (tenant_id, name) VALUES (?, ?)");
    if ($connection === 'sqlite') {
        $insertPayment = $pdo->prepare("INSERT OR IGNORE INTO payment_types (tenant_id, name) VALUES (?, ?)");
    }

    foreach ($tenants as $tenantId) {
        $insertBooking->execute([$tenantId, 'In Salon']);
        $insertBooking->execute([$tenantId, 'Home Visit']);
        
        $insertPayment->execute([$tenantId, 'Credit Card']);
        $insertPayment->execute([$tenantId, 'Cash']);
        $insertPayment->execute([$tenantId, 'Bank Transfer']);
    }
    echo "Defaults seeded.\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
