<?php
$db = new PDO('sqlite:data/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $db->exec('ALTER TABLE inventory_items ADD COLUMN expiry_date DATE NULL');
    echo "Added expiry_date\n";
} catch(Exception $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "Column already exists\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
