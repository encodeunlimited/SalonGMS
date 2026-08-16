<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AnalyticsRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\CustomerRepository;

class DashboardController
{
    private Twig $view;
    private AnalyticsRepository $analytics;
    private AppointmentRepository $appointmentRepo;
    private InvoiceRepository $invoiceRepo;
    private CustomerRepository $customerRepo;

    public function __construct(
        Twig $view, 
        AnalyticsRepository $analytics, 
        AppointmentRepository $appointmentRepo, 
        InvoiceRepository $invoiceRepo,
        CustomerRepository $customerRepo
    ) {
        $this->view = $view;
        $this->analytics = $analytics;
        $this->appointmentRepo = $appointmentRepo;
        $this->invoiceRepo = $invoiceRepo;
        $this->customerRepo = $customerRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->analytics->setTenantId($tenantId);
        $this->appointmentRepo->setTenantId($tenantId);
        $this->invoiceRepo->setTenantId($tenantId);
        $this->customerRepo->setTenantId($tenantId);
        
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

        // Fetch today's birthdays
        $customers = $this->customerRepo->getAll();
        $todaysBirthdays = array_filter($customers, function($c) use ($today) {
            if (empty($c['date_of_birth'])) return false;
            return date('m-d', strtotime($c['date_of_birth'])) === date('m-d', strtotime($today));
        });

        return $this->view->render($response, 'dashboard.twig', [
            'title' => 'Dashboard',
            'active_menu' => 'dashboard',
            'kpi' => $kpi,
            'today_appointments' => $todayAppointments,
            'tomorrow_appointments' => $tomorrowAppointments,
            'pending_payments' => $pendingPayments,
            'todays_birthdays' => $todaysBirthdays,
            'charts' => [
                'weekly_revenue_labels' => json_encode($weeklyRevenue['labels']),
                'weekly_revenue' => json_encode($weeklyRevenue['series']),
                'services_labels' => json_encode($servicesBreakdown['labels']),
                'services_series' => json_encode($servicesBreakdown['series'])
            ]
        ]);
    }
}
