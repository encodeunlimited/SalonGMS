<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\CustomerRepository;
use App\Repositories\AppointmentRepository;
use App\Repositories\InvoiceRepository;
use App\Services\LoyaltyService;
use App\Repositories\PackageRepository;
use App\Repositories\CustomerPackageRepository;

class CustomerController
{
    private Twig $view;
    private CustomerRepository $customers;
    private AppointmentRepository $appointments;
    private InvoiceRepository $invoices;
    private LoyaltyService $loyaltyService;
    private ?PackageRepository $packages;
    private ?CustomerPackageRepository $customerPackages;

    public function __construct(
        Twig $view, 
        CustomerRepository $customers,
        AppointmentRepository $appointments,
        InvoiceRepository $invoices,
        LoyaltyService $loyaltyService,
        ?PackageRepository $packages = null,
        ?CustomerPackageRepository $customerPackages = null
    ) {
        $this->view = $view;
        $this->customers = $customers;
        $this->appointments = $appointments;
        $this->invoices = $invoices;
        $this->loyaltyService = $loyaltyService;
        $this->packages = $packages;
        $this->customerPackages = $customerPackages;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'id',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        $paginated = $this->customers->getPaginated($options);

        return $this->view->render($response, 'customers/index.twig', [
            'title' => 'Customers',
            'active_menu' => 'customers',
            'customers' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir']
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'customers/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $profileImagePath = null;

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/profiles';
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $profileImagePath = '/uploads/profiles/' . $filename;
        }

        $customer = $this->customers->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'profile_image' => $profileImagePath
        ]);

        $rowHtml = $this->view->fetch('customers/row.twig', ['customer' => $customer]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="beforeend:#customers-table-body" id=', $rowHtml);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . $rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Customer added successfully!']
                        ]));
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $customerId = (int) $args['id'];
        $customer = $this->customers->getById($customerId);
        
        if (!$customer) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('customers/modal.twig', ['customer' => $customer]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $customerId = (int) $args['id'];
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'notes' => $data['notes'] ?? null
        ];

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/profiles';
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $updateData['profile_image'] = '/uploads/profiles/' . $filename;
        }

        $customer = $this->customers->update($customerId, $updateData);

        $rowHtml = $this->view->fetch('customers/row.twig', ['customer' => $customer]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#customer-row-' . $customerId . '" id=', $rowHtml);
        
        $response->getBody()->write($rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Customer updated successfully!']
                        ]));
    }

    public function apiStore(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $data = json_decode((string)$request->getBody(), true);
        
        $customer = $this->customers->create([
            'name' => $data['name'] ?? 'Unknown',
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'profile_image' => null
        ]);

        $response->getBody()->write(json_encode($customer));
        return $response->withHeader('Content-Type', 'application/json');
    }
    public function delete(Request $request, Response $response, array $args): Response
    {
        if ($request->getAttribute('role') === 'cashier') return $response->withStatus(403);
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }
        
        $tenantId = $request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $customerId = (int) $args['id'];
        
        try {
            $this->customers->delete($customerId);
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Customer deleted successfully!']
                            ]));
        } catch (\Exception $e) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error deleting customer: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }

    public function profile(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        
        $this->customers->setTenantId($tenantId);
        $this->appointments->setTenantId($tenantId);
        $this->invoices->setTenantId($tenantId);
        $this->loyaltyService->setTenantId($tenantId);
        
        $customer = $this->customers->getById($customerId);
        if (!$customer) {
            return $response->withStatus(404);
        }
        
        $appointments = $this->appointments->getByCustomerId($customerId);
        $unbilledAppointments = $this->appointments->getUnbilledDoneAppointments($customerId);
        
        // Fetch packages and their remaining services
        $activePackages = [];
        if ($this->packages && $this->customerPackages) {
            $this->packages->setTenantId($tenantId);
            $this->customerPackages->setTenantId($tenantId);
            
            $packagesList = $this->packages->getAll(['active' => 1]);
            $packagesByName = [];
            foreach ($packagesList as $pkg) {
                $packagesByName[$pkg['name']] = $pkg;
            }
            
            $availableServices = $this->customerPackages->getAvailableServicesForCustomer($customerId);
            $packagesMap = [];
            foreach ($availableServices as $srv) {
                $cpId = $srv['customer_package_id'];
                if (!isset($packagesMap[$cpId])) {
                    $packagesMap[$cpId] = [
                        'id' => $cpId,
                        'name' => $srv['package_name'],
                        'expires_at' => $srv['expires_at'],
                        'services' => []
                    ];
                }
                $packagesMap[$cpId]['services'][] = $srv;
            }
            $activePackages = array_values($packagesMap);
            
            // Fix unbilled appointments price if it's a package
            foreach ($unbilledAppointments as &$apt) {
                if (strpos($apt['service'], '(Package Redemption)') !== false) {
                    $apt['service_price'] = 0.00;
                } elseif (strpos($apt['service'], 'Package: ') === 0) {
                    $packageName = preg_replace('/^Package: (.*?) \(First Service: .*\)$/', '$1', $apt['service']);
                    $packageName = str_replace('Package: ', '', $packageName);
                    
                    if (isset($packagesByName[$packageName])) {
                        $apt['service_price'] = $packagesByName[$packageName]['price'];
                    }
                }
            }
            unset($apt);
        }
        
        $invoices = $this->invoices->getByCustomerId($customerId);
        $loyaltyTransactions = $this->loyaltyService->getCustomerTransactions($customerId);
        
        $totalSpent = 0;
        $pendingAmount = 0;
        
        foreach ($invoices as $invoice) {
            if ($invoice['status'] === 'paid') {
                $totalSpent += (float)$invoice['total_amount'];
            } elseif ($invoice['status'] === 'unpaid') {
                $pendingAmount += (float)$invoice['total_amount'];
            }
        }
        
        $paginatedInvoices = array_slice($invoices, 0, 10);
        $invoicesPagination = [
            'page' => 1, 'limit' => 10, 'total' => count($invoices), 'total_pages' => ceil(count($invoices) / 10)
        ];

        $paginatedAppointments = array_slice($appointments, 0, 10);
        $appointmentsPagination = [
            'page' => 1, 'limit' => 10, 'total' => count($appointments), 'total_pages' => ceil(count($appointments) / 10)
        ];

        $paginatedLoyalty = array_slice($loyaltyTransactions, 0, 10);
        $loyaltyPagination = [
            'page' => 1, 'limit' => 10, 'total' => count($loyaltyTransactions), 'total_pages' => ceil(count($loyaltyTransactions) / 10)
        ];

        return $this->view->render($response, 'customers/profile.twig', [
            'title' => 'Customer Profile',
            'active_menu' => 'customers',
            'customer' => $customer,
            'appointments' => $paginatedAppointments,
            'appointments_pagination' => $appointmentsPagination,
            'unbilled_appointments' => $unbilledAppointments,
            'active_packages' => $activePackages,
            'invoices' => $paginatedInvoices,
            'invoices_pagination' => $invoicesPagination,
            'loyalty_transactions' => $paginatedLoyalty,
            'loyalty_pagination' => $loyaltyPagination,
            'base_url' => $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : ''),
            'stats' => [
                'total_appointments' => count($appointments),
                'total_spent' => $totalSpent,
                'pending_amount' => $pendingAmount
            ]
        ]);
    }
    public function redeemPackageService(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $data = (array)$request->getParsedBody();
        
        $cpsId = (int)($data['customer_package_service_id'] ?? 0);
        $serviceId = (int)($data['service_id'] ?? 0);
        $serviceName = $data['service_name'] ?? 'Unknown Service';
        $packageName = $data['package_name'] ?? 'Unknown Package';
        
        if ($cpsId > 0 && $this->customerPackages) {
            $this->customerPackages->setTenantId($tenantId);
            $this->appointments->setTenantId($tenantId);
            $this->customers->setTenantId($tenantId);
            
            $customer = $this->customers->getById($customerId);
            
            // Decrement remaining by incrementing used_quantity
            if ($this->customerPackages->incrementUsedQuantity($cpsId)) {
                // Create a "done" appointment for this redemption
                $this->appointments->create([
                    'customer_id' => $customerId,
                    'customer_name' => $customer['name'] ?? 'Unknown',
                    'stylist_id' => null,
                    'stylist_name' => 'Walk-in Stylist',
                    'service_id' => $serviceId,
                    'service_name' => 'Package: ' . $packageName . ' (Package Redemption) - ' . $serviceName,
                    'date' => date('Y-m-d'),
                    'time' => date('H:i'),
                    'status' => 'done',
                    'booking_type' => 'Walk-in',
                    'customer_package_service_id' => $cpsId
                ]);
            }
        }
        
        return $response->withHeader('Location', '/web/customers/' . $customerId . '/profile')->withStatus(302);
    }
    public function getAvailableRedemptions(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        
        $activePackages = [];
        if ($this->customerPackages) {
            $this->customerPackages->setTenantId($tenantId);
            $availableServices = $this->customerPackages->getAvailableServicesForCustomer($customerId);
            
            $packagesMap = [];
            foreach ($availableServices as $srv) {
                $cpId = $srv['customer_package_id'];
                if (!isset($packagesMap[$cpId])) {
                    $packagesMap[$cpId] = [
                        'id' => $cpId,
                        'name' => $srv['package_name'],
                        'expires_at' => $srv['expires_at'],
                        'services' => []
                    ];
                }
                $packagesMap[$cpId]['services'][] = $srv;
            }
            $activePackages = array_values($packagesMap);
        }
        
        $response->getBody()->write(json_encode(['success' => true, 'packages' => $activePackages]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    public function appointmentsTable(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $this->appointments->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'date',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => ['customer_id' => $customerId]
        ];

        $paginated = $this->appointments->getPaginatedAppointments($options);

        return $this->view->render($response, 'customers/partials/appointments_table.twig', [
            'appointments' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir'],
            'customer_id' => $customerId
        ]);
    }

    public function invoicesTable(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $this->invoices->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $sort = $params['sort'] ?? 'created_at';
        $dir = $params['dir'] ?? 'desc';
        $page = (int)($params['page'] ?? 1);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $invoices = $this->invoices->getHistoryPaginated($search, $sort, $dir, $limit, $offset, $customerId);
        $total = $this->invoices->getHistoryCount($search, $customerId);
        $totalPages = ceil($total / $limit);

        $pagination = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ];

        return $this->view->render($response, 'customers/partials/invoices_table.twig', [
            'invoices' => $invoices,
            'pagination' => $pagination,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'customer_id' => $customerId
        ]);
    }

    public function loyaltyTable(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $this->loyaltyService->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $sort = $params['sort'] ?? 'created_at';
        $dir = $params['dir'] ?? 'desc';
        $page = (int)($params['page'] ?? 1);
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $transactions = $this->loyaltyService->getCustomerTransactionsPaginated($customerId, $search, $sort, $dir, $limit, $offset);
        $total = $this->loyaltyService->getCustomerTransactionsCount($customerId, $search);
        $totalPages = ceil($total / $limit);

        $pagination = [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages
        ];

        return $this->view->render($response, 'customers/partials/loyalty_table.twig', [
            'loyalty_transactions' => $transactions,
            'pagination' => $pagination,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'customer_id' => $customerId
        ]);
    }
}
