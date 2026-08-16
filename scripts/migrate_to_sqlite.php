<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$mysqlHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$mysqlDb = $_ENV['DB_DATABASE'] ?? 'salonms';
$mysqlUser = $_ENV['DB_USERNAME'] ?? 'root';
$mysqlPass = $_ENV['DB_PASSWORD'] ?? '';
$mysqlPort = $_ENV['DB_PORT'] ?? '3306';

$sqlitePath = __DIR__ . '/../data/database.sqlite';

try {
    echo "Connecting to MySQL Database...\n";
    $dsn = "mysql:host=$mysqlHost;dbname=$mysqlDb;port=$mysqlPort;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (!empty($_ENV['DB_SSL_CA'])) {
        $options[\Pdo\Mysql::ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
    }
    $mysqlPdo = new PDO($dsn, $mysqlUser, $mysqlPass, $options);
    
    echo "Connecting to SQLite Database at $sqlitePath...\n";
    if (!is_dir(__DIR__ . '/../data')) {
        mkdir(__DIR__ . '/../data', 0777, true);
    }
    
    // Backup existing SQLite DB if it exists
    if (file_exists($sqlitePath)) {
        rename($sqlitePath, $sqlitePath . '.bak.' . time());
        echo "Backed up existing SQLite DB.\n";
    }

    $sqlitePdo = new PDO("sqlite:$sqlitePath");
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlitePdo->exec("PRAGMA journal_mode=WAL;");
    $sqlitePdo->exec("PRAGMA synchronous=NORMAL;");
    $sqlitePdo->exec("PRAGMA foreign_keys=OFF;"); // Disable foreign keys during import
    
    // Get all tables
    $tablesQuery = $mysqlPdo->query("SHOW TABLES");
    $tables = $tablesQuery->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        echo "Processing table: $table\n";
        
        // 1. Get MySQL Schema
        $createTableStmt = $mysqlPdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $mysqlSchema = $createTableStmt['Create Table'];
        
        // 2. Convert to SQLite Schema
        // This is a basic regex conversion.
        $sqliteSchema = preg_replace('/AUTO_INCREMENT/', 'AUTOINCREMENT', $mysqlSchema);
        $sqliteSchema = preg_replace('/ENGINE=InnoDB.*/', ';', $sqliteSchema);
        $sqliteSchema = preg_replace('/CHARACTER SET [a-zA-Z0-9_]+/', '', $sqliteSchema);
        $sqliteSchema = preg_replace('/COLLATE [a-zA-Z0-9_]+/', '', $sqliteSchema);
        $sqliteSchema = preg_replace('/COMMENT\s*\'[^\']*\'/', '', $sqliteSchema);
        $sqliteSchema = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/', '', $sqliteSchema);
        $sqliteSchema = preg_replace('/ENUM\([^)]+\)/', 'VARCHAR(255)', $sqliteSchema);
        // Replace INT/BIGINT AUTO_INCREMENT PRIMARY KEY with INTEGER PRIMARY KEY AUTOINCREMENT
        $sqliteSchema = preg_replace('/`id` (int|bigint)[^,]*AUTOINCREMENT/', '`id` INTEGER PRIMARY KEY AUTOINCREMENT', $sqliteSchema);
        // Remove trailing PRIMARY KEY (...) if id is already primary key
        if (strpos($sqliteSchema, '`id` INTEGER PRIMARY KEY AUTOINCREMENT') !== false) {
            $sqliteSchema = preg_replace('/,\s*PRIMARY KEY \(`id`\)/', '', $sqliteSchema);
        }
        // Remove KEY and UNIQUE KEY syntax which is unsupported inline in SQLite (needs separate CREATE INDEX)
        // We'll just strip KEY and UNIQUE KEY lines for now to ensure data imports.
        $lines = explode("\n", $sqliteSchema);
        $newLines = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*UNIQUE KEY /', $line) || preg_match('/^\s*KEY /', $line) || preg_match('/^\s*CONSTRAINT /', $line)) {
                // Skip index and constraint definitions inline for sqlite simplicity
                continue;
            }
            $newLines[] = $line;
        }
        $sqliteSchema = implode("\n", $newLines);
        // Clean up trailing commas before closing parenthesis
        $sqliteSchema = preg_replace('/,\s*\)\s*;/', "\n);", $sqliteSchema);

        echo "Creating table $table in SQLite...\n";
        try {
            $sqlitePdo->exec($sqliteSchema);
        } catch (Exception $e) {
            echo "Warning: Error creating table $table. Using basic creation.\n";
            echo $e->getMessage() . "\n";
            // Fallback: create basic table from column types
            $columnsStmt = $mysqlPdo->query("SHOW COLUMNS FROM `$table`");
            $columns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
            $colDefs = [];
            foreach ($columns as $col) {
                if ($col['Field'] === 'id') {
                    $colDefs[] = "`id` INTEGER PRIMARY KEY AUTOINCREMENT";
                } else {
                    $colDefs[] = "`{$col['Field']}` TEXT"; // Fallback everything to text
                }
            }
            $fallbackSchema = "CREATE TABLE `$table` (" . implode(', ', $colDefs) . ");";
            $sqlitePdo->exec($fallbackSchema);
        }
        
        // 3. Migrate Data
        $dataStmt = $mysqlPdo->query("SELECT * FROM `$table`");
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            $sqlitePdo->beginTransaction();
            $columns = array_keys($rows[0]);
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnsStr = implode('`, `', $columns);
            
            $insertSql = "INSERT INTO `$table` (`$columnsStr`) VALUES ($placeholders)";
            $insertStmt = $sqlitePdo->prepare($insertSql);
            
            foreach ($rows as $row) {
                // MySQL JSON might need to be cast or checked, but SQLite handles strings fine
                $insertStmt->execute(array_values($row));
            }
            $sqlitePdo->commit();
            echo "Migrated " . count($rows) . " rows to $table.\n";
        } else {
            echo "No data in $table.\n";
        }
    }
    
    $sqlitePdo->exec("PRAGMA foreign_keys=ON;");
    echo "\nMigration Complete! SQLite database is ready at $sqlitePath\n";

} catch (Exception $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
    exit(1);
}
