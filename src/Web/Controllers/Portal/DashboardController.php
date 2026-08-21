<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AppointmentRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\PackageRepository;
use App\Repositories\CustomerPackageRepository;
use App\Services\LoyaltyService;

class DashboardController
{
    private Twig $view;
    private AppointmentRepository $appointmentRepo;
    private CustomerRepository $customerRepo;
    private LoyaltyService $loyaltyService;
    private PackageRepository $packageRepo;
    private CustomerPackageRepository $customerPackageRepo;

    public function __construct(
        Twig $view, 
        AppointmentRepository $appointmentRepo, 
        CustomerRepository $customerRepo,
        LoyaltyService $loyaltyService,
        PackageRepository $packageRepo,
        CustomerPackageRepository $customerPackageRepo
    ) {
        $this->view = $view;
        $this->appointmentRepo = $appointmentRepo;
        $this->customerRepo = $customerRepo;
        $this->loyaltyService = $loyaltyService;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $customerId = $request->getAttribute('customer_id');
        $tenantId = $request->getAttribute('tenant_id');
        
        $this->appointmentRepo->setTenantId($tenantId);
        $this->customerRepo->setTenantId($tenantId);
        $this->loyaltyService->setTenantId($tenantId);
        $this->packageRepo->setTenantId($tenantId);
        $this->customerPackageRepo->setTenantId($tenantId);

        $customer = $this->customerRepo->getById($customerId);
        $loyaltyTransactions = $this->loyaltyService->getCustomerTransactions($customerId);
        
        // Fetch appointments for this customer
        $appointments = $this->appointmentRepo->getAll(['sort' => 'apt_date', 'dir' => 'DESC']);
        $customerAppointments = array_filter($appointments, function($app) use ($customerId) {
            return $app['customer_id'] == $customerId;
        });

        // Separate into upcoming and past
        $now = new \DateTime();
        $twoMonthsAgo = (clone $now)->modify('-2 months');
        $upcoming = [];
        $past = [];

        foreach ($customerAppointments as &$app) {
            $aptDate = $app['apt_date'] ?? date('Y-m-d');
            $aptTime = $app['apt_time'] ?? '00:00';
            $aptEndTime = $app['apt_end_time'] ?? $aptTime;
            
            $startTime = new \DateTime("$aptDate $aptTime");
            $app['start_time'] = $startTime->format('Y-m-d H:i:s');
            
            $endTime = new \DateTime("$aptDate $aptEndTime");
            $app['end_time'] = $endTime->format('Y-m-d H:i:s');
            
            if ($startTime >= $now && in_array(strtolower($app['status']), ['scheduled', 'pending', 'approved'])) {
                $upcoming[] = $app;
            } else {
                if ($startTime >= $twoMonthsAgo) {
                    $past[] = $app;
                }
            }
        }
        unset($app);

        // Fetch My Packages
        $availablePackageServices = $this->customerPackageRepo->getAvailableServicesForCustomer($customerId);
        $groupedPackages = [];
        foreach ($availablePackageServices as $cps) {
            $cpId = $cps['customer_package_id'];
            if (!isset($groupedPackages[$cpId])) {
                $groupedPackages[$cpId] = [
                    'package_name' => $cps['package_name'],
                    'expires_at' => $cps['expires_at'],
                    'services' => []
                ];
            }
            $groupedPackages[$cpId]['services'][] = [
                'cps_id' => $cps['customer_package_service_id'],
                'service_name' => $cps['service_name'],
                'remaining' => $cps['total_quantity'] - $cps['used_quantity'],
                'total' => $cps['total_quantity']
            ];
        }

        $today = date('m-d');
        $isBirthday = false;
        if (!empty($customer['date_of_birth']) && date('m-d', strtotime($customer['date_of_birth'])) === $today) {
            $isBirthday = true;
        }

        return $this->view->render($response, 'portal/dashboard.twig', [
            'customer' => $customer,
            'upcoming_appointments' => $upcoming,
            'past_appointments' => $past,
            'loyalty_transactions' => $loyaltyTransactions,
            'is_birthday' => $isBirthday,
            'my_packages' => $groupedPackages
        ]);
    }
}
