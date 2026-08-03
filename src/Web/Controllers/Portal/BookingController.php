<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ServiceRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\UserRepository;
use App\Repositories\TenantSettingRepository;

class BookingController
{
    private Twig $view;
    private ServiceRepository $serviceRepo;
    private AppointmentRepository $appointmentRepo;
    private UserRepository $userRepo;
    private TenantSettingRepository $settings;

    public function __construct(
        Twig $view, 
        ServiceRepository $serviceRepo, 
        AppointmentRepository $appointmentRepo, 
        UserRepository $userRepo,
        TenantSettingRepository $settings
    ) {
        $this->view = $view;
        $this->serviceRepo = $serviceRepo;
        $this->appointmentRepo = $appointmentRepo;
        $this->userRepo = $userRepo;
        $this->settings = $settings;
    }

    public function step1(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $this->serviceRepo->setTenantId($tenantId);
        
        $services = $this->serviceRepo->getAll();
        
        $queryParams = $request->getQueryParams();
        $selectedServiceId = isset($queryParams['service_id']) ? (int)$queryParams['service_id'] : null;

        return $this->view->render($response, 'portal/book.twig', [
            'services' => $services,
            'selected_service_id' => $selectedServiceId
        ]);
    }

    public function getEmployeesForService(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $queryParams = $request->getQueryParams();
        $serviceId = (int)($queryParams['service_id'] ?? 0);

        $this->userRepo->setTenantId($tenantId);
        $allUsers = $this->userRepo->getAll();
        
        $specialists = [];
        foreach ($allUsers as $user) {
            if (in_array((string)$serviceId, $user['specialist_areas'] ?? [], true) || in_array((int)$serviceId, $user['specialist_areas'] ?? [], true)) {
                $specialists[] = $user;
            }
        }

        return $this->view->render($response, 'portal/partials/booking_employees.twig', [
            'employees' => $specialists,
            'service_id' => $serviceId
        ]);
    }

    public function getAvailableTimes(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id', 1);
        $queryParams = $request->getQueryParams();
        $employeeId = (int)($queryParams['employee_id'] ?? 0);
        $serviceId = (int)($queryParams['service_id'] ?? 0);
        $date = $queryParams['date'] ?? date('Y-m-d');

        $this->serviceRepo->setTenantId($tenantId);
        $service = $this->serviceRepo->getById($serviceId);
        $durationMinutes = $service['duration_minutes'] ?? 30;

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

        return $this->view->render($response, 'portal/partials/booking_times.twig', [
            'times' => $availableTimes,
            'date' => $date,
            'booking_type' => $bookingType
        ]);
    }

    public function confirm(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        error_log("Booking Confirm POST Data: " . print_r($data, true));
        $tenantId = $request->getAttribute('tenant_id', 1);
        $customerId = $request->getAttribute('customer_id');
        
        $serviceId = $data['service_id'] ?? null;
        $employeeId = $data['employee_id'] ?? null;
        $date = $data['date'] ?? null;
        $time = $data['time'] ?? null;
        $bookingType = $data['booking_type'] ?? 'In Salon';

        if (!$serviceId || !$employeeId || !$date || !$time) {
            $response->getBody()->write('
                <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">
                    Please select a service, a specialist, date, and time.
                </div>
            ');
            return $response->withStatus(400);
        }

        $this->serviceRepo->setTenantId($tenantId);
        $service = $this->serviceRepo->getById((int)$serviceId);
        
        if (!$service) {
            $response->getBody()->write('<div class="text-red-500">Invalid service selected.</div>');
            return $response->withStatus(400);
        }

        $this->userRepo->setTenantId($tenantId);
        $employee = $this->userRepo->getById((int)$employeeId);

        if (!$employee) {
            $response->getBody()->write('<div class="text-red-500">Invalid specialist selected.</div>');
            return $response->withStatus(400);
        }

        $startDateTimeObj = new \DateTime("$date $time:00");
        $startDateTimeObj->add(new \DateInterval('PT' . ($service['duration_minutes'] ?? 60) . 'M'));
        $endTime = $startDateTimeObj->format('H:i');

        try {
            $this->appointmentRepo->setTenantId($tenantId);
            $this->appointmentRepo->create([
                'customer_id' => $customerId,
                'stylist_id' => $employee['id'],
                'service_id' => $service['id'],
                'customer_name' => $_SESSION['customer_name'] ?? 'Guest', 
                'service_name' => $service['name'],
                'stylist_name' => $employee['name'],
                'date' => $date,
                'time' => $time,
                'end_time' => $endTime,
                'booking_type' => $bookingType
            ]);

            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write('
                <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200">
                    ' . htmlspecialchars($e->getMessage()) . '
                </div>
            ');
            return $response->withStatus(500);
        }
    }
}
