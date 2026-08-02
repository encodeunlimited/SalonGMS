<?php

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

return function (App $app) {
    // ---------------------------------------------------------
    // WEB ROUTES (HTMX + Sessions)
    // ---------------------------------------------------------
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
        $group->get('/services', \App\Web\Controllers\ServiceController::class . ':index');
        
    })/*->add(\App\Middleware\WebSessionAuthMiddleware::class)*/;

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
