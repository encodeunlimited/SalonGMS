<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$pdo = new PDO('mysql:host='.$_ENV['DB_HOST'].';dbname='.$_ENV['DB_NAME'].';port='.$_ENV['DB_PORT'], $_ENV['DB_USER'], $_ENV['DB_PASS']);
$stmt = $pdo->query('DESCRIBE users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt = $pdo->query('DESCRIBE appointments');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
