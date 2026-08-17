<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AppointmentRepository;
use App\Services\AppointmentService;
use App\Services\InvoiceService;
use Exception;
use App\Repositories\CustomerRepository;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;
use App\Repositories\TenantSettingRepository;
use App\Repositories\CustomerPackageRepository;
use App\Services\PdfService;
use App\Services\WhatsAppService;

class AppointmentController
{
    private Twig $view;
    private AppointmentRepository $appointments;
    private AppointmentService $appointmentService;
    private CustomerRepository $customers;
    private ServiceRepository $services;
    private UserRepository $users;
    private TenantSettingRepository $settings;
    private \App\Repositories\BookingTypeRepository $bookingTypes;
    private InvoiceService $invoiceService;
    private PdfService $pdfService;
    private WhatsAppService $whatsappService;
    private CustomerPackageRepository $customerPackages;

    public function __construct(
        Twig $view, 
        AppointmentRepository $appointments, 
        AppointmentService $appointmentService,
        CustomerRepository $customers,
        ServiceRepository $services,
        UserRepository $users,
        TenantSettingRepository $settings,
        \App\Repositories\BookingTypeRepository $bookingTypes,
        InvoiceService $invoiceService,
        PdfService $pdfService,
        WhatsAppService $whatsappService,
        CustomerPackageRepository $customerPackages
    ) {
        $this->view = $view;
        $this->appointments = $appointments;
        $this->appointmentService = $appointmentService;
        $this->customers = $customers;
        $this->services = $services;
        $this->users = $users;
        $this->settings = $settings;
        $this->bookingTypes = $bookingTypes;
        $this->invoiceService = $invoiceService;
        $this->pdfService = $pdfService;
        $this->whatsappService = $whatsappService;
        $this->customerPackages = $customerPackages;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->appointments->setTenantId($tenantId);
        $this->customers->setTenantId($tenantId);
        $this->services->setTenantId($tenantId);
        $this->users->setTenantId($tenantId);
        $this->settings->setTenantId($tenantId);
        $this->bookingTypes->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $options = [
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        if (!empty($params['date'])) {
            $options['filters']['date'] = $params['date'];
        }

        $paginated = $this->appointments->getPaginatedAppointments($options);
        $customersList = $this->customers->getAll();
        $servicesList = $this->services->getAll();
        $usersList = $this->users->getAll();
        
        $openTime = $this->settings->get('open_time', '09:00');
        $closeTime = $this->settings->get('close_time', '17:00');
        $openHour = (int) explode(':', $openTime)[0];
        $closeHour = (int) explode(':', $closeTime)[0];

        $bookingTypesRaw = $this->bookingTypes->getAll();
        $bookingTypes = array_column($bookingTypesRaw, 'name');

        return $this->view->render($response, 'appointments/index.twig', [
            'active_menu' => 'appointments',
            'appointments' => $paginated['data'],
            'pagination' => $paginated,
            'customers' => $customersList,
            'services' => $servicesList,
            'users' => $usersList,
            'open_hour' => $openHour,
            'close_hour' => $closeHour,
            'booking_types' => $bookingTypes,
            'base_url' => $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '')
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
        
        $cpsId = null;
        if (!empty($data['service_id'])) {
            if (is_string($data['service_id']) && strpos($data['service_id'], 'cps_') === 0) {
                $cpsId = (int)str_replace('cps_', '', $data['service_id']);
                $this->customerPackages->setTenantId($tenantId);
                $cps = $this->customerPackages->getCustomerPackageServiceById($cpsId);
                if ($cps) {
                    $data['service_id'] = $cps['service_id'];
                    $data['customer_package_service_id'] = $cpsId;
                } else {
                    $data['service_id'] = 0; // Invalid
                }
            }

            $service = $this->services->getById((int)$data['service_id']);
            if ($service) {
                $data['service_name'] = $service['name'];
                if ($cpsId) {
                    $data['service_name'] .= ' (Package Redemption)';
                }
                
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
            
            if ($cpsId) {
                $this->customerPackages->incrementUsedQuantity($cpsId);
            }

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

    public function getServicesForCustomer(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$request->getQueryParams()['customer_id'] ?? 0;

        $this->services->setTenantId($tenantId);
        $regularServices = $this->services->getAll();

        $html = '<option value="">Select a service...</option>';

        if ($customerId > 0) {
            $this->customerPackages->setTenantId($tenantId);
            $packageServices = $this->customerPackages->getAvailableServicesForCustomer($customerId);
            
            if (count($packageServices) > 0) {
                $html .= '<optgroup label="Available Package Services">';
                foreach ($packageServices as $cps) {
                    $remaining = $cps['total_quantity'] - $cps['used_quantity'];
                    $html .= '<option value="cps_' . $cps['customer_package_service_id'] . '">' . htmlspecialchars($cps['service_name']) . ' (from ' . htmlspecialchars($cps['package_name']) . ' - ' . $remaining . ' left)</option>';
                }
                $html .= '</optgroup>';
            }
        }
        
        $html .= '<optgroup label="Regular Services">';
        foreach ($regularServices as $service) {
            $html .= '<option value="' . $service['id'] . '">' . htmlspecialchars($service['name']) . '</option>';
        }
        $html .= '</optgroup>';

        $response->getBody()->write($html);
        return $response->withStatus(200);
    }

    public function getStylistsForService(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $serviceId = (int)$request->getQueryParams()['service_id'] ?? 0;

        $this->users->setTenantId($tenantId);
        $allUsers = $this->users->getAll();
        
        $html = '<option value="">Select a stylist...</option>';
        foreach ($allUsers as $user) {
            // Include roles that might act as stylists if needed, or just check specialist areas
            if (in_array((string)$serviceId, $user['specialist_areas'] ?? [], true) || in_array((int)$serviceId, $user['specialist_areas'] ?? [], true)) {
                $html .= '<option value="' . $user['id'] . '">' . htmlspecialchars($user['name']) . '</option>';
            }
        }

        $response->getBody()->write($html);
        return $response->withStatus(200);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $appointmentId = (int)$args['id'];
        
        $data = $request->getParsedBody();
        $newStatus = $data['status'] ?? 'pending';
        
        $this->appointments->setTenantId($tenantId);
        $this->appointments->updateStatus($appointmentId, $newStatus);
        
        $appointment = $this->appointments->getAppointmentDetails($appointmentId);

        if ($newStatus === 'paid' && empty($appointment['invoice_id'])) {
            try {
                $this->invoiceService->setTenantId($tenantId);
                
                $checkoutData = [
                    'customer_id' => $appointment['customer_id'] ?? null,
                    'employee_id' => $appointment['user_id'] ?? null,
                    'payment_method' => 'cash',
                    'items' => [
                        [
                            'name' => $appointment['service'],
                            'quantity' => 1,
                            'price' => $appointment['service_price'] ?? 0
                        ]
                    ]
                ];
                
                $invoice = $this->invoiceService->checkout($checkoutData);
                if (!empty($invoice['id'])) {
                    $this->appointments->setInvoiceId($appointmentId, $invoice['id']);
                    $appointment['invoice_id'] = $invoice['id']; // Update for current render
                    
                    // Generate PDF and send via WhatsApp
                    if (!empty($appointment['customer_phone'])) {
                        $fullInvoice = $this->invoiceService->getInvoicePublic($invoice['id']);
                        $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
                        $pdfUrl = $this->pdfService->generateInvoicePdf($fullInvoice, $baseUrl);
                        $this->whatsappService->sendInvoice(
                            $appointment['customer_phone'], 
                            $pdfUrl, 
                            $appointment['customer_name'] ?? 'Customer'
                        );
                    }
                }
            } catch (Exception $e) {
                // Log or handle error if needed, but don't break the flow
                error_log("Failed to process payment/whatsapp: " . $e->getMessage());
            }
        }

        // If the request comes from the calendar tooltip, we can just return a success header
        // that triggers a calendar refresh, but since we are modifying the DOM we might just return empty 
        // with an HTMX trigger to refetch the calendar.
        
        // For the list item, we want to return the updated row HTML.
        // We can differentiate by a custom header or just return the list item and 
        // add a trigger to refresh the calendar too.

        // Actually, we'll return the updated list item. If it's called from the tooltip, 
        // the list item HTML will just swap out the tooltip button or do nothing if targeting out-of-band.
        // But the best way is to return the updated list_item and fire an event to refresh the calendar.

        $response->getBody()->write($this->view->fetch('appointments/list_item.twig', [
            'appointment' => $appointment
        ]));
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', 'refresh-calendar');
    }
}
