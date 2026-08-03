<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AppointmentRepository;
use App\Services\AppointmentService;
use Exception;
use App\Repositories\CustomerRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;

class AppointmentController
{
    private Twig $view;
    private AppointmentRepository $appointments;
    private AppointmentService $appointmentService;
    private CustomerRepository $customers;
    private ServiceRepository $services;
    private UserRepository $users;

    public function __construct(
        Twig $view, 
        AppointmentRepository $appointments, 
        AppointmentService $appointmentService,
        CustomerRepository $customers,
        ServiceRepository $services,
        UserRepository $users
    ) {
        $this->view = $view;
        $this->appointments = $appointments;
        $this->appointmentService = $appointmentService;
        $this->customers = $customers;
        $this->services = $services;
        $this->users = $users;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->appointments->setTenantId($tenantId);
        $this->customers->setTenantId($tenantId);
        $this->services->setTenantId($tenantId);
        $this->users->setTenantId($tenantId);

        $appointments = $this->appointments->getAllForTenant();
        $customersList = $this->customers->getAll();
        $servicesList = $this->services->getAll();
        $stylistsList = $this->users->getAll(); // In real app, might filter by role

        return $this->view->render($response, 'appointments/index.twig', [
            'title' => 'Appointments',
            'active_menu' => 'appointments',
            'appointments' => $appointments,
            'customers' => $customersList,
            'services' => $servicesList,
            'stylists' => $stylistsList
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->appointmentService->setTenantId($tenantId);
        $this->customers->setTenantId($tenantId);
        $this->services->setTenantId($tenantId);
        $this->users->setTenantId($tenantId);
        
        // Look up names from IDs
        if (!empty($data['customer_id'])) {
            $customer = $this->customers->getById((int)$data['customer_id']);
            if ($customer) $data['customer_name'] = $customer['name'];
        }
        
        if (!empty($data['service_id'])) {
            $service = $this->services->getById((int)$data['service_id']);
            if ($service) {
                $data['service_name'] = $service['name'];
                
                // Calculate end time based on duration
                $date = $data['date'] ?? date('Y-m-d');
                $time = $data['time'] ?? '12:00';
                $startDateTimeObj = new \DateTime("$date $time:00");
                $startDateTimeObj->add(new \DateInterval('PT' . ($service['duration_minutes'] ?? 60) . 'M'));
                $data['end_time'] = $startDateTimeObj->format('H:i');
            }
        }
        
        if (!empty($data['stylist_id'])) {
            $stylist = $this->users->getById((int)$data['stylist_id']);
            if ($stylist) $data['stylist_name'] = $stylist['name'];
        }

        // For the service validation
        if (isset($data['stylist_name'])) {
            $data['stylist'] = $data['stylist_name']; // Backwards compatibility for service validation
        }
        
        try {
            $newAppointment = $this->appointmentService->createAppointment($data);
            
            // Clear any previous error messages out of band, and append the new row
            $response->getBody()->write('
                <div id="form-messages" class="mb-4" hx-swap-oob="true"></div>
            ');
            return $this->view->render($response, 'appointments/list_item.twig', [
                'appointment' => $newAppointment
            ]);
        } catch (Exception $e) {
            // Return the error message out of band so HTMX updates the modal, without swapping the table row
            $response->getBody()->write('
                <div id="form-messages" class="mb-4" hx-swap-oob="true">
                    <div class="p-3 text-sm text-red-800 rounded-lg bg-red-50 border border-red-300" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200); // 200 required for HTMX standard swap
        }
    }

    public function getStylistsForService(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $serviceId = (int)$request->getQueryParams()['service_id'] ?? 0;

        $this->users->setTenantId($tenantId);
        $allUsers = $this->users->getAll();
        
        $options = '<option value="">Select a stylist...</option>';
        foreach ($allUsers as $user) {
            // Include roles that might act as stylists if needed, or just check specialist areas
            if (in_array((string)$serviceId, $user['specialist_areas'] ?? [], true) || in_array((int)$serviceId, $user['specialist_areas'] ?? [], true)) {
                $options .= '<option value="' . $user['id'] . '">' . htmlspecialchars($user['name']) . '</option>';
            }
        }

        $response->getBody()->write($options);
        return $response->withStatus(200);
    }
}
