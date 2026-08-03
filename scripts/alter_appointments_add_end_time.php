<?php
require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dsn = "mysql:host={$_ENV['DB_HOST']};port={$_ENV['DB_PORT']};dbname={$_ENV['DB_DATABASE']};charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

if (isset($_ENV['DB_SSL_CA'])) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], $options);
    
    // Add apt_end_time column
    $sql = "ALTER TABLE appointments ADD COLUMN apt_end_time VARCHAR(50) NULL AFTER apt_time";
    $pdo->exec($sql);
    echo "Added apt_end_time column successfully.\n";

    // Set default end_time for existing appointments (assume 1 hour duration)
    // apt_time is like "13:00"
    $updateSql = "
        UPDATE appointments 
        SET apt_end_time = DATE_FORMAT(DATE_ADD(STR_TO_DATE(CONCAT(apt_date, ' ', apt_time), '%Y-%m-%d %H:%i'), INTERVAL 1 HOUR), '%H:%i')
        WHERE apt_end_time IS NULL
    ";
    $affected = $pdo->exec($updateSql);
    echo "Updated $affected existing appointments with default end time.\n";

} catch (\PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
