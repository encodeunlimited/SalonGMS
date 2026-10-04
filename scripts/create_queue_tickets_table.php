<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$connection = $_ENV['DB_CONNECTION'] ?? 'sqlite';

try {
    if ($connection === 'sqlite') {
        $dbPath = __DIR__ . '/../data/database.sqlite';
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $schema = "
        CREATE TABLE IF NOT EXISTS queue_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            ticket_number VARCHAR(50) NOT NULL,
            sequence_num INTEGER NOT NULL,
            customer_id INTEGER NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NULL,
            barber_id INTEGER NOT NULL,
            service_id INTEGER NULL,
            service_name VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'waiting',
            estimated_wait_time INTEGER DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            called_at DATETIME NULL,
            served_at DATETIME NULL,
            completed_at DATETIME NULL,
            invoice_id INTEGER NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
            FOREIGN KEY (barber_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL
        );

        CREATE INDEX IF NOT EXISTS idx_queue_tickets_tenant_status ON queue_tickets(tenant_id, status);
        CREATE INDEX IF NOT EXISTS idx_queue_tickets_barber_status ON queue_tickets(tenant_id, barber_id, status);
        CREATE INDEX IF NOT EXISTS idx_queue_tickets_created_at ON queue_tickets(tenant_id, created_at);
        ";
        $pdo->exec($schema);
        echo "SQLite: queue_tickets table created successfully at $dbPath.\n";
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
        CREATE TABLE IF NOT EXISTS queue_tickets (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            tenant_id BIGINT NOT NULL,
            ticket_number VARCHAR(50) NOT NULL,
            sequence_num INT NOT NULL,
            customer_id BIGINT NULL,
            customer_name VARCHAR(255) NOT NULL,
            customer_phone VARCHAR(50) NULL,
            barber_id BIGINT NOT NULL,
            service_id BIGINT NULL,
            service_name VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'waiting',
            estimated_wait_time INT DEFAULT 0,
            notes TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            called_at DATETIME NULL,
            served_at DATETIME NULL,
            completed_at DATETIME NULL,
            invoice_id BIGINT NULL,
            INDEX idx_queue_tickets_tenant_status (tenant_id, status),
            INDEX idx_queue_tickets_barber_status (tenant_id, barber_id, status),
            INDEX idx_queue_tickets_created_at (tenant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $pdo->exec($schema);
        echo "MySQL: queue_tickets table created successfully.\n";
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}
