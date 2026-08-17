<?php

echo "<h1>SalonMS Live SQLite Migration (Expenses)</h1>";
echo "<pre>";

$dbPath = __DIR__ . '/data/database.sqlite';

if (!file_exists($dbPath)) {
    // Fallback if data dir is one level up
    $dbPath = __DIR__ . '/../data/database.sqlite';
    if (!file_exists($dbPath)) {
        // Fallback for local testing just in case
        $dbPath = __DIR__ . '/database.sqlite';
        if (!file_exists($dbPath)) {
            die("Database file not found. Please check the path.\n");
        }
    }
}

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to SQLite Database: $dbPath\n\n";

    echo "Creating expenses table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `expenses` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `tenant_id` INTEGER NOT NULL,
            `expense_date` DATE NOT NULL,
            `category` VARCHAR(50) NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `description` TEXT,
            `payment_method` VARCHAR(50),
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    echo "Created table expenses.\n";

    echo "\nMigration completed successfully! You can now safely delete this script.";

} catch (PDOException $e) {
    echo "\nMigration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
