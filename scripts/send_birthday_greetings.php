<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Repositories\CustomerRepository;
use App\Services\WhatsAppService;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$connection = $_ENV['DB_CONNECTION'] ?? 'mysql';
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
    
    $customerRepo = new CustomerRepository($pdo);
    
    // We assume tenant 1 for this cron script as an example,
    // or you could loop through tenants.
    $tenantId = 1;
    $customerRepo->setTenantId($tenantId);
    
    $whatsappService = new WhatsAppService(
        $_ENV['WHATSAPP_PROVIDER_URL'] ?? '',
        $_ENV['WHATSAPP_INSTANCE_ID'] ?? '',
        $_ENV['WHATSAPP_TOKEN'] ?? ''
    );

    $customers = $customerRepo->getAll();
    $today = date('m-d');
    
    $count = 0;
    foreach ($customers as $customer) {
        if (!empty($customer['date_of_birth']) && date('m-d', strtotime($customer['date_of_birth'])) === $today) {
            if (!empty($customer['phone'])) {
                $message = "🎉 Happy Birthday, {$customer['name']}! 🎂\n\nWe hope you have a fantastic day. Treat yourself to one of our special beauty packages. Check them out here: https://your-salon-domain.com/portal/packages\n\nBest Wishes,\nSalonMS Team";
                
                $success = $whatsappService->sendMessage($customer['phone'], $message);
                if ($success) {
                    echo "Sent birthday greeting to {$customer['name']} ({$customer['phone']})\n";
                    $count++;
                } else {
                    echo "Failed to send greeting to {$customer['name']} ({$customer['phone']})\n";
                }
            }
        }
    }
    
    echo "Done. Sent {$count} birthday greetings.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
