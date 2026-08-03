<?php
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$settings = require __DIR__ . '/config/settings.php';
$dbSettings = $settings['db'];

$dsn = "mysql:host={$dbSettings['host']};port={$dbSettings['port']};dbname={$dbSettings['database']};charset=utf8mb4";
$options = [
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (!empty($dbSettings['ssl_ca'])) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $dbSettings['ssl_ca'];
}

$pdo = new PDO($dsn, $dbSettings['username'], $dbSettings['password'], $options);

$repo = new \App\Repositories\UserRepository($pdo);
$repo->setTenantId(1);
try {
    $res = $repo->create([
        'name' => 'Test User',
        'email' => 'test'.time().'@test.com',
        'password' => 'secret',
        'role' => 'stylist',
        'commission_rate' => 10.5,
        'profile_image' => null,
        'specialist_areas' => ['1']
    ]);
    print_r($res);
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
