<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (!empty($_ENV['DB_SSL_CA'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $options);

    echo "Running migration for Customer Packages...\n";

    // 1. Create customer_packages table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_packages` (
            `id` int NOT NULL AUTO_INCREMENT,
            `tenant_id` int NOT NULL,
            `customer_id` int NOT NULL,
            `package_id` int NOT NULL,
            `status` varchar(50) DEFAULT 'active',
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `fk_cp_tenant` (`tenant_id`),
            KEY `fk_cp_customer` (`customer_id`),
            KEY `fk_cp_package` (`package_id`),
            CONSTRAINT `fk_cp_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cp_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cp_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
    ");
    echo "Created table customer_packages.\n";

    // 2. Create customer_package_services table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_package_services` (
            `id` int NOT NULL AUTO_INCREMENT,
            `tenant_id` int NOT NULL,
            `customer_package_id` int NOT NULL,
            `service_id` int NOT NULL,
            `total_quantity` int NOT NULL DEFAULT 1,
            `used_quantity` int NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `fk_cps_tenant` (`tenant_id`),
            KEY `fk_cps_cp` (`customer_package_id`),
            KEY `fk_cps_service` (`service_id`),
            CONSTRAINT `fk_cps_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cps_cp` FOREIGN KEY (`customer_package_id`) REFERENCES `customer_packages` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_cps_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
    ");
    echo "Created table customer_package_services.\n";

    // 3. Alter appointments table to add customer_package_service_id
    try {
        $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `customer_package_service_id` int DEFAULT NULL AFTER `invoice_id`");
        $pdo->exec("ALTER TABLE `appointments` ADD CONSTRAINT `fk_apt_cps` FOREIGN KEY (`customer_package_service_id`) REFERENCES `customer_package_services` (`id`) ON DELETE SET NULL");
        echo "Added customer_package_service_id to appointments.\n";
    } catch (PDOException $e) {
        // Column might already exist
        echo "Note: appointments table alter might have already been run. Error: " . $e->getMessage() . "\n";
    }

    echo "Migration completed successfully!\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
