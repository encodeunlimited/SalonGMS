<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dbHost = $_ENV['DB_HOST'];
$dbPort = $_ENV['DB_PORT'];
$dbName = $_ENV['DB_DATABASE'];
$dbUser = $_ENV['DB_USERNAME'];
$dbPass = $_ENV['DB_PASSWORD'];
$dbSslCa = $_ENV['DB_SSL_CA'] ?? null;

$dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

if ($dbSslCa) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    echo "Connected successfully\n";

    // Update missing customer_names
    $sql = "UPDATE appointments a 
            JOIN customers c ON a.customer_id = c.id 
            SET a.customer_name = c.name 
            WHERE a.customer_name = '' OR a.customer_name IS NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    echo "Updated " . $stmt->rowCount() . " appointments.\n";
} catch (\PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
