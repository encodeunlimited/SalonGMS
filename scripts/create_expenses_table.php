<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dbPath = __DIR__ . '/../database.sqlite';
$db = new PDO("sqlite:" . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    expense_date DATE NOT NULL,
    category VARCHAR(50) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description TEXT,
    payment_method VARCHAR(50),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
";

try {
    $db->exec($sql);
    echo "Expenses table created successfully!\n";
} catch (PDOException $e) {
    echo "Error creating expenses table: " . $e->getMessage() . "\n";
}
