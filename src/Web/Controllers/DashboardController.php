<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AnalyticsRepository;

class DashboardController
{
    private Twig $view;
    private AnalyticsRepository $analytics;

    public function __construct(Twig $view, AnalyticsRepository $analytics)
    {
        $this->view = $view;
        $this->analytics = $analytics;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->analytics->setTenantId($tenantId);
        
        $kpi = $this->analytics->getDashboardKPIs();
        $weeklyRevenue = $this->analytics->getWeeklyRevenueData();
        $servicesBreakdown = $this->analytics->getServicesBreakdown();

        return $this->view->render($response, 'dashboard.twig', [
            'title' => 'Dashboard',
            'active_menu' => 'dashboard',
            'kpi' => $kpi,
            'charts' => [
                'weekly_revenue' => json_encode($weeklyRevenue),
                'services_labels' => json_encode($servicesBreakdown['labels']),
                'services_series' => json_encode($servicesBreakdown['series'])
            ]
        ]);
    }
}
