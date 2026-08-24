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
    private \App\Repositories\PackageRepository $packages;

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
        CustomerPackageRepository $customerPackages,
        \App\Repositories\PackageRepository $packages
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
        $this->packages = $packages;
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
        $this->packages->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $options = [
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        if (!empty($params['date'])) {
            $options['filters']['date'] = $params['date'];
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $userRole = $_SESSION['role'] ?? '';
        $userId = $_SESSION['user_id'] ?? null;
        
        if ($userRole === 'stylist' && $userId) {
            $options['filters']['user_id'] = $userId;
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
            'packages' => $this->packages->getAll(['active' => 1]),
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
        $isNewPackage = false;
        $newPackageId = null;
        $initialServiceId = null;

        if (!empty($data['service_id'])) {
            if (is_string($data['service_id']) && preg_match('/^pkg_(\d+)_srv_(\d+)$/', $data['service_id'], $matches)) {
                $isNewPackage = true;
                $newPackageId = (int)$matches[1];
                $initialServiceId = (int)$matches[2];
                $data['service_id'] = $initialServiceId;
            } elseif (is_string($data['service_id']) && strpos($data['service_id'], 'cps_') === 0) {
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
                if ($isNewPackage) {
                    $this->packages->setTenantId($tenantId);
                    $package = $this->packages->getById($newPackageId);
                    $data['service_name'] = 'Package: ' . ($package ? $package['name'] : 'Unknown') . ' (First Service: ' . $service['name'] . ')';

                    // Create the customer package since it's a new purchase
                    $expiresAt = null;
                    if (!empty($package['validity_days'])) {
                        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$package['validity_days']} days"));
                    }
                    
                    $this->customerPackages->setTenantId($tenantId);
                    $newCustomerPackage = $this->customerPackages->create([
                        'customer_id' => $data['customer_id'],
                        'package_id' => $newPackageId,
                        'status' => 'active',
                        'expires_at' => $expiresAt
                    ]);
                    $customerPackageId = $newCustomerPackage['id'];
                    
                    // Add all services from the package to the customer's package
                    $initialCpsId = null;
                    if (!empty($package['services'])) {
                        foreach ($package['services'] as $pkgSrv) {
                            $quantity = $pkgSrv['quantity'] ?? 1; // Default to 1 if quantity not specified in package items
                            $this->customerPackages->addService($customerPackageId, $pkgSrv['id'], $quantity);
                        }
                        
                        // We also need to mark the initial service as used.
                        // We can fetch the newly created customer_package_services and increment the used quantity
                        $newPackageServices = $this->customerPackages->getAvailableServicesForCustomer($data['customer_id']);
                        foreach ($newPackageServices as $cps) {
                            if ($cps['customer_package_id'] == $customerPackageId && $cps['service_id'] == $initialServiceId) {
                                $initialCpsId = $cps['customer_package_service_id'];
                                break;
                            }
                        }
                    }
                    
                    if ($initialCpsId) {
                        $cpsId = $initialCpsId; // So it gets incremented below
                        $data['customer_package_service_id'] = $cpsId; // Link the appointment
                    }

                } else {
                    $data['service_name'] = $service['name'];
                    if ($cpsId) {
                        $data['service_name'] .= ' (Package Redemption)';
                    }
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

        $packageServices = [];
        if ($customerId > 0) {
            $this->customerPackages->setTenantId($tenantId);
            $packageServices = $this->customerPackages->getAvailableServicesForCustomer($customerId);
        }

        $this->packages->setTenantId($tenantId);
        $availablePackages = $this->packages->getAll(['active' => 1]);

        $html = '<div x-data="{ bookingMode: \'regular\', selectedPackage: \'\' }">';
        
        // Booking Mode Selection
        $html .= '<div class="mb-4">';
        $html .= '<label class="block text-sm font-medium text-gray-700 mb-2">Service Selection Mode</label>';
        $html .= '<div class="flex space-x-4">';
        $html .= '<label class="inline-flex items-center cursor-pointer"><input type="radio" x-model="bookingMode" @change="selectedPackage = \'\'" value="regular" class="text-salon-accent focus:ring-salon-accent border-gray-300"> <span class="ml-2 text-sm text-gray-700">Regular Service</span></label>';
        
        if (count($packageServices) > 0) {
            $html .= '<label class="inline-flex items-center cursor-pointer"><input type="radio" x-model="bookingMode" @change="selectedPackage = \'\'" value="redeem" class="text-salon-accent focus:ring-salon-accent border-gray-300"> <span class="ml-2 text-sm text-gray-700 font-semibold text-green-700">Redeem Package</span></label>';
        }
        if (count($availablePackages) > 0) {
            $html .= '<label class="inline-flex items-center cursor-pointer"><input type="radio" x-model="bookingMode" @change="selectedPackage = \'\'" value="new_package" class="text-salon-accent focus:ring-salon-accent border-gray-300"> <span class="ml-2 text-sm text-gray-700">Buy Package</span></label>';
        }
        $html .= '</div></div>';

        // Regular Service Dropdown
        $html .= '<div class="mb-4" x-show="bookingMode === \'regular\'" x-cloak>';
        $html .= '<label class="block text-sm font-medium text-gray-700">Service</label>';
        $html .= '<select :name="bookingMode === \'regular\' ? \'service_id\' : \'\'" :required="bookingMode === \'regular\'" hx-get="/web/appointments/stylists" hx-target="#stylist_id" hx-swap="innerHTML" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-salon-accent sm:text-sm">';
        $html .= '<option value="">Select a service...</option>';
        foreach ($regularServices as $service) {
            $html .= '<option value="' . $service['id'] . '">' . htmlspecialchars($service['name']) . '</option>';
        }
        $html .= '</select></div>';

        // Redeem Package Section
        if (count($packageServices) > 0) {
            $redeemPackages = [];
            foreach ($packageServices as $cps) {
                $pkgName = $cps['package_name'];
                if (!isset($redeemPackages[$pkgName])) {
                    $redeemPackages[$pkgName] = [];
                }
                $redeemPackages[$pkgName][] = $cps;
            }

            $html .= '<div class="mb-4 space-y-4 p-3 bg-green-50 border border-green-100 rounded-lg" x-show="bookingMode === \'redeem\'" x-cloak>';
            $html .= '<div><label class="block text-sm font-medium text-gray-700">Select Active Package</label>';
            $html .= '<select x-model="selectedPackage" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-salon-accent sm:text-sm">';
            $html .= '<option value="">Select a package to redeem from...</option>';
            foreach (array_keys($redeemPackages) as $pkgName) {
                $html .= '<option value="' . htmlspecialchars($pkgName) . '">' . htmlspecialchars($pkgName) . '</option>';
            }
            $html .= '</select></div>';

            foreach ($redeemPackages as $pkgName => $services) {
                $escapedPkgName = htmlspecialchars(addslashes($pkgName));
                $html .= '<div x-show="selectedPackage === \'' . $escapedPkgName . '\'">';
                $html .= '<label class="block text-sm font-medium text-gray-700">Service to Redeem</label>';
                $html .= '<select :name="bookingMode === \'redeem\' && selectedPackage === \'' . $escapedPkgName . '\' ? \'service_id\' : \'\'" :required="bookingMode === \'redeem\' && selectedPackage === \'' . $escapedPkgName . '\'" hx-get="/web/appointments/stylists" hx-target="#stylist_id" hx-swap="innerHTML" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-salon-accent sm:text-sm">';
                $html .= '<option value="">Select a service...</option>';
                foreach ($services as $cps) {
                    $remaining = $cps['total_quantity'] - $cps['used_quantity'];
                    if ($remaining > 0) {
                        $html .= '<option value="cps_' . $cps['customer_package_service_id'] . '">' . htmlspecialchars($cps['service_name']) . ' (' . $remaining . ' left)</option>';
                    }
                }
                $html .= '</select></div>';
            }
            $html .= '</div>';
        }

        // Buy New Package Section
        if (count($availablePackages) > 0) {
            $html .= '<div class="mb-4 space-y-4 p-3 bg-blue-50 border border-blue-100 rounded-lg" x-show="bookingMode === \'new_package\'" x-cloak>';
            $html .= '<div><label class="block text-sm font-medium text-gray-700">Select Package to Buy</label>';
            $html .= '<select x-model="selectedPackage" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-salon-accent sm:text-sm">';
            $html .= '<option value="">Select package...</option>';
            foreach ($availablePackages as $package) {
                $html .= '<option value="' . $package['id'] . '">' . htmlspecialchars($package['name']) . ' - QAR ' . number_format($package['price'], 2) . '</option>';
            }
            $html .= '</select></div>';

            foreach ($availablePackages as $package) {
                if (!empty($package['services'])) {
                    $html .= '<div x-show="selectedPackage == \'' . $package['id'] . '\'">';
                    $html .= '<label class="block text-sm font-medium text-gray-700">Select Initial Service</label>';
                    $html .= '<select :name="bookingMode === \'new_package\' && selectedPackage == \'' . $package['id'] . '\' ? \'service_id\' : \'\'" :required="bookingMode === \'new_package\' && selectedPackage == \'' . $package['id'] . '\'" hx-get="/web/appointments/stylists" hx-target="#stylist_id" hx-swap="innerHTML" class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-salon-accent sm:text-sm">';
                    $html .= '<option value="">Select initial service to start with...</option>';
                    foreach ($package['services'] as $ps) {
                        $html .= '<option value="pkg_' . $package['id'] . '_srv_' . $ps['id'] . '">' . htmlspecialchars($ps['name']) . '</option>';
                    }
                    $html .= '</select></div>';
                }
            }
            $html .= '</div>';
        }
        $html .= '</div>'; // End x-data wrapper
        
        // Re-process htmx for the dynamically loaded selects so they can trigger /stylists endpoint
        $html .= '<script>setTimeout(() => { if (typeof htmx !== "undefined") { htmx.process(document.getElementById("service_selection_wrapper")); } }, 50);</script>';

        $response->getBody()->write($html);
        return $response->withStatus(200);
    }

    public function getStylistsForService(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $serviceId = (int)$request->getQueryParams()['service_id'] ?? 0;

        $this->users->setTenantId($tenantId);
        $allUsers = $this->users->getAll();
        
        $html = '<div class="flex space-x-3 overflow-x-auto pb-2" style="scroll-snap-type: x mandatory;">';
        foreach ($allUsers as $user) {
            // Include roles that might act as stylists if needed, or just check specialist areas
            if (empty($serviceId) || in_array((string)$serviceId, $user['specialist_areas'] ?? [], true) || in_array((int)$serviceId, $user['specialist_areas'] ?? [], true)) {
                $img = !empty($user['profile_image']) 
                    ? '<img src="' . htmlspecialchars($user['profile_image']) . '" class="w-10 h-10 rounded-full object-cover mb-1 border border-gray-200">'
                    : '<div class="w-10 h-10 rounded-full bg-gray-200 text-gray-600 font-bold flex items-center justify-center mb-1 text-sm border border-gray-300">' . strtoupper(substr($user['name'], 0, 2)) . '</div>';
                
                $html .= '<label class="flex-shrink-0 w-20 flex flex-col items-center justify-center p-2 rounded-lg cursor-pointer border-2 transition-all border-gray-100 bg-white hover:border-gray-300 has-[:checked]:border-salon-accent has-[:checked]:bg-yellow-50">';
                $html .= '<input type="radio" name="stylist_id" value="' . $user['id'] . '" required class="hidden">';
                $html .= $img;
                $html .= '<span class="text-xs font-medium text-gray-700 text-center truncate w-full">' . htmlspecialchars(explode(' ', $user['name'])[0]) . '</span>';
                $html .= '</label>';
            }
        }
        $html .= '</div>';

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
