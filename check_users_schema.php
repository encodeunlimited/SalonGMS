<?php
require __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$connection = 'mysql';
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$db   = $_ENV['DB_DATABASE'] ?? 'test';
$user = $_ENV['DB_USERNAME'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$ssl_ca = $_ENV['DB_SSL_CA'] ?? null;

$dsn = "$connection:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];
if (!empty($ssl_ca)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // Check users table schema
    $stmt = $pdo->query("SHOW CREATE TABLE users");
    print_r($stmt->fetch(PDO::FETCH_ASSOC));
    
    // Check distinct roles
    $stmt2 = $pdo->query("SELECT DISTINCT role FROM users");
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
    
} catch (\Exception $e) {
    echo $e->getMessage();
}
