<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function (App $app) {
    // ---------------------------------------------------------
    // WEB ROUTES (HTMX + Sessions)
    // ---------------------------------------------------------
    $app->get('/web/login', \App\Web\Controllers\AuthController::class . ':showLogin');
    $app->post('/web/login', \App\Web\Controllers\AuthController::class . ':processLogin');
    $app->post('/web/logout', \App\Web\Controllers\AuthController::class . ':logout');
    $app->get('/web/logout', \App\Web\Controllers\AuthController::class . ':logout'); // Fallback for simple links
    
    // Public Invoice View
    $app->get('/web/invoices/download/{id}', \App\Web\Controllers\InvoiceController::class . ':download');
    
    $app->group('/web', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', [\App\Web\Controllers\DashboardController::class, 'index']);
        
        // Settings
        $group->get('/settings', [\App\Web\Controllers\SettingsController::class, 'index']);
        $group->post('/settings', [\App\Web\Controllers\SettingsController::class, 'store']);
        
        $group->post('/settings/booking-types', [\App\Web\Controllers\SettingsController::class, 'storeBookingType']);
        $group->put('/settings/booking-types/{id}', [\App\Web\Controllers\SettingsController::class, 'updateBookingType']);
        $group->delete('/settings/booking-types/{id}', [\App\Web\Controllers\SettingsController::class, 'deleteBookingType']);

        $group->post('/settings/payment-types', [\App\Web\Controllers\SettingsController::class, 'storePaymentType']);
        $group->put('/settings/payment-types/{id}', [\App\Web\Controllers\SettingsController::class, 'updatePaymentType']);
        $group->delete('/settings/payment-types/{id}', [\App\Web\Controllers\SettingsController::class, 'deletePaymentType']);

        $group->post('/settings/service-categories', [\App\Web\Controllers\SettingsController::class, 'storeServiceCategory']);
        $group->put('/settings/service-categories/{id}', [\App\Web\Controllers\SettingsController::class, 'updateServiceCategory']);
        $group->delete('/settings/service-categories/{id}', [\App\Web\Controllers\SettingsController::class, 'deleteServiceCategory']);
        
        // Appointments
        $group->get('/appointments', \App\Web\Controllers\AppointmentController::class . ':index');
        $group->get('/appointments/services', \App\Web\Controllers\AppointmentController::class . ':getServicesForCustomer');
        $group->get('/appointments/stylists', \App\Web\Controllers\AppointmentController::class . ':getStylistsForService');
        $group->post('/appointments', \App\Web\Controllers\AppointmentController::class . ':store');
        $group->put('/appointments/{id}/status', \App\Web\Controllers\AppointmentController::class . ':updateStatus');
        
        // POS & Billing
        $group->get('/pos', \App\Web\Controllers\InvoiceController::class . ':pos');
        $group->post('/pos/open', \App\Web\Controllers\InvoiceController::class . ':openRegister');
        $group->post('/pos/close', \App\Web\Controllers\InvoiceController::class . ':closeRegister');
        $group->post('/pos/checkout', \App\Web\Controllers\InvoiceController::class . ':checkout');
        $group->post('/invoices/{id}/pay', \App\Web\Controllers\InvoiceController::class . ':payRemaining');
        $group->post('/customers/{id}/invoices', [\App\Web\Controllers\CustomerController::class, 'createInvoice']);
        $group->post('/customers/{id}/bulk-invoice', [\App\Web\Controllers\InvoiceController::class, 'bulkInvoice']);
        $group->post('/customers/{id}/pay-all-invoices', [\App\Web\Controllers\InvoiceController::class, 'payAllInvoices']);
        $group->post('/customers/{id}/pay-selected-invoices', [\App\Web\Controllers\InvoiceController::class, 'paySelectedInvoices']);
        $group->get('/receipts/bulk', [\App\Web\Controllers\InvoiceController::class, 'printBulkReceipt']);
        
        // Employees
        $group->get('/employees', \App\Web\Controllers\EmployeeController::class . ':index');
        $group->get('/employees/create', \App\Web\Controllers\EmployeeController::class . ':create');
        $group->post('/employees/create', \App\Web\Controllers\EmployeeController::class . ':store');
        $group->get('/employees/{id}/edit', \App\Web\Controllers\EmployeeController::class . ':edit');
        $group->post('/employees/{id}/edit', \App\Web\Controllers\EmployeeController::class . ':update');
        $group->delete('/employees/{id}', \App\Web\Controllers\EmployeeController::class . ':delete');
        $group->get('/employees/{id}/profile', \App\Web\Controllers\EmployeeController::class . ':profile');
        
        // Services
        $group->get('/services', \App\Web\Controllers\ServiceController::class . ':index');
        $group->get('/services/create', \App\Web\Controllers\ServiceController::class . ':create');
        $group->post('/services/create', \App\Web\Controllers\ServiceController::class . ':store');
        $group->get('/services/{id}', \App\Web\Controllers\ServiceController::class . ':show');
        $group->get('/services/{id}/edit', \App\Web\Controllers\ServiceController::class . ':edit');
        $group->post('/services/{id}/edit', \App\Web\Controllers\ServiceController::class . ':update');
        $group->delete('/services/{id}', \App\Web\Controllers\ServiceController::class . ':delete');
        
        // Packages
        $group->get('/packages', \App\Web\Controllers\PackageController::class . ':index');
        $group->get('/packages/create', \App\Web\Controllers\PackageController::class . ':create');
        $group->post('/packages/create', \App\Web\Controllers\PackageController::class . ':store');
        $group->get('/packages/{id}/edit', \App\Web\Controllers\PackageController::class . ':edit');
        $group->post('/packages/{id}/edit', \App\Web\Controllers\PackageController::class . ':update');
        $group->delete('/packages/{id}', \App\Web\Controllers\PackageController::class . ':delete');
        
        // Inventory
        $group->get('/inventory', \App\Web\Controllers\InventoryController::class . ':index');
        $group->get('/inventory/create', \App\Web\Controllers\InventoryController::class . ':create');
        $group->post('/inventory/create', \App\Web\Controllers\InventoryController::class . ':store');
        $group->get('/inventory/{id}/edit', \App\Web\Controllers\InventoryController::class . ':edit');
        $group->post('/inventory/{id}/edit', \App\Web\Controllers\InventoryController::class . ':update');
        $group->delete('/inventory/{id}', \App\Web\Controllers\InventoryController::class . ':delete');
        $group->get('/inventory/batch-issue', \App\Web\Controllers\InventoryController::class . ':batchIssueForm');
        $group->post('/inventory/batch-issue', \App\Web\Controllers\InventoryController::class . ':processBatchIssue');
        $group->get('/inventory/batch-grn', \App\Web\Controllers\InventoryController::class . ':batchGrnForm');
        $group->post('/inventory/batch-grn', \App\Web\Controllers\InventoryController::class . ':processBatchGrn');
        
        // Customers
        $group->get('/customers', \App\Web\Controllers\CustomerController::class . ':index');
        $group->get('/customers/create', \App\Web\Controllers\CustomerController::class . ':create');
        $group->post('/customers/create', \App\Web\Controllers\CustomerController::class . ':store');
        $group->post('/api/customers', \App\Web\Controllers\CustomerController::class . ':apiStore');
        $group->get('/customers/{id}/edit', \App\Web\Controllers\CustomerController::class . ':edit');
        $group->post('/customers/{id}/edit', \App\Web\Controllers\CustomerController::class . ':update');
        $group->delete('/customers/{id}', \App\Web\Controllers\CustomerController::class . ':delete');
        $group->get('/customers/{id}/profile', \App\Web\Controllers\CustomerController::class . ':profile');
        $group->post('/customers/{id}/redeem-package-service', \App\Web\Controllers\CustomerController::class . ':redeemPackageService');
        $group->get('/api/customers/{id}/available-redemptions', \App\Web\Controllers\CustomerController::class . ':getAvailableRedemptions');
        
        // Profile
        $group->get('/profile', \App\Web\Controllers\ProfileController::class . ':index');

        // Reports
        $group->get('/reports', \App\Web\Controllers\ReportController::class . ':index');
        $group->get('/reports/stylist-performance', \App\Web\Controllers\ReportController::class . ':stylistPerformance');
        $group->get('/reports/daily-eod', \App\Web\Controllers\ReportController::class . ':dailyEod');
        $group->get('/reports/register-shifts', \App\Web\Controllers\ReportController::class . ':registerShifts');
        $group->get('/reports/payment-channels', \App\Web\Controllers\ReportController::class . ':paymentChannels');
        $group->get('/reports/inventory-issues', \App\Web\Controllers\ReportController::class . ':inventoryIssues');
        $group->get('/reports/sales', \App\Web\Controllers\ReportController::class . ':salesSummary');
        $group->get('/reports/stylists', \App\Web\Controllers\ReportController::class . ':stylistPerformance');
        $group->get('/reports/payment-channels', \App\Web\Controllers\ReportController::class . ':paymentChannels');
        $group->get('/reports/eod', \App\Web\Controllers\ReportController::class . ':dailyEod');
        $group->get('/reports/shifts', \App\Web\Controllers\ReportController::class . ':registerShifts');
        
        // Expenses
        $group->get('/expenses', \App\Web\Controllers\ExpenseController::class . ':index');
        $group->post('/expenses', \App\Web\Controllers\ExpenseController::class . ':store');
        $group->get('/expenses/{id}/edit', \App\Web\Controllers\ExpenseController::class . ':edit');
        $group->post('/expenses/{id}/edit', \App\Web\Controllers\ExpenseController::class . ':update');
        $group->post('/expenses/{id}/delete', \App\Web\Controllers\ExpenseController::class . ':delete');
        
        // Notifications
        $group->get('/notifications/dropdown', \App\Web\Controllers\NotificationController::class . ':getDropdown');
        $group->get('/notifications/badge', \App\Web\Controllers\NotificationController::class . ':getBadge');
        $group->post('/notifications/{id}/read', \App\Web\Controllers\NotificationController::class . ':markAsRead');
        $group->post('/notifications/read-all', \App\Web\Controllers\NotificationController::class . ':markAllAsRead');

        // Birthdays
        $group->get('/birthdays', \App\Web\Controllers\BirthdayController::class . ':index');
        
    })->add(\App\Middleware\WebSessionAuthMiddleware::class);

    // ---------------------------------------------------------
    // API ROUTES (Flutter App + JWT)
    // ---------------------------------------------------------
    $app->group('/api/v1', function (RouteCollectorProxy $group) {
        // Dashboard KPIs
        $group->get('/dashboard', \App\Api\Controllers\DashboardController::class . ':index');
        
    });//->add(\App\Middleware\JwtAuthMiddleware::class);

    // ---------------------------------------------------------
    // INTERNAL BACKGROUND JOBS (Cron Triggered)
    // ---------------------------------------------------------
    $app->group('/internal', function (RouteCollectorProxy $group) {
        $group->get('/process-queue', \App\Internal\Controllers\JobQueueController::class . ':process');
    });//->add(\App\Middleware\InternalCronAuthMiddleware::class);
    
    // ---------------------------------------------------------
    // CUSTOMER PORTAL
    // ---------------------------------------------------------
    
    $app->get('/', function (Request $request, Response $response) use ($app) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $view = $app->getContainer()->get(\Slim\Views\Twig::class);
        
        if (isset($_SESSION['customer_id'])) {
            $view->getEnvironment()->addGlobal('auth_customer', [
                'id' => $_SESSION['customer_id'],
                'name' => $_SESSION['customer_name'] ?? 'Customer',
                'tenant_id' => $_SESSION['tenant_id'] ?? 1,
                'profile_image' => $_SESSION['customer_profile_image'] ?? null
            ]);
        }
        
        $serviceRepo = $app->getContainer()->get(\App\Repositories\ServiceRepository::class);
        
        // Use tenant ID 1 for public portal by default
        $serviceRepo->setTenantId(1);
        $servicesRaw = $serviceRepo->getAll();

        $servicesByCategory = [];
        foreach ($servicesRaw as $service) {
            $cat = $service['category'] ?: 'Uncategorized';
            if (!isset($servicesByCategory[$cat])) {
                $servicesByCategory[$cat] = [];
            }
            $servicesByCategory[$cat][] = $service;
        }

        $packageRepo = $app->getContainer()->get(\App\Repositories\PackageRepository::class);
        $packageRepo->setTenantId(1);
        $packages = $packageRepo->getAll(['active' => 1]);
        
        return $view->render($response, 'portal/home.twig', [
            'services_by_category' => $servicesByCategory,
            'packages' => $packages
        ]);
    });

    $app->get('/portal/login', \App\Web\Controllers\Portal\AuthController::class . ':showLogin');
    $app->post('/portal/login', \App\Web\Controllers\Portal\AuthController::class . ':processLogin');
    $app->get('/portal/register', \App\Web\Controllers\Portal\AuthController::class . ':showRegister');
    $app->post('/portal/register', \App\Web\Controllers\Portal\AuthController::class . ':processRegister');
    $app->get('/portal/logout', \App\Web\Controllers\Portal\AuthController::class . ':logout');

    $app->get('/portal/book', \App\Web\Controllers\Portal\BookingController::class . ':step1');
    $app->get('/portal/book/employees', \App\Web\Controllers\Portal\BookingController::class . ':getEmployeesForService');
    $app->get('/portal/book/times', \App\Web\Controllers\Portal\BookingController::class . ':getAvailableTimes');
    $app->get('/portal/book/lookup-customer', \App\Web\Controllers\Portal\BookingController::class . ':lookupCustomer');
    $app->post('/portal/book/confirm', \App\Web\Controllers\Portal\BookingController::class . ':confirm');

    $app->group('/portal', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', \App\Web\Controllers\Portal\DashboardController::class . ':index');
        $group->get('/profile', \App\Web\Controllers\Portal\ProfileController::class . ':index');
        $group->post('/profile', \App\Web\Controllers\Portal\ProfileController::class . ':update');
        $group->post('/rating', \App\Web\Controllers\Portal\DashboardController::class . ':submitRating');
    })->add(\App\Middleware\CustomerSessionAuthMiddleware::class);
};
