<?php
require __DIR__ . '/../vendor/autoload.php';
$containerBuilder = new \DI\ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/dependencies.php');
$container = $containerBuilder->build();
$pdo = $container->get(PDO::class);

$stmt = $pdo->query('DESCRIBE tenants');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
