<?php
echo "<h1>SalonMS Database Migrations</h1>";
echo "<pre>";

$dbPath = __DIR__ . '/data/database.sqlite';

if (!file_exists($dbPath)) {
    die("Database file not found at: $dbPath\nAre you sure you placed this script next to app.phar?");
}

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to SQLite Database: $dbPath\n\n";

    // 1. Create pos_sessions table
    echo "Creating pos_sessions table...\n";
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS pos_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        opened_by INTEGER NOT NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        opening_balance REAL NOT NULL,
        closed_by INTEGER NULL,
        closed_at DATETIME NULL,
        closing_balance REAL NULL,
        expected_balance REAL NULL,
        status TEXT NOT NULL DEFAULT 'open',
        FOREIGN KEY (tenant_id) REFERENCES tenants(id),
        FOREIGN KEY (opened_by) REFERENCES users(id),
        FOREIGN KEY (closed_by) REFERENCES users(id)
    )
    ");
    echo "Created table pos_sessions (or already exists).\n\n";

    // 2. Add expiry_date to inventory_items
    echo "Checking inventory_items table for expiry_date...\n";
    try {
        $stmt = $pdo->query("PRAGMA table_info(inventory_items)");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('expiry_date', $columns)) {
            $pdo->exec("ALTER TABLE inventory_items ADD COLUMN expiry_date DATE NULL;");
            echo "Added expiry_date column to inventory_items.\n";
        } else {
            echo "Column expiry_date already exists on inventory_items.\n";
        }
    } catch (PDOException $e) {
        echo "Error altering inventory_items: " . $e->getMessage() . "\n";
    }

    echo "\nAll migrations completed successfully! You can safely delete this script.";

} catch (PDOException $e) {
    echo "\nMigration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
