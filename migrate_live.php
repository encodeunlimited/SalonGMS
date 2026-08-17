<?php

echo "<h1>SalonMS Live SQLite Migration</h1>";
echo "<pre>";

$dbPath = __DIR__ . '/data/database.sqlite';

if (!file_exists($dbPath)) {
    die("Database file not found at: $dbPath\nAre you sure you placed this script next to app.phar?");
}

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to SQLite Database: $dbPath\n\n";

    // 1. Create customer_packages table
    echo "Creating customer_packages table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_packages` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `tenant_id` INTEGER NOT NULL,
            `customer_id` INTEGER NOT NULL,
            `package_id` INTEGER NOT NULL,
            `status` VARCHAR(50) DEFAULT 'active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
            FOREIGN KEY(`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
            FOREIGN KEY(`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE
        );
    ");
    echo "Created table customer_packages.\n";

    // 2. Create customer_package_services table
    echo "Creating customer_package_services table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `customer_package_services` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `tenant_id` INTEGER NOT NULL,
            `customer_package_id` INTEGER NOT NULL,
            `service_id` INTEGER NOT NULL,
            `total_quantity` INTEGER NOT NULL DEFAULT 1,
            `used_quantity` INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
            FOREIGN KEY(`customer_package_id`) REFERENCES `customer_packages`(`id`) ON DELETE CASCADE,
            FOREIGN KEY(`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE
        );
    ");
    echo "Created table customer_package_services.\n";

    // 3. Alter appointments table
    echo "Altering appointments table...\n";
    try {
        $stmt = $pdo->query("PRAGMA table_info(appointments)");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('customer_package_service_id', $columns)) {
            $pdo->exec("ALTER TABLE `appointments` ADD COLUMN `customer_package_service_id` INTEGER DEFAULT NULL;");
            echo "Added customer_package_service_id to appointments.\n";
        } else {
            echo "Column customer_package_service_id already exists on appointments.\n";
        }
    } catch (PDOException $e) {
        echo "Note: appointments table alter might have already been run. Error: " . $e->getMessage() . "\n";
    }

    echo "\nMigration completed successfully! You can now safely delete this script.";

} catch (PDOException $e) {
    echo "\nMigration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
