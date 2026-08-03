<?php
require "vendor/autoload.php";
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$settings = require "config/settings.php";
$dbSettings = $settings["db"];
$dsn = "mysql:host={$dbSettings["host"]};port={$dbSettings["port"]};dbname={$dbSettings["database"]};charset=utf8mb4";
$options = [
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (!empty($dbSettings["ssl_ca"])) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $dbSettings["ssl_ca"];
}
$pdo = new PDO($dsn, $dbSettings["username"], $dbSettings["password"], $options);

$view = Slim\Views\Twig::create(__DIR__ . "/templates", ["cache" => false]);
$users = new \App\Repositories\UserRepository($pdo);
$services = new \App\Repositories\ServiceRepository($pdo);

$controller = new \App\Web\Controllers\EmployeeController($view, $users, $services);

// Let's find an employee ID to test
$stmt = $pdo->query("SELECT id FROM users LIMIT 1");
$empId = $stmt->fetchColumn();

if (!$empId) {
    echo "NO EMPLOYEES FOUND\n";
    exit;
}

$request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest("GET", "/web/employees/{$empId}/edit");
$request = $request->withAttribute("tenant_id", 1);
$response = (new \Slim\Psr7\Factory\ResponseFactory())->createResponse();

try {
    $res = $controller->edit($request, $response, ['id' => $empId]);
    echo "SUCCESS\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
