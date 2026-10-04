<?php

namespace App\Services;

use PDO;
use PDOException;

class DatabaseOptimizer
{
    public static function tuneConnection(PDO $pdo, string $driver = 'sqlite'): void
    {
        if ($driver === 'sqlite') {
            try {
                $pdo->exec('PRAGMA journal_mode = WAL;');
                $pdo->exec('PRAGMA synchronous = NORMAL;');
                $pdo->exec('PRAGMA busy_timeout = 5000;');
                $pdo->exec('PRAGMA cache_size = -64000;'); // 64MB RAM page cache
                $pdo->exec('PRAGMA temp_store = MEMORY;');
                $pdo->exec('PRAGMA mmap_size = 268435456;'); // 256MB memory mapping
                $pdo->exec('PRAGMA foreign_keys = ON;');
                $pdo->exec('PRAGMA optimize;');
            } catch (\Throwable $e) {
                // Ignore pragma failures on restricted hosts
            }
        }
    }

    public static function ensureIndexes(PDO $pdo, string $driver = 'sqlite'): array
    {
        $indexes = [
            // Tenant Settings (Loaded on every page request)
            "idx_tenant_settings_lookup" => "CREATE INDEX IF NOT EXISTS idx_tenant_settings_lookup ON tenant_settings (tenant_id, setting_key)",
            
            // Appointments
            "idx_appointments_tenant_date" => "CREATE INDEX IF NOT EXISTS idx_appointments_tenant_date ON appointments (tenant_id, apt_date, status)",
            "idx_appointments_customer" => "CREATE INDEX IF NOT EXISTS idx_appointments_customer ON appointments (tenant_id, customer_id)",
            "idx_appointments_user" => "CREATE INDEX IF NOT EXISTS idx_appointments_user ON appointments (tenant_id, user_id, apt_date)",
            "idx_appointments_invoice" => "CREATE INDEX IF NOT EXISTS idx_appointments_invoice ON appointments (tenant_id, invoice_id)",
            
            // Services
            "idx_services_tenant_cat" => "CREATE INDEX IF NOT EXISTS idx_services_tenant_cat ON services (tenant_id, category)",
            "idx_services_tenant_name" => "CREATE INDEX IF NOT EXISTS idx_services_tenant_name ON services (tenant_id, name)",
            
            // Service Categories
            "idx_service_cats_tenant" => "CREATE INDEX IF NOT EXISTS idx_service_cats_tenant ON service_categories (tenant_id, name)",
            
            // Customers
            "idx_customers_tenant_phone" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_phone ON customers (tenant_id, phone)",
            "idx_customers_tenant_name" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_name ON customers (tenant_id, name)",
            "idx_customers_tenant_email" => "CREATE INDEX IF NOT EXISTS idx_customers_tenant_email ON customers (tenant_id, email)",
            
            // Invoices
            "idx_invoices_tenant_created" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_created ON invoices (tenant_id, created_at, status)",
            "idx_invoices_tenant_customer" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_customer ON invoices (tenant_id, customer_id)",
            "idx_invoices_tenant_apt" => "CREATE INDEX IF NOT EXISTS idx_invoices_tenant_apt ON invoices (tenant_id, appointment_id)",
            
            // Invoice Items
            "idx_invoice_items_invoice" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items (invoice_id)",
            "idx_invoice_items_service" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_service ON invoice_items (service_id)",
            "idx_invoice_items_tenant" => "CREATE INDEX IF NOT EXISTS idx_invoice_items_tenant ON invoice_items (tenant_id)",
            
            // Users
            "idx_users_tenant_role" => "CREATE INDEX IF NOT EXISTS idx_users_tenant_role ON users (tenant_id, role)",
            "idx_users_email" => "CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)",
            "idx_users_username" => "CREATE INDEX IF NOT EXISTS idx_users_username ON users (user_name)",
            
            // Queue Tickets
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

        $results = [];

        foreach ($indexes as $name => $sql) {
            try {
                $pdo->exec($sql);
                $results[$name] = 'created_or_verified';
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false || strpos($e->getMessage(), 'already exists') !== false) {
                    $results[$name] = 'exists';
                } else {
                    $results[$name] = 'error: ' . $e->getMessage();
                }
            }
        }

        if ($driver === 'sqlite') {
            try {
                $pdo->exec("PRAGMA optimize;");
            } catch (\Throwable $e) {}
        }

        return $results;
    }
}
