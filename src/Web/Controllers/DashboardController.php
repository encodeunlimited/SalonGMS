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
        
        $role = $request->getAttribute('role') ?? 'stylist';
        $userId = (int)$request->getAttribute('user_id');

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        if ($role === 'admin') {
            $kpi = $this->analytics->getDashboardKPIs();
            $weeklyRevenue = $this->analytics->getWeeklyRevenueData();
            $servicesBreakdown = $this->analytics->getServicesBreakdown();
            
            $todayAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $today], 'limit' => 50])['data'];
            $tomorrowAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $tomorrow], 'limit' => 50])['data'];
            
            $pendingPayments = $this->invoiceRepo->getUnpaidInvoicesWithCustomer();
            
            $customers = $this->customerRepo->getAll();
            $todaysBirthdays = array_filter($customers, function($c) use ($today) {
                if (empty($c['date_of_birth'])) return false;
                return date('m-d', strtotime($c['date_of_birth'])) === date('m-d', strtotime($today));
            });
            
            $appointmentsStatus = $this->analytics->getAppointmentsByStatus();

            return $this->view->render($response, 'dashboard_admin.twig', [
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
                    'services_series' => json_encode($servicesBreakdown['series']),
                    'apt_status_labels' => json_encode($appointmentsStatus['labels']),
                    'apt_status_series' => json_encode($appointmentsStatus['series'])
                ]
            ]);
        } elseif ($role === 'receptionist') {
            $todayAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $today], 'limit' => 50])['data'];
            $tomorrowAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $tomorrow], 'limit' => 50])['data'];
            
            $pendingPayments = $this->invoiceRepo->getUnpaidInvoicesWithCustomer();
            
            $customers = $this->customerRepo->getAll();
            $todaysBirthdays = array_filter($customers, function($c) use ($today) {
                if (empty($c['date_of_birth'])) return false;
                return date('m-d', strtotime($c['date_of_birth'])) === date('m-d', strtotime($today));
            });
            
            $appointmentsStatus = $this->analytics->getAppointmentsByStatus();

            return $this->view->render($response, 'dashboard_receptionist.twig', [
                'title' => 'Front Desk Dashboard',
                'active_menu' => 'dashboard',
                'today_appointments' => $todayAppointments,
                'tomorrow_appointments' => $tomorrowAppointments,
                'pending_payments' => $pendingPayments,
                'todays_birthdays' => $todaysBirthdays,
                'charts' => [
                    'apt_status_labels' => json_encode($appointmentsStatus['labels']),
                    'apt_status_series' => json_encode($appointmentsStatus['series'])
                ]
            ]);
        } else {
            // Stylist dashboard
            $todayAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $today, 'user_id' => $userId], 'limit' => 50])['data'];
            $tomorrowAppointments = $this->appointmentRepo->getPaginatedAppointments(['filters' => ['date' => $tomorrow, 'user_id' => $userId], 'limit' => 50])['data'];
            
            // Calculate my commission for this month
            $myCommission = $this->analytics->getStylistCommissionThisMonth($userId);
            
            $kpi = ['my_commission' => $myCommission];
            
            $commissionTrend = $this->analytics->getStylistCommissionTrend($userId);
            $servicesBreakdown = $this->analytics->getStylistServicesBreakdown($userId);

            return $this->view->render($response, 'dashboard_stylist.twig', [
                'title' => 'Stylist Dashboard',
                'active_menu' => 'dashboard',
                'today_appointments' => $todayAppointments,
                'tomorrow_appointments' => $tomorrowAppointments,
                'kpi' => $kpi,
                'charts' => [
                    'commission_labels' => json_encode($commissionTrend['labels']),
                    'commission_series' => json_encode($commissionTrend['series']),
                    'services_labels' => json_encode($servicesBreakdown['labels']),
                    'services_series' => json_encode($servicesBreakdown['series'])
                ]
            ]);
        }
    }
}
