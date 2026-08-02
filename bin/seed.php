<?php
require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$containerBuilder = new ContainerBuilder();
$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);
$container = $containerBuilder->build();

/** @var PDO $pdo */
$pdo = $container->get(PDO::class);

echo "Clearing data...\n";

// Disable foreign key checks for clearing
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$pdo->exec("TRUNCATE TABLE invoice_items");
$pdo->exec("TRUNCATE TABLE invoices");
$pdo->exec("TRUNCATE TABLE appointments");
$pdo->exec("TRUNCATE TABLE commissions");
$pdo->exec("DELETE FROM services");
$pdo->exec("DELETE FROM customers");
$pdo->exec("DELETE FROM users WHERE role != 'admin'");

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "Data cleared (excluding admin user).\n";
echo "Seeding dummy data...\n";

$tenantId = 1;

// Insert Services
$services = [
    ['name' => 'Men\'s Haircut', 'description' => 'Classic men\'s haircut with fade', 'duration' => 30, 'price' => 25.00],
    ['name' => 'Women\'s Haircut', 'description' => 'Wash, cut, and style', 'duration' => 60, 'price' => 45.00],
    ['name' => 'Hair Coloring', 'description' => 'Full head coloring', 'duration' => 120, 'price' => 120.00],
    ['name' => 'Balayage', 'description' => 'Hand-painted highlights', 'duration' => 150, 'price' => 150.00],
    ['name' => 'Manicure', 'description' => 'Classic manicure with gel polish', 'duration' => 45, 'price' => 35.00],
    ['name' => 'Pedicure', 'description' => 'Spa pedicure with massage', 'duration' => 60, 'price' => 50.00],
];

$stmtService = $pdo->prepare("INSERT INTO services (tenant_id, name, description, duration_minutes, price) VALUES (?, ?, ?, ?, ?)");
$serviceIds = [];
foreach ($services as $s) {
    $stmtService->execute([$tenantId, $s['name'], $s['description'], $s['duration'], $s['price']]);
    $serviceIds[] = $pdo->lastInsertId();
}

// Insert Customers
$customers = [
    ['name' => 'Emma Watson', 'email' => 'emma@example.com', 'phone' => '555-0101'],
    ['name' => 'Liam Neeson', 'email' => 'liam@example.com', 'phone' => '555-0102'],
    ['name' => 'Olivia Cole', 'email' => 'olivia@example.com', 'phone' => '555-0103'],
    ['name' => 'Noah Smith', 'email' => 'noah@example.com', 'phone' => '555-0104'],
    ['name' => 'Ava Brown', 'email' => 'ava@example.com', 'phone' => '555-0105'],
];

$stmtCustomer = $pdo->prepare("INSERT INTO customers (tenant_id, name, email, phone) VALUES (?, ?, ?, ?)");
$customerIds = [];
foreach ($customers as $c) {
    $stmtCustomer->execute([$tenantId, $c['name'], $c['email'], $c['phone']]);
    $customerIds[] = $pdo->lastInsertId();
}

// Insert Stylists (Employees)
$employees = [
    ['name' => 'Sarah Stylist', 'email' => 'sarah@example.com', 'pass' => password_hash('password', PASSWORD_DEFAULT), 'role' => 'stylist', 'comm' => 40.0],
    ['name' => 'Mike Barber', 'email' => 'mike@example.com', 'pass' => password_hash('password', PASSWORD_DEFAULT), 'role' => 'stylist', 'comm' => 35.0],
];
$stmtEmp = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, commission_rate) VALUES (?, ?, ?, ?, ?, ?)");
$employeeIds = [];
foreach ($employees as $e) {
    $stmtEmp->execute([$tenantId, $e['name'], $e['email'], $e['pass'], $e['role'], $e['comm']]);
    $employeeIds[] = $pdo->lastInsertId();
}

// Determine current admin user (if any, use ID 1 as fallback)
$stmtAdmin = $pdo->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$stmtAdmin->execute();
$adminId = $stmtAdmin->fetchColumn() ?: 1;
$employeeIds[] = $adminId; // Admin can also take appointments

$stmtAppt = $pdo->prepare("INSERT INTO appointments (tenant_id, customer_id, user_id, service_id, customer_name, service, stylist, apt_date, apt_time, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$statuses = ['scheduled', 'completed', 'cancelled'];
$now = time();
$appointmentIds = [];

for ($i = 0; $i < 15; $i++) {
    $cIdx = array_rand($customers);
    $custId = $customerIds[$cIdx];
    $customerName = $customers[$cIdx]['name'];
    
    $eIdx = array_rand($employees);
    // If eIdx is out of bounds for employeeIds (admin fallback), get safely
    if (!isset($employeeIds[$eIdx])) $eIdx = 0;
    $empId = $employeeIds[$eIdx];
    $stylistName = $employees[$eIdx]['name'];
    
    $servIdx = array_rand($services);
    $servId = $serviceIds[$servIdx];
    $serviceName = $services[$servIdx]['name'];
    $duration = $services[$servIdx]['duration'];
    
    // Random day within -2 to +5 days
    $dayOffset = rand(-2, 5);
    $hour = rand(9, 16); // 9 AM to 4 PM
    $minute = array_rand([0 => 0, 30 => 30]); // 0 or 30 mins
    
    $startTime = strtotime(date('Y-m-d', strtotime("$dayOffset days"))) + ($hour * 3600) + ($minute * 60);
    $aptDate = date('Y-m-d', $startTime);
    $aptTime = date('H:i', $startTime);
    
    $status = $startTime < time() ? 'completed' : 'scheduled';
    if (rand(1, 10) > 8) $status = 'cancelled'; // 20% cancellation
    
    $stmtAppt->execute([
        $tenantId, 
        $custId, 
        $empId, 
        $servId,
        $customerName,
        $serviceName,
        $stylistName,
        $aptDate, 
        $aptTime, 
        $status
    ]);
    
    $appointmentIds[] = $pdo->lastInsertId();
}

// Insert Invoices for completed appointments
$stmtInv = $pdo->prepare("INSERT INTO invoices (tenant_id, customer_id, total_amount, status) VALUES (?, ?, ?, ?)");
$stmtInvItem = $pdo->prepare("INSERT INTO invoice_items (tenant_id, invoice_id, service_id, description, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?)");

$stmtCompletedAppts = $pdo->prepare("SELECT id, customer_id, service_id FROM appointments WHERE status = 'completed'");
$stmtCompletedAppts->execute();
$completed = $stmtCompletedAppts->fetchAll();

foreach ($completed as $appt) {
    // Find service price
    $price = 0;
    $serviceName = 'Service';
    foreach ($services as $idx => $s) {
        if ($serviceIds[$idx] == $appt['service_id']) {
            $price = $s['price'];
            $serviceName = $s['name'];
            break;
        }
    }
    
    // Create Invoice
    $stmtInv->execute([$tenantId, $appt['customer_id'], $price, 'paid']);
    $invoiceId = $pdo->lastInsertId();
    
    // Create Invoice Item
    $stmtInvItem->execute([$tenantId, $invoiceId, $appt['service_id'], $serviceName, 1, $price, $price]);
}

echo "Seeding complete!\n";
