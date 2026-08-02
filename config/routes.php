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
        // Dashboard
        $group->get('/dashboard', \App\Web\Controllers\DashboardController::class . ':index');
        
        // Appointments
        $group->get('/appointments', \App\Web\Controllers\AppointmentController::class . ':index');
        $group->post('/appointments', \App\Web\Controllers\AppointmentController::class . ':store');
        
        // POS & Billing
        $group->get('/pos', \App\Web\Controllers\InvoiceController::class . ':pos');
        $group->post('/pos/checkout', \App\Web\Controllers\InvoiceController::class . ':checkout');
        
        // Employees & Services
        $group->get('/employees', \App\Web\Controllers\EmployeeController::class . ':index');
        $group->get('/employees/create', \App\Web\Controllers\EmployeeController::class . ':create');
        $group->post('/employees/create', \App\Web\Controllers\EmployeeController::class . ':store');
        $group->get('/services', \App\Web\Controllers\ServiceController::class . ':index');
        $group->get('/services/create', \App\Web\Controllers\ServiceController::class . ':create');
        $group->post('/services/create', \App\Web\Controllers\ServiceController::class . ':store');
        
        // Customers
        $group->get('/customers', \App\Web\Controllers\CustomerController::class . ':index');
        $group->get('/customers/create', \App\Web\Controllers\CustomerController::class . ':create');
        $group->post('/customers/create', \App\Web\Controllers\CustomerController::class . ':store');
        
        // Profile & Settings
        $group->get('/profile', \App\Web\Controllers\ProfileController::class . ':index');
        $group->get('/settings', \App\Web\Controllers\SettingsController::class . ':index');
        
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
    
    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write("SalonMS API is running. Access /web/dashboard for the web interface.");
        return $response;
    });
};
