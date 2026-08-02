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
        $group->get('/appointments', \App\Web\Controllers\AppointmentController::class . ':index');
        $group->post('/appointments', \App\Web\Controllers\AppointmentController::class . ':store');
        
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
