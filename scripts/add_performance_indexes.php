<?php

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

$connection = $_ENV['DB_CONNECTION'] ?? 'sqlite';

try {
    if ($connection === 'sqlite') {
        $dbPath = __DIR__ . '/../data/database.sqlite';
        $pdo = new PDO("sqlite:" . $dbPath);
    } else {
        $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
        $dbname = $_ENV['DB_DATABASE'] ?? 'salongms';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $user = $_ENV['DB_USERNAME'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;port=$port;charset=utf8mb4", $user, $pass);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "--- Adding High-Performance Database Indexes ---\n";

    $indexes = [
        // Tenant Settings (Queried on EVERY page request!)
        "idx_tenant_settings_lookup" => "CREATE INDEX IF NOT EXISTS idx_tenant_settings_lookup ON tenant_settings (tenant_id, setting_key)",
        
        // Appointments (Dashboard, Calendar, POS, Reports)
        "idx_appointments_tenant_date" => "CREATE INDEX IF NOT EXISTS idx_appointments_tenant_date ON appointments (tenant_id, apt_date, status)",
        "idx_appointments_customer" => "CREATE INDEX IF NOT EXISTS idx_appointments_customer ON appointments (tenant_id, customer_id)",
        "idx_appointments_user" => "CREATE INDEX IF NOT EXISTS idx_appointments_user ON appointments (tenant_id, user_id, apt_date)",
        "idx_appointments_invoice" => "CREATE INDEX IF NOT EXISTS idx_appointments_invoice ON appointments (tenant_id, invoice_id)",
        
        // Services (Catalog, POS search, category filtering)
        "idx_services_tenant_cat" => "CREATE INDEX IF NOT EXISTS idx_services_tenant_cat ON services (tenant_id, category)",
        "idx_services_tenant_name" => "CREATE INDEX IF NOT EXISTS idx_services_tenant_name ON services (tenant_id, name)",
        
        // Service Categories
        "idx_service_cats_tenant" => "CREATE INDEX IF NOT EXISTS idx_service_cats_tenant ON service_categories (tenant_id, name)",
        
        // Customers (Search by phone, name, email)
        "idx_customers_tenant_phone" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_phone ON customers (tenant_id, phone)",
        "idx_customers_tenant_name" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_name ON customers (tenant_id, name)",
        "idx_customers_tenant_email" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_email ON customers (tenant_id, email)",
        
        // Invoices & Billing (POS, Reports, History)
        "idx_invoices_tenant_created" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_created ON invoices (tenant_id, created_at, status)",
        "idx_invoices_tenant_customer" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_customer ON invoices (tenant_id, customer_id)",
        "idx_invoices_tenant_apt" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_apt ON invoices (tenant_id, appointment_id)",
        
        // Invoice Items (Line items for reports and commission calculations)
        "idx_invoice_items_invoice" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items (invoice_id)",
        "idx_invoice_items_service" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_service ON invoice_items (service_id)",
        "idx_invoice_items_tenant" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_tenant ON invoice_items (tenant_id)",
        
        // Users & Auth
        "idx_users_tenant_role" => "CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users (tenant_id, role)",
        "idx_users_email" => "CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)",
        "idx_users_username" => "CREATE INDEX IF NOT EXISTS idx_users_username ON users (user_name)",
        
        // Queue Tickets (Barber queue and POS checkout)
        "idx_queue_tickets_status" => "CREATE INDEX IF NOT EXISTS idx_queue_tickets_status ON queue_tickets (tenant_id, status)",
        "idx_queue_tickets_barber" => "CREATE INDEX IF NOT EXISTS idx_queue_tickets_barber ON queue_tickets (tenant_id, barber_id, status)",
        "idx_queue_tickets_created" => "CREATE INDEX IF NOT EXISTS idx_queue_tickets_created ON queue_tickets (tenant_id, created_at)",
        
        // Packages & Customer Packages
        "idx_cust_packages_lookup" => "CREATE INDEX IF NOT EXISTS idx_cust_packages_lookup ON customer_packages (tenant_id, customer_id, status)",
        "idx_cust_pkg_services" => "CREATE INDEX IF NOT EXISTS idx_cust_pkg_services ON customer_package_services (customer_package_id, service_id)",
        "idx_pkg_items" => "CREATE INDEX IF NOT EXISTS idx_pkg_items ON package_items (package_id, service_id)",
        
        // Commissions & Loyalty
        "idx_commissions_lookup" => "CREATE INDEX IF NOT EXISTS idx_commissions_lookup ON commissions (tenant_id, user_id, created_at)",
        "idx_loyalty_customer" => "CREATE INDEX IF NOT EXISTS idx_loyalty_customer ON loyalty_transactions (tenant_id, customer_id)",
        
        // Inventory
        "idx_inventory_tenant" => "CREATE INDEX IF NOT EXISTS idx_inventory_tenant ON inventory_items (tenant_id, quantity)",
        "idx_inv_trans_item" => "CREATE INDEX IF NOT EXISTS idx_inv_trans_item ON inventory_transactions (tenant_id, item_id)",
        
        // Expenses
        "idx_expenses_lookup" => "CREATE INDEX IF NOT EXISTS idx_expenses_lookup ON expenses (tenant_id, expense_date, category)",

        // POS Sessions
        "idx_pos_sessions_tenant" => "CREATE INDEX IF NOT EXISTS idx_pos_sessions_tenant ON pos_sessions (tenant_id, opened_by, status)"
    ];

    foreach ($indexes as $name => $sql) {
        try {
            $pdo->exec($sql);
            echo "[OK] $name created / verified.\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                echo "[EXISTS] $name already exists.\n";
            } else {
                echo "[WARN] Could not create $name: " . $e->getMessage() . "\n";
            }
        }
    }

    if ($connection === 'sqlite') {
        // Run SQLite PRAGMA optimize
        $pdo->exec("PRAGMA optimize;");
        echo "SQLite PRAGMA optimize executed.\n";
    }

    echo "\nAll performance indexes applied successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
