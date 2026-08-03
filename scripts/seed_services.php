<?php

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

try {
    $dbConnection = $_ENV['DB_CONNECTION'] ?? 'sqlite';
    
    if ($dbConnection === 'mysql') {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $db   = $_ENV['DB_DATABASE'] ?? 'salon_ms';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';
        
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        if (!empty($_ENV['DB_SSL_CA'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $_ENV['DB_SSL_CA'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
        
        $pdo = new PDO($dsn, $user, $pass, $options);
    } else {
        $dbPath = __DIR__ . '/../database.sqlite';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // 1. Add category column if it doesn't exist
    try {
        if ($dbConnection === 'mysql') {
            $pdo->exec("ALTER TABLE services ADD COLUMN category VARCHAR(255) NULL");
            echo "Added 'category' column to 'services' table.\n";
        } else {
            // SQLite
            $pdo->exec("ALTER TABLE services ADD COLUMN category VARCHAR(255) NULL");
            echo "Added 'category' column to 'services' table.\n";
        }
    } catch (\PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false || strpos($e->getMessage(), 'duplicate column name') !== false) {
            echo "Column 'category' already exists. Skipping.\n";
        } else {
            throw $e;
        }
    }

    // 2. Define the services to seed
    $tenantId = 1; // Assuming tenant 1 for now

    $servicesToSeed = [
        'Henna' => [
            'Finger Front Only', 'Finger Front and Back', 'Palm Front Only', 'Palm Front and Back',
            'Forearm Front', 'Forearm Back', 'Forearm Half Front', 'Forearm Half Back', 'Elbow Front',
            'Elbow Back', 'Foot Sole Full', 'Foot Sole with Finger Design', 'Foot Sole Design',
            'Ankle Only', 'Golf (Calf) as per Design', 'Knee as per Design', 'Bride Henna'
        ],
        'Coloring' => [
            'Eyebrow Color Only', 'Eyebrow Thread with Color', 'Eyebrow Bleach with Color', 
            'Eyebrow Cutting', 'Eyebrow Cutting with Color'
        ],
        'Manicure & Pedicure' => [
            'Manicure', 'Pedicure', 'Manicure and Pedicure', 'Normal Color', 'French Color', 
            'Cut and Nail File', 'Nail Design (One Finger)', 'Gel Polish with Manicure', 
            'Manicure with Gel Polish', 'Pedicure with Gel Polish', 'Manicure and Pedicure with Gel Polish',
            'Gel Nail Extension without Manicure', 'Gel Nail Extension with Manicure', 
            'Gel Nail Extension with Manicure and Gel Polish', 'Acrylic Extension without Manicure', 
            'Acrylic Extension with Manicure', 'Acrylic Extension with Manicure and Gel Polish', 
            'Plastic Nail Extension without Color', 'Plastic Nail Extension with Normal Color', 
            'Plastic Nail Extension with French Color', 'Plastic Nail Extension with Gel Polish', 
            'Remove Acrylic or Gel Nail Extension', 'Remove Plastic Nail Extension and Gel Polish', 
            'Remove Normal Polish Only', 'One Fix Broken Nail Extension', 'Refill Gel or Acrylic Nail Extension',
            'Refill Gel or Acrylic Nail Extension with Manicure', 'Refill Gel or Acrylic Nail Extension with Manicure and Gel Polish'
        ],
        'Masri Halawa (Sugar Wax)' => [
            'Full Body', 'Full Body with Bikini', 'Full Hand with Underarms', 'Full Hand', 'Full Leg', 
            'Full Back', 'Full Front', 'Half Back', 'Half Front', 'Half Leg', 'Underarms'
        ],
        'Moroccan Bath' => [
            'Normal', 'Special', 'Bridal Special'
        ],
        'Bleaching' => [
            'Eyebrow Bleach', 'Upper Lip', 'Forehead', 'Neck', 'Full Face', 'Full Face with Neck'
        ],
        'Threading' => [
            'Eyebrow Threading', 'Upper Lip', 'Chin', 'Forehead', 'Neck', 'Full Face', 
            'Full Face with Neck', 'Side Lock Threading'
        ],
        'Waxing' => [
            'Upper Lips', 'Chin', 'Forehead', 'Neck', 'Nose', 'Full Face', 'Full Face with Neck', 
            'Full Body Wax', 'Full Body with Bikini Wax', 'Full Hand with Underarms', 'Full Hand', 
            'Full Leg', 'Full Back', 'Full Front', 'Half Back', 'Half Front', 'Half Hand', 'Half Leg', 'Underarms'
        ],
        'Hair' => [
            'Normal Hair Wash', 'Dandruff Cleaning', 'Brazilian Cacau Hair Wash', 'Hair Cut for Kids', 
            'Basic Cut', 'Split End Treatment', 'Hair Trimming', 'Step Cut (Thin Hair)', 'Step Cut (Thick Hair)', 
            'Hot Oil Treatment', 'Hair Treatment Cream', 'Hair Treatment Aloe Vera with Vitamin E', 
            'Hair Straight, Curly, Wavy', 'Hair Color Roots', 'Thin Hair Color', 'Thick Hair Color', 
            'Highlights Hair', 'Hair Henna', 'Protein & Keratin Treatment', 'Blow Dry (Short Light Hair)', 
            'Blow Dry (Short Thick Hair)'
        ],
        'Massage' => [
            'Ayurvedic Massage', 'Deep Tissue Massage', 'Aroma Therapy Massage', 'Back Massage', 
            'Foot Massage', 'Head Massage'
        ],
        'Facial' => [
            'Special Facial', 'Normal Facial'
        ]
    ];

    $stmt = $pdo->prepare("INSERT INTO services (tenant_id, name, category, description, duration_minutes, price) VALUES (?, ?, ?, ?, ?, ?)");
    
    $checkStmt = $pdo->prepare("SELECT id FROM services WHERE tenant_id = ? AND name = ? AND category = ?");

    $addedCount = 0;

    foreach ($servicesToSeed as $category => $services) {
        foreach ($services as $serviceName) {
            // Check if exists
            $checkStmt->execute([$tenantId, $serviceName, $category]);
            if (!$checkStmt->fetch()) {
                $stmt->execute([
                    $tenantId,
                    $serviceName,
                    $category,
                    "Professional " . strtolower($serviceName) . " service.",
                    30, // Default duration
                    0.00 // Default price
                ]);
                $addedCount++;
            }
        }
    }

    echo "Successfully seeded $addedCount new services into the database.\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
