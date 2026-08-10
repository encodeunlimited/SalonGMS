<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AnalyticsRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\InvoiceRepository;

class DashboardController
{
    private Twig $view;
    private AnalyticsRepository $analytics;
    private AppointmentRepository $appointmentRepo;
    private InvoiceRepository $invoiceRepo;

    public function __construct(Twig $view, AnalyticsRepository $analytics, AppointmentRepository $appointmentRepo, InvoiceRepository $invoiceRepo)
    {
        $this->view = $view;
        $this->analytics = $analytics;
        $this->appointmentRepo = $appointmentRepo;
        $this->invoiceRepo = $invoiceRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->analytics->setTenantId($tenantId);
        $this->appointmentRepo->setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        
        $kpi = $this->analytics->getDashboardKPIs();
        $weeklyRevenue = $this->analytics->getWeeklyRevenueData();
        $servicesBreakdown = $this->analytics->getServicesBreakdown();

        // Fetch appointments
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $todayAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $today], 'limit' => 50])['data'];
        $tomorrowAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $tomorrow], 'limit' => 50])['data'];
        
        // Fetch unpaid invoices
        $pendingPayments = $this->invoiceRepo->getUnpaidInvoicesWithCustomer();

        return $this->view->render($response, 'dashboard.twig', [
            'title' => 'Dashboard',
            'active_menu' => 'dashboard',
            'kpi' => $kpi,
            'today_appointments' => $todayAppointments,
            'tomorrow_appointments' => $tomorrowAppointments,
            'pending_payments' => $pendingPayments,
            'charts' => [
                'weekly_revenue' => json_encode($weeklyRevenue),
                'services_labels' => json_encode($servicesBreakdown['labels']),
                'services_series' => json_encode($servicesBreakdown['series'])
            ]
        ]);
    }
}
