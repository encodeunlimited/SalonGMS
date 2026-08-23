<?php

namespace App\Web\Controllers\Portal;

use App\Repositories\RatingRepository;
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
    private RatingRepository $ratingRepo;

    public function __construct(
        Twig $view, 
        AppointmentRepository $appointmentRepo, 
        CustomerRepository $customerRepo,
        LoyaltyService $loyaltyService,
        PackageRepository $packageRepo,
        CustomerPackageRepository $customerPackageRepo,
        RatingRepository $ratingRepo
    ) {
        $this->view = $view;
        $this->appointmentRepo = $appointmentRepo;
        $this->customerRepo = $customerRepo;
        $this->loyaltyService = $loyaltyService;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
        $this->ratingRepo = $ratingRepo;
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
        $customerAppointments = $this->appointmentRepo->getByCustomerId($customerId);

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

    public function submitRating(Request $request, Response $response): Response
    {
        $session = $request->getAttribute('session');
        if (!$session || !isset($session['customer_id'])) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Not authenticated']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $tenantId = $session['tenant_id'] ?? 1;
        $customerId = $session['customer_id'];

        $data = $request->getParsedBody();
        $rating = (int)($data['rating'] ?? 0);
        $comment = trim($data['comment'] ?? '');

        if ($rating < 1 || $rating > 5) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid rating']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $this->ratingRepo->setTenantId($tenantId);
        $this->ratingRepo->createRating($customerId, $rating, $comment);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
