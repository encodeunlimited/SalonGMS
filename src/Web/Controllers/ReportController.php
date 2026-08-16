<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ReportRepository;

class ReportController
{
    private Twig $view;
    private ReportRepository $reports;

    public function __construct(Twig $view, ReportRepository $reports)
    {
        $this->view = $view;
        $this->reports = $reports;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);

        $params = $request->getQueryParams();
        
        // Default to current month if no dates provided
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $data = $this->getReportData($startDate, $endDate);

        // If it's an HTMX request, we might just want to render the partials
        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/dashboard_data.twig', $data);
        }

        return $this->view->render($response, 'reports/index.twig', array_merge([
            'title' => 'Reports',
            'active_menu' => 'reports',
            'start_date' => $startDate,
            'end_date' => $endDate
        ], $data));
    }

    private function getReportData(string $startDate, string $endDate): array
    {
        $revenueOverTime = $this->reports->getRevenueOverTime($startDate, $endDate);
        $revenueByPaymentMethod = $this->reports->getRevenueByPaymentMethod($startDate, $endDate);
        $topServices = $this->reports->getTopServices($startDate, $endDate);
        $stylistPerformance = $this->reports->getStylistPerformance($startDate, $endDate);
        $topCustomers = $this->reports->getTopCustomers($startDate, $endDate);
        $appointmentsSummary = $this->reports->getAppointmentsSummary($startDate, $endDate);

        $totalRevenue = array_sum(array_column($revenueOverTime, 'revenue'));
        $totalAppointments = array_sum(array_column($appointmentsSummary, 'count'));

        return [
            'total_revenue' => $totalRevenue,
            'total_appointments' => $totalAppointments,
            'revenue_over_time' => $revenueOverTime,
            'revenue_by_payment_method' => $revenueByPaymentMethod,
            'top_services' => $topServices,
            'stylist_performance' => $stylistPerformance,
            'top_customers' => $topCustomers,
            'appointments_summary' => $appointmentsSummary
        ];
    }
}
