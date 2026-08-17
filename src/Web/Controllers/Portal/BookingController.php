<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ServiceRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\UserRepository;
use App\Repositories\TenantSettingRepository;
use App\Repositories\BookingTypeRepository;
use App\Repositories\PackageRepository;
use App\Repositories\CustomerPackageRepository;

class BookingController
{
    private Twig $view;
    private ServiceRepository $serviceRepo;
    private AppointmentRepository $appointmentRepo;
    private UserRepository $userRepo;
    private TenantSettingRepository $settings;
    private BookingTypeRepository $bookingTypeRepo;
    private PackageRepository $packageRepo;
    private CustomerPackageRepository $customerPackageRepo;

    public function __construct(
        Twig $view, 
        ServiceRepository $serviceRepo, 
        AppointmentRepository $appointmentRepo, 
        UserRepository $userRepo,
        TenantSettingRepository $settings,
        BookingTypeRepository $bookingTypeRepo,
        PackageRepository $packageRepo,
        CustomerPackageRepository $customerPackageRepo
    ) {
        $this->view = $view;
        $this->serviceRepo = $serviceRepo;
        $this->appointmentRepo = $appointmentRepo;
        $this->userRepo = $userRepo;
        $this->settings = $settings;
        $this->bookingTypeRepo = $bookingTypeRepo;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
    }

    public function step1(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $this->serviceRepo->setTenantId($tenantId);
        $this->bookingTypeRepo->setTenantId($tenantId);
        
        $servicesRaw = $this->serviceRepo->getAll();
        
        $servicesByCategory = [];
        foreach ($servicesRaw as $service) {
            $cat = $service['category'] ?: 'Uncategorized';
            if (!isset($servicesByCategory[$cat])) {
                $servicesByCategory[$cat] = [];
            }
            $servicesByCategory[$cat][] = $service;
        }
        
        $bookingTypesRaw = $this->bookingTypeRepo->getAll();
        $bookingTypes = array_column($bookingTypesRaw, 'name');
        
        $this->packageRepo->setTenantId($tenantId);
        $packages = $this->packageRepo->getAll(['active' => 1]);
        
        $groupedPackageServices = [];
        $customerId = $request->getAttribute('customer_id');
        if ($customerId) {
            $this->customerPackageRepo->setTenantId($tenantId);
            $availablePackageServices = $this->customerPackageRepo->getAvailableServicesForCustomer($customerId);
            
            foreach ($availablePackageServices as $cps) {
                $cpId = $cps['customer_package_id'];
                if (!isset($groupedPackageServices[$cpId])) {
                    $groupedPackageServices[$cpId] = [
                        'customer_package_id' => $cpId,
                        'package_name' => $cps['package_name'],
                        'expires_at' => $cps['expires_at'],
                        'services' => []
                    ];
                }
                $groupedPackageServices[$cpId]['services'][] = $cps;
            }
        }
        
        $queryParams = $request->getQueryParams();
        $selectedServiceId = isset($queryParams['service_id']) ? $queryParams['service_id'] : null;
        $selectedPackageId = isset($queryParams['package_id']) ? (int)$queryParams['package_id'] : null;

        return $this->view->render($response, 'portal/book.twig', [
            'services_by_category' => $servicesByCategory,
            'packages' => $packages,
            'grouped_package_services' => $groupedPackageServices,
            'booking_types' => $bookingTypes,
            'selected_service_id' => $selectedServiceId,
            'selected_package_id' => $selectedPackageId
        ]);
    }

    public function getEmployeesForService(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $queryParams = $request->getQueryParams();
        $isPackage = false;
        $isRedemption = false;
        if (is_string($queryParams['service_id'] ?? null) && preg_match('/^pkg_(\d+)_srv_(\d+)$/', $queryParams['service_id'], $matches)) {
            $isPackage = true;
            $packageId = (int)$matches[1];
            $serviceId = (int)$matches[2];
        } elseif (is_string($queryParams['service_id'] ?? null) && strpos($queryParams['service_id'], 'pkg_') === 0) {
            $isPackage = true;
            $packageId = (int)str_replace('pkg_', '', $queryParams['service_id']);
            $serviceId = 0; // Or whatever
        } elseif (is_string($queryParams['service_id'] ?? null) && strpos($queryParams['service_id'], 'cps_') === 0) {
            $isRedemption = true;
            $cpsId = (int)str_replace('cps_', '', $queryParams['service_id']);
            $this->customerPackageRepo->setTenantId($tenantId);
            $cps = $this->customerPackageRepo->getCustomerPackageServiceById($cpsId);
            $serviceId = $cps ? (int)$cps['service_id'] : 0;
        } else {
            $serviceId = (int)($queryParams['service_id'] ?? 0);
        }

        $this->userRepo->setTenantId($tenantId);
        $allUsers = $this->userRepo->getAll();
        
        $specialists = [];
        foreach ($allUsers as $user) {
            if ($isPackage && $serviceId === 0) {
                $specialists[] = $user;
            } elseif (in_array((string)$serviceId, $user['specialist_areas'] ?? [], true) || in_array((int)$serviceId, $user['specialist_areas'] ?? [], true)) {
                $specialists[] = $user;
            }
        }

        return $this->view->render($response, 'portal/partials/booking_employees.twig', [
            'employees' => $specialists,
            'service_id' => $queryParams['service_id'] ?? ''
        ]);
    }

    public function getAvailableTimes(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $queryParams = $request->getQueryParams();
        $employeeId = (int)($queryParams['employee_id'] ?? 0);
        $isPackage = false;
        $isRedemption = false;
        
        if (is_string($queryParams['service_id'] ?? null) && preg_match('/^pkg_(\d+)_srv_(\d+)$/', $queryParams['service_id'], $matches)) {
            $isPackage = true;
            $packageId = (int)$matches[1];
            $serviceId = (int)$matches[2];
        } elseif (is_string($queryParams['service_id'] ?? null) && strpos($queryParams['service_id'], 'pkg_') === 0) {
            $isPackage = true;
            $packageId = (int)str_replace('pkg_', '', $queryParams['service_id']);
        } elseif (is_string($queryParams['service_id'] ?? null) && strpos($queryParams['service_id'], 'cps_') === 0) {
            $isRedemption = true;
            $cpsId = (int)str_replace('cps_', '', $queryParams['service_id']);
        } else {
            $serviceId = (int)($queryParams['service_id'] ?? 0);
        }
        $date = $queryParams['date'] ?? date('Y-m-d');

        if ($isPackage) {
            $this->packageRepo->setTenantId($tenantId);
            $service = $this->packageRepo->getById($packageId);
            if (!empty($serviceId)) {
                $this->serviceRepo->setTenantId($tenantId);
                $initialService = $this->serviceRepo->getById($serviceId);
                $durationMinutes = $initialService['duration_minutes'] ?? 60;
            } else {
                $durationMinutes = 60;
            }
        } elseif ($isRedemption) {
            $this->customerPackageRepo->setTenantId($tenantId);
            $cps = $this->customerPackageRepo->getCustomerPackageServiceById($cpsId);
            $durationMinutes = $cps['duration_minutes'] ?? 60;
        } else {
            $this->serviceRepo->setTenantId($tenantId);
            $service = $this->serviceRepo->getById($serviceId);
            $durationMinutes = $service['duration_minutes'] ?? 30;
        }

        $this->appointmentRepo->setTenantId($tenantId);
        // Find existing appointments for this employee on this date
        // Note: AppointmentRepository doesn't have getByDateAndUserId, so we will fetch all and filter
        $allAppointments = $this->appointmentRepo->getAllForTenant();
        
        $bookedSlots = [];
        foreach ($allAppointments as $apt) {
            if ($apt['date'] === $date && (int)$apt['user_id'] === $employeeId && strtolower($apt['status']) !== 'cancelled') {
                $aptStart = strtotime($apt['date'] . ' ' . $apt['time']);
                $aptEnd = strtotime($apt['date'] . ' ' . ($apt['end_time'] ?? date('H:i', strtotime($apt['time'] . ' +1 hour'))));
                $bookedSlots[] = [
                    'start' => $aptStart,
                    'end' => $aptEnd
                ];
            }
        }

        $this->settings->setTenantId($tenantId);
        $openTime = $this->settings->get('open_time', '09:00');
        $closeTime = $this->settings->get('close_time', '17:00');

        // Generate time slots from settings
        $availableTimes = [];
        $startTime = strtotime("$date $openTime:00");
        $endTime = strtotime("$date $closeTime:00");

        for ($time = $startTime; $time < $endTime; $time += 15 * 60) {
            $slotEnd = $time + ($durationMinutes * 60);
            
            $isBooked = false;
            foreach ($bookedSlots as $booked) {
                // Overlap condition: start < booked.end AND end > booked.start
                if ($time < $booked['end'] && $slotEnd > $booked['start']) {
                    $isBooked = true;
                    break;
                }
            }
            if (!$isBooked) {
                $availableTimes[] = date('H:i', $time);
            }
        }

        $bookingType = $queryParams['booking_type'] ?? 'In Salon';

        $this->bookingTypeRepo->setTenantId($tenantId);
        $bookingTypesRaw = $this->bookingTypeRepo->getAll();
        $bookingTypes = array_column($bookingTypesRaw, 'name');

        return $this->view->render($response, 'portal/partials/booking_times.twig', [
            'times' => $availableTimes,
            'date' => $date,
            'booking_type' => $bookingType,
            'booking_types' => $bookingTypes
        ]);
    }

    public function confirm(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        error_log("Booking Confirm POST Data: " . print_r($data, true));
        $tenantId = $request->getAttribute('tenant_id', 1);
        $customerId = $request->getAttribute('customer_id');
        
        $serviceIdParam = $data['service_id'] ?? null;
        $employeeId = $data['employee_id'] ?? null;
        $date = $data['date'] ?? null;
        $time = $data['time'] ?? null;
        $bookingType = $data['booking_type'] ?? 'In Salon';

        if (!$serviceIdParam || !$employeeId || !$date || !$time) {
            $response->getBody()->write('
                <div x-data="{ show: true }" x-show="show" x-transition.duration.500ms x-init="setTimeout(() => show = false, 4000)" class="fixed bottom-6 right-6 bg-red-600 text-white px-6 py-4 rounded-xl shadow-2xl z-50 flex items-center font-sans font-medium" style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 50;">
                    <svg class="w-6 h-6 mr-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Please select a service, a specialist, date, and time.</span>
                </div>
            ');
            return $response->withStatus(200);
        }

        $isPackage = false;
        $isRedemption = false;
        $cpsId = null;
        $initialService = null;
        
        if (is_string($serviceIdParam) && preg_match('/^pkg_(\d+)_srv_(\d+)$/', $serviceIdParam, $matches)) {
            $isPackage = true;
            $packageId = (int)$matches[1];
            $serviceId = (int)$matches[2];
            $this->packageRepo->setTenantId($tenantId);
            $service = $this->packageRepo->getById($packageId);
            
            $this->serviceRepo->setTenantId($tenantId);
            $initialService = $this->serviceRepo->getById($serviceId);
        } elseif (is_string($serviceIdParam) && strpos($serviceIdParam, 'pkg_') === 0) {
            $isPackage = true;
            $packageId = (int)str_replace('pkg_', '', $serviceIdParam);
            $this->packageRepo->setTenantId($tenantId);
            $service = $this->packageRepo->getById($packageId);
            $serviceId = null; // Don't assign a service_id for packages for now
        } elseif (is_string($serviceIdParam) && strpos($serviceIdParam, 'cps_') === 0) {
            $isRedemption = true;
            $cpsId = (int)str_replace('cps_', '', $serviceIdParam);
            $this->customerPackageRepo->setTenantId($tenantId);
            $cps = $this->customerPackageRepo->getCustomerPackageServiceById($cpsId);
            if ($cps) {
                $serviceId = (int)$cps['service_id'];
                $this->serviceRepo->setTenantId($tenantId);
                $service = $this->serviceRepo->getById($serviceId);
                $service['name'] = $service['name'] . ' (Package Redemption)';
            } else {
                $service = null;
            }
        } else {
            $serviceId = (int)$serviceIdParam;
            $this->serviceRepo->setTenantId($tenantId);
            $service = $this->serviceRepo->getById($serviceId);
        }
        
        if (!$service) {
            $response->getBody()->write('<div x-data="{ show: true }" x-show="show" x-transition.duration.500ms x-init="setTimeout(() => show = false, 4000)" class="fixed bottom-6 right-6 bg-red-600 text-white px-6 py-4 rounded-xl shadow-2xl z-50 flex items-center font-sans font-medium" style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 50;">Invalid service selected.</div>');
            return $response->withStatus(200);
        }

        $this->userRepo->setTenantId($tenantId);
        $employee = $this->userRepo->getById((int)$employeeId);

        if (!$employee) {
            $response->getBody()->write('<div x-data="{ show: true }" x-show="show" x-transition.duration.500ms x-init="setTimeout(() => show = false, 4000)" class="fixed bottom-6 right-6 bg-red-600 text-white px-6 py-4 rounded-xl shadow-2xl z-50 flex items-center font-sans font-medium" style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 50;">Invalid specialist selected.</div>');
            return $response->withStatus(200);
        }

        $startDateTimeObj = new \DateTime("$date $time:00");
        $durationMinutes = ($isPackage && $initialService) ? ($initialService['duration_minutes'] ?? 60) : ($isPackage ? 60 : ($service['duration_minutes'] ?? 60));
        $startDateTimeObj->add(new \DateInterval('PT' . $durationMinutes . 'M'));
        $endTime = $startDateTimeObj->format('H:i');

        // Construct service name
        if ($isPackage && $initialService) {
            $serviceName = 'Package: ' . $service['name'] . ' (First Service: ' . $initialService['name'] . ')';
        } elseif ($isPackage) {
            $serviceName = 'Package: ' . $service['name'];
        } else {
            $serviceName = $service['name'];
        }

        try {
            $this->appointmentRepo->setTenantId($tenantId);
            $this->appointmentRepo->create([
                'customer_id' => $customerId,
                'stylist_id' => $employee['id'],
                'service_id' => $serviceId,
                'customer_name' => $_SESSION['customer_name'] ?? 'Guest', 
                'service_name' => $serviceName,
                'stylist_name' => $employee['name'],
                'date' => $date,
                'time' => $time,
                'end_time' => $endTime,
                'booking_type' => $bookingType,
                'customer_package_service_id' => $cpsId
            ]);
            
            if ($isRedemption && $cpsId) {
                $this->customerPackageRepo->incrementUsedQuantity($cpsId);
            }

            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write('
                <div x-data="{ show: true }" x-show="show" x-transition.duration.500ms x-init="setTimeout(() => show = false, 4000)" class="fixed bottom-6 right-6 bg-red-600 text-white px-6 py-4 rounded-xl shadow-2xl z-50 flex items-center font-sans font-medium" style="position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 50;">
                    <svg class="w-6 h-6 mr-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>' . htmlspecialchars($e->getMessage()) . '</span>
                </div>
            ');
            return $response->withStatus(200);
        }
    }
}
