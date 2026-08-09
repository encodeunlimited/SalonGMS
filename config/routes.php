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
        $group->get('/appointments/stylists', \App\Web\Controllers\AppointmentController::class . ':getStylistsForService');
        $group->post('/appointments', \App\Web\Controllers\AppointmentController::class . ':store');
        
        // POS & Billing
        $group->get('/pos', \App\Web\Controllers\InvoiceController::class . ':pos');
        $group->post('/pos/checkout', \App\Web\Controllers\InvoiceController::class . ':checkout');
        
        // Employees
        $group->get('/employees', \App\Web\Controllers\EmployeeController::class . ':index');
        $group->get('/employees/create', \App\Web\Controllers\EmployeeController::class . ':create');
        $group->post('/employees/create', \App\Web\Controllers\EmployeeController::class . ':store');
        $group->get('/employees/{id}/edit', \App\Web\Controllers\EmployeeController::class . ':edit');
        $group->post('/employees/{id}/edit', \App\Web\Controllers\EmployeeController::class . ':update');
        $group->delete('/employees/{id}', \App\Web\Controllers\EmployeeController::class . ':delete');
        
        // Services
        $group->get('/services', \App\Web\Controllers\ServiceController::class . ':index');
        $group->get('/services/create', \App\Web\Controllers\ServiceController::class . ':create');
        $group->post('/services/create', \App\Web\Controllers\ServiceController::class . ':store');
        $group->get('/services/{id}', \App\Web\Controllers\ServiceController::class . ':show');
        $group->get('/services/{id}/edit', \App\Web\Controllers\ServiceController::class . ':edit');
        $group->post('/services/{id}/edit', \App\Web\Controllers\ServiceController::class . ':update');
        $group->delete('/services/{id}', \App\Web\Controllers\ServiceController::class . ':delete');
        
        // Inventory
        $group->get('/inventory', \App\Web\Controllers\InventoryController::class . ':index');
        $group->get('/inventory/create', \App\Web\Controllers\InventoryController::class . ':create');
        $group->post('/inventory/create', \App\Web\Controllers\InventoryController::class . ':store');
        $group->get('/inventory/{id}/edit', \App\Web\Controllers\InventoryController::class . ':edit');
        $group->post('/inventory/{id}/edit', \App\Web\Controllers\InventoryController::class . ':update');
        $group->delete('/inventory/{id}', \App\Web\Controllers\InventoryController::class . ':delete');
        $group->get('/inventory/{id}/issue', \App\Web\Controllers\InventoryController::class . ':issueForm');
        $group->post('/inventory/{id}/issue', \App\Web\Controllers\InventoryController::class . ':issue');
        
        // Customers
        $group->get('/customers', \App\Web\Controllers\CustomerController::class . ':index');
        $group->get('/customers/create', \App\Web\Controllers\CustomerController::class . ':create');
        $group->post('/customers/create', \App\Web\Controllers\CustomerController::class . ':store');
        $group->post('/api/customers', \App\Web\Controllers\CustomerController::class . ':apiStore');
        $group->get('/customers/{id}/edit', \App\Web\Controllers\CustomerController::class . ':edit');
        $group->post('/customers/{id}/edit', \App\Web\Controllers\CustomerController::class . ':update');
        $group->delete('/customers/{id}', \App\Web\Controllers\CustomerController::class . ':delete');
        $group->get('/customers/{id}/profile', \App\Web\Controllers\CustomerController::class . ':profile');
        
        // Profile
        $group->get('/profile', \App\Web\Controllers\ProfileController::class . ':index');
        
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
        $view = $app->getContainer()->get(\Slim\Views\Twig::class);
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
        
        return $view->render($response, 'portal/home.twig', [
            'services_by_category' => $servicesByCategory
        ]);
    });

    $app->get('/portal/login', \App\Web\Controllers\Portal\AuthController::class . ':showLogin');
    $app->post('/portal/login', \App\Web\Controllers\Portal\AuthController::class . ':processLogin');
    $app->get('/portal/register', \App\Web\Controllers\Portal\AuthController::class . ':showRegister');
    $app->post('/portal/register', \App\Web\Controllers\Portal\AuthController::class . ':processRegister');
    $app->get('/portal/logout', \App\Web\Controllers\Portal\AuthController::class . ':logout');

    $app->group('/portal', function (RouteCollectorProxy $group) {
        $group->get('/dashboard', \App\Web\Controllers\Portal\DashboardController::class . ':index');
        $group->get('/book', \App\Web\Controllers\Portal\BookingController::class . ':step1');
        $group->get('/book/employees', \App\Web\Controllers\Portal\BookingController::class . ':getEmployeesForService');
        $group->get('/book/times', \App\Web\Controllers\Portal\BookingController::class . ':getAvailableTimes');
        $group->post('/book/confirm', \App\Web\Controllers\Portal\BookingController::class . ':confirm');
    })->add(\App\Middleware\CustomerSessionAuthMiddleware::class);
};
