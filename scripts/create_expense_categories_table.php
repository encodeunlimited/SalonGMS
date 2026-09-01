<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Wait, dependencies use .env. Is database.sqlite the one actually used?
// The .env I dumped showed DB_CONNECTION=sqlite but also had DB_HOST=gateway01...
// Let's use the container if possible. But scripts use simple PDO.
$dbPath = __DIR__ . '/../data/database.sqlite';
$db = new PDO("sqlite:" . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
CREATE TABLE IF NOT EXISTS expense_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
";

try {
    $db->exec($sql);
    echo "Expense categories table created successfully!\n";
    
    // Seed some default categories if table is empty
    $stmt = $db->query("SELECT COUNT(*) FROM expense_categories");
    if ($stmt->fetchColumn() == 0) {
        $defaultCategories = [
            'Rent', 'Utilities', 'Inventory', 'Payroll', 'Marketing', 'Maintenance', 'Other'
        ];
        
        $insert = $db->prepare("INSERT INTO expense_categories (tenant_id, name) VALUES (1, ?)");
        foreach ($defaultCategories as $cat) {
            $insert->execute([$cat]);
        }
        echo "Default expense categories seeded.\n";
    }
} catch (PDOException $e) {
    echo "Error creating expense categories table: " . $e->getMessage() . "\n";
}
