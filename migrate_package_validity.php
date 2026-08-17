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

    $connection = $_ENV['DB_CONNECTION'] ?? 'mysql';
    
    if ($connection === 'sqlite') {
        $dbPath = __DIR__ . '/data/database.sqlite';
        if (isset($_ENV['DB_DATABASE']) && strpos($_ENV['DB_DATABASE'], '.sqlite') !== false) {
            $dbPath = __DIR__ . '/' . $_ENV['DB_DATABASE'];
        } elseif (isset($_ENV['DB_DATABASE']) && $_ENV['DB_DATABASE'] !== 'database' && $_ENV['DB_DATABASE'] !== 'salonms') {
            $dbPath = __DIR__ . '/data/database.sqlite'; 
        }
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        if (!empty($_ENV['DB_SSL_CA'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4";
        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $options);
    }

    echo "Running migration for Package Validity...\n";

    if ($connection === 'sqlite') {
        try {
            $stmt = $pdo->query("PRAGMA table_info(packages)");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('validity_months', $columns)) {
                $pdo->exec("ALTER TABLE `packages` ADD COLUMN `validity_months` INTEGER DEFAULT NULL;");
                echo "Added validity_months to packages.\n";
            }
        } catch (PDOException $e) { echo $e->getMessage() . "\n"; }

        try {
            $stmt = $pdo->query("PRAGMA table_info(customer_packages)");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('expires_at', $columns)) {
                $pdo->exec("ALTER TABLE `customer_packages` ADD COLUMN `expires_at` TIMESTAMP DEFAULT NULL;");
                echo "Added expires_at to customer_packages.\n";
            }
        } catch (PDOException $e) { echo $e->getMessage() . "\n"; }
    } else {
        try {
            $pdo->exec("ALTER TABLE `packages` ADD COLUMN `validity_months` int DEFAULT NULL AFTER `price`");
            echo "Added validity_months to packages.\n";
        } catch (PDOException $e) { echo "Note: packages alter might have already been run. Error: " . $e->getMessage() . "\n"; }

        try {
            $pdo->exec("ALTER TABLE `customer_packages` ADD COLUMN `expires_at` timestamp NULL DEFAULT NULL AFTER `status`");
            echo "Added expires_at to customer_packages.\n";
        } catch (PDOException $e) { echo "Note: customer_packages alter might have already been run. Error: " . $e->getMessage() . "\n"; }
    }

    echo "Migration completed successfully!\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
