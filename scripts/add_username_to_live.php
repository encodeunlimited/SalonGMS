<?php
try {
    $db = new PDO('sqlite:' . dirname(__DIR__) . '/data/live_database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if user_name column exists
    $result = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
    $columnExists = false;
    foreach ($result as $row) {
        if ($row['name'] === 'user_name') {
            $columnExists = true;
            break;
        }
    }

    if (!$columnExists) {
        $db->exec("ALTER TABLE users ADD COLUMN user_name VARCHAR(255) NULL");
        echo "Column 'user_name' added to 'users' table in LIVE database successfully.\n";
    } else {
        echo "Column 'user_name' already exists in 'users' table in LIVE database.\n";
    }

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
