<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (!empty($_ENV['DB_SSL_CA'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $options);

    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "Table: $table\n";
        $stmt2 = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cols = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo "Columns: " . implode(", ", $cols) . "\n\n";
    }
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
