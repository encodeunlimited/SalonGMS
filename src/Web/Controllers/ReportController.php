<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ReportRepository;
use App\Repositories\ExpenseRepository;

class ReportController
{
    private Twig $view;
    private ReportRepository $reports;
    private ExpenseRepository $expenseRepo;
    private \App\Repositories\PosSessionRepository $posSessionRepo;

    public function __construct(
        Twig $view, 
        ReportRepository $reports, 
        ExpenseRepository $expenseRepo,
        \App\Repositories\PosSessionRepository $posSessionRepo
    ) {
        $this->view = $view;
        $this->reports = $reports;
        $this->expenseRepo = $expenseRepo;
        $this->posSessionRepo = $posSessionRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);

        $params = $request->getQueryParams();
        
        // Default to current month if no dates provided
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $this->expenseRepo->setTenantId($tenantId);
        $totalExpenses = $this->expenseRepo->getTotalExpensesByDateRange($startDate, $endDate);

        $data = $this->getReportData($startDate, $endDate);
        $data['total_expenses'] = $totalExpenses;

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

    public function salesSummary(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);
        $this->expenseRepo->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $data = $this->getReportData($startDate, $endDate);
        $data['total_expenses'] = $this->expenseRepo->getTotalExpensesByDateRange($startDate, $endDate);
        
        $data['title'] = 'Sales Summary';
        $data['active_menu'] = 'reports';
        $data['start_date'] = $startDate;
        $data['end_date'] = $endDate;

        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/sales_summary_data.twig', $data);
        }

        return $this->view->render($response, 'reports/sales_summary.twig', $data);
    }

    public function stylistPerformance(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $data = $this->getReportData($startDate, $endDate);
        $data['title'] = 'Stylist Performance';
        $data['active_menu'] = 'reports';
        $data['start_date'] = $startDate;
        $data['end_date'] = $endDate;

        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/stylist_performance_data.twig', $data);
        }

        return $this->view->render($response, 'reports/stylist_performance.twig', $data);
    }

    public function dailyEod(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);
        $this->expenseRepo->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $date = $params['date'] ?? date('Y-m-d');
        
        // EOD is single day
        $data = $this->getReportData($date, $date);
        $data['total_expenses'] = $this->expenseRepo->getTotalExpensesByDateRange($date, $date);
        
        $data['title'] = 'End of Day Report';
        $data['active_menu'] = 'reports';
        $data['target_date'] = $date;

        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/daily_eod_data.twig', $data);
        }

        return $this->view->render($response, 'reports/daily_eod.twig', $data);
    }

    public function registerShifts(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->posSessionRepo->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $shifts = $this->posSessionRepo->getSessions($tenantId, $startDate, $endDate);

        $data = [
            'title' => 'Register Shifts',
            'active_menu' => 'reports',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'shifts' => $shifts
        ];

        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/register_shifts_data.twig', $data);
        }

        return $this->view->render($response, 'reports/register_shifts.twig', $data);
    }

    public function paymentChannels(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->reports->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $startDate = $params['start_date'] ?? date('Y-m-01');
        $endDate = $params['end_date'] ?? date('Y-m-t');

        $revenueByPaymentMethod = $this->reports->getRevenueByPaymentMethod($startDate, $endDate);
        
        $data = [
            'title' => 'Payment Channel Report',
            'active_menu' => 'reports',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'revenue_by_payment_method' => $revenueByPaymentMethod,
        ];

        if ($request->getHeaderLine('HX-Request') === 'true' && !empty($params['partial'])) {
            return $this->view->render($response, 'reports/partials/payment_channels_data.twig', $data);
        }

        return $this->view->render($response, 'reports/payment_channels.twig', $data);
    }
}
