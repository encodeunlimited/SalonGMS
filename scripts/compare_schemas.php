<?php
$db1Path = __DIR__ . '/../data/database.sqlite';
$db2Path = __DIR__ . '/../data/live_database.sqlite';

if (!file_exists($db1Path) || !file_exists($db2Path)) {
    // Let's check the root directory as well since they might be there
    $db1Path = __DIR__ . '/../database.sqlite';
    $db2Path = __DIR__ . '/../live_database.sqlite';
}

echo "DB1: $db1Path\n";
echo "DB2: $db2Path\n\n";

try {
    $db1 = new PDO('sqlite:' . $db1Path);
    $db2 = new PDO('sqlite:' . $db2Path);
    $db1->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables1 = $db1->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);
    $tables2 = $db2->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC);

    $schema1 = [];
    foreach ($tables1 as $t) { $schema1[$t['name']] = $t['sql']; }
    
    $schema2 = [];
    foreach ($tables2 as $t) { $schema2[$t['name']] = $t['sql']; }

    echo "--- Missing Tables in Live DB ---\n";
    foreach ($schema1 as $name => $sql) {
        if (!isset($schema2[$name])) {
            echo "- $name\n";
        }
    }

    echo "\n--- Tables with Differences ---\n";
    foreach ($schema1 as $name => $sql) {
        if (isset($schema2[$name]) && trim($sql) !== trim($schema2[$name])) {
            echo "Table: $name\n";
            echo "DEV:\n" . trim($sql) . "\n";
            echo "LIVE:\n" . trim($schema2[$name]) . "\n\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
