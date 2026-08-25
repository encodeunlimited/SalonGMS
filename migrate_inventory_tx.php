<?php
$db = new PDO('sqlite:data/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $db->exec("
    CREATE TABLE IF NOT EXISTS inventory_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tenant_id INTEGER NOT NULL,
        type VARCHAR(20) NOT NULL,
        reference_no VARCHAR(100) NULL,
        item_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_cost DECIMAL(10,2) NULL,
        total_cost DECIMAL(10,2) NULL,
        expiry_date DATE NULL,
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id),
        FOREIGN KEY (item_id) REFERENCES inventory_items(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )
    ");
    echo "Created inventory_transactions table.\n";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
