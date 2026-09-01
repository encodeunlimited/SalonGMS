<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dbPath = __DIR__ . '/../data/database.sqlite';
if (!file_exists($dbPath)) {
    die("Database not found at $dbPath\n");
}

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Adding arabic_name to service_categories...\n";
    try {
        $pdo->exec("ALTER TABLE service_categories ADD COLUMN arabic_name TEXT");
        echo "Success.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "Column arabic_name already exists in service_categories.\n";
        } else {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    echo "Adding arabic_name to services...\n";
    try {
        $pdo->exec("ALTER TABLE services ADD COLUMN arabic_name TEXT");
        echo "Success.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "Column arabic_name already exists in services.\n";
        } else {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    echo "Adding arabic_price to services...\n";
    try {
        $pdo->exec("ALTER TABLE services ADD COLUMN arabic_price REAL");
        echo "Success.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "Column arabic_price already exists in services.\n";
        } else {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
