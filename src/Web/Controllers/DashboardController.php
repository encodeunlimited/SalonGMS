<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        // Dummy KPI Data
        $kpiData = [
            'revenue_today' => 1250.50,
            'appointments_today' => 18,
            'active_stylists' => 4,
            'new_customers' => 3
        ];

        return $this->view->render($response, 'dashboard.twig', [
            'title' => 'Dashboard',
            'active_menu' => 'dashboard',
            'kpi' => $kpiData
        ]);
    }
}
