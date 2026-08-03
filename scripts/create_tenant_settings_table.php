<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

// Resolve connection settings
$connection = $_ENV['DB_CONNECTION'] ?? 'sqlite';

try {
    if ($connection === 'sqlite') {
        $dbPath = __DIR__ . '/../' . ($_ENV['DB_DATABASE'] ?? 'data/salon.sqlite');
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $schema = "
        CREATE TABLE IF NOT EXISTS tenant_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            UNIQUE(tenant_id, setting_key)
        );
        ";
        $pdo->exec($schema);
    } else {
        // MySQL
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
        CREATE TABLE IF NOT EXISTS tenant_settings (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT,
            UNIQUE KEY tenant_key (tenant_id, setting_key)
        );
        ";
        $pdo->exec($schema);
    }

    echo "Tenant settings table created successfully.\n";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
