<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\InvoiceService;
use App\Repositories\ServiceRepository;
use App\Repositories\TenantSettingRepository;
use App\Repositories\PaymentTypeRepository;
use App\Repositories\UserRepository;
use App\Services\PdfService;
use App\Services\WhatsAppService;
use Exception;

use App\Repositories\AppointmentRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\PackageRepository;

class InvoiceController
{
    private Twig $view;
    private InvoiceService $invoiceService;
    private ServiceRepository $serviceRepo;
    private TenantSettingRepository $settingsRepo;
    private PaymentTypeRepository $paymentTypeRepo;
    private UserRepository $userRepo;
    private AppointmentRepository $appointmentRepo;
    private PdfService $pdfService;
    private WhatsAppService $whatsappService;
    private CustomerRepository $customerRepo;
    private PackageRepository $packageRepo;
    private \App\Repositories\CustomerPackageRepository $customerPackageRepo;

    public function __construct(
        Twig $view, 
        InvoiceService $invoiceService, 
        ServiceRepository $serviceRepo, 
        TenantSettingRepository $settingsRepo, 
        PaymentTypeRepository $paymentTypeRepo,
        UserRepository $userRepo,
        AppointmentRepository $appointmentRepo,
        PdfService $pdfService,
        WhatsAppService $whatsappService,
        CustomerRepository $customerRepo,
        PackageRepository $packageRepo,
        \App\Repositories\CustomerPackageRepository $customerPackageRepo
    ) {
        $this->view = $view;
        $this->invoiceService = $invoiceService;
        $this->serviceRepo = $serviceRepo;
        $this->settingsRepo = $settingsRepo;
        $this->paymentTypeRepo = $paymentTypeRepo;
        $this->userRepo = $userRepo;
        $this->appointmentRepo = $appointmentRepo;
        $this->pdfService = $pdfService;
        $this->whatsappService = $whatsappService;
        $this->customerRepo = $customerRepo;
        $this->packageRepo = $packageRepo;
        $this->customerPackageRepo = $customerPackageRepo;
    }

    public function pos(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->serviceRepo->setTenantId($tenantId);
        $this->settingsRepo->setTenantId($tenantId);
        $this->paymentTypeRepo->setTenantId($tenantId);
        
        $servicesRaw = $this->serviceRepo->getAll();
        
        $servicesByCategory = [];
        foreach ($servicesRaw as $service) {
            $cat = $service['category'] ?: 'Uncategorized';
            if (!isset($servicesByCategory[$cat])) {
                $servicesByCategory[$cat] = [];
            }
            $servicesByCategory[$cat][] = $service;
        }
        
        $paymentTypesRaw = $this->paymentTypeRepo->getAll();
        $paymentTypes = array_column($paymentTypesRaw, 'name');

        $this->userRepo->setTenantId($tenantId);
        $employees = $this->userRepo->getAll(['filters' => ['role' => 'stylist']]);

        $this->customerRepo->setTenantId($tenantId);
        $customers = $this->customerRepo->getAll();

        $this->packageRepo->setTenantId($tenantId);
        $packages = $this->packageRepo->getAll();

        $appointmentId = (int)($request->getQueryParams()['appointment_id'] ?? 0);
        $appointmentToCheckout = null;
        if ($appointmentId > 0) {
            $this->appointmentRepo->setTenantId($tenantId);
            $appointmentToCheckout = $this->appointmentRepo->getAppointmentDetails($appointmentId);
            
            if ($appointmentToCheckout) {
                $appointmentToCheckout['item_type'] = 'service';
                $appointmentToCheckout['item_id'] = $appointmentToCheckout['service_id'] ?? 0;
                $appointmentToCheckout['item_name'] = $appointmentToCheckout['service'];
                $appointmentToCheckout['item_price'] = $appointmentToCheckout['service_price'] ?? 0;
                
                if (strpos($appointmentToCheckout['service'], 'Package: ') === 0 && strpos($appointmentToCheckout['service'], '(First Service: ') !== false) {
                    $packageName = preg_replace('/^Package: (.*?) \(First Service: .*\)$/', '$1', $appointmentToCheckout['service']);
                    foreach ($packages as $pkg) {
                        if ($pkg['name'] === $packageName) {
                            $appointmentToCheckout['item_type'] = 'package';
                            $appointmentToCheckout['item_id'] = $pkg['id'];
                            $appointmentToCheckout['item_name'] = $pkg['name'];
                            $appointmentToCheckout['item_price'] = $pkg['price'];
                            break;
                        }
                    }
                } elseif (strpos($appointmentToCheckout['service'], 'Package: ') === 0) {
                    $packageName = str_replace('Package: ', '', $appointmentToCheckout['service']);
                    foreach ($packages as $pkg) {
                        if ($pkg['name'] === $packageName) {
                            $appointmentToCheckout['item_type'] = 'package';
                            $appointmentToCheckout['item_id'] = $pkg['id'];
                            $appointmentToCheckout['item_name'] = $pkg['name'];
                            $appointmentToCheckout['item_price'] = $pkg['price'];
                            break;
                        }
                    }
                } elseif (strpos($appointmentToCheckout['service'], '(Package Redemption)') !== false) {
                    $appointmentToCheckout['item_price'] = 0.00;
                }
            }
        }

        $settings = $this->settingsRepo->getAll();
        $loyaltyPointsPerCurrency = (int)($settings['loyalty_points_per_currency'] ?? 10);
        $loyaltyCurrencyPerPoint = (float)($settings['loyalty_currency_per_point'] ?? 0.001);

        return $this->view->render($response, 'pos/index.twig', [
            'title' => 'Point of Sale',
            'active_menu' => 'pos',
            'services_by_category' => $servicesByCategory,
            'packages' => $packages,
            'payment_types' => $paymentTypes,
            'employees' => $employees,
            'customers' => $customers,
            'appointment_to_checkout' => $appointmentToCheckout,
            'loyalty_points_per_currency' => $loyaltyPointsPerCurrency,
            'loyalty_currency_per_point' => $loyaltyCurrencyPerPoint,
            'hide_sidebar' => true
        ]);
    }

    public function checkout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->invoiceService->setTenantId($tenantId);
        
        try {
            // Process package redemptions for ALL checkout methods
            if (!empty($data['items'])) {
                $this->customerPackageRepo->setTenantId($tenantId);
                foreach ($data['items'] as $item) {
                    if (($item['type'] ?? '') === 'redemption') {
                        $qty = (int)($item['quantity'] ?? 1);
                        for ($i = 0; $i < $qty; $i++) {
                            $this->customerPackageRepo->incrementUsedQuantity((int)$item['id']);
                        }
                    }
                }
            }

            // If Credit is selected, create unbilled appointments and do NOT generate an invoice immediately.
            // This applies to both regular services and packages (which will be provisioned here).
            if (($data['payment_method'] ?? '') === 'Credit') {
                $customerId = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
                if (!$customerId) {
                    return $response->withHeader('HX-Trigger', json_encode([
                        'show-toast' => ['type' => 'error', 'message' => 'Customer must be selected for Credit payment.']
                    ]))->withStatus(200);
                }

                $this->appointmentRepo->setTenantId($tenantId);
                $appId = !empty($data['appointment_id']) ? (int)$data['appointment_id'] : null;
                
                if ($appId) {
                    $this->appointmentRepo->updateStatus($appId, 'done');
                }
                
                if (!empty($data['items'])) {
                    $this->customerRepo->setTenantId($tenantId);
                    $customer = $this->customerRepo->getById($customerId);
                    $employeeId = !empty($data['employee_id']) ? (int)$data['employee_id'] : null;
                    $stylistName = 'Unknown';
                    
                    if ($employeeId) {
                        $this->userRepo->setTenantId($tenantId);
                        $employee = $this->userRepo->getById($employeeId);
                        if ($employee) $stylistName = $employee['name'];
                    }

                    $skipFirst = $appId ? true : false;
                    
                    foreach ($data['items'] as $index => $item) {
                        if ($skipFirst && $index === 0) {
                            continue;
                        }
                        
                        $qty = (int)($item['quantity'] ?? 1);
                        $itemName = $item['name'] ?? 'Service';
                        
                        if (($item['type'] ?? '') === 'package') {
                            $pkgId = !empty($item['item_id']) ? (int)$item['item_id'] : (!empty($item['id']) ? (int)$item['id'] : null);
                            
                            // Provision the package
                            if (!$appId && $pkgId) {
                                $this->packageRepo->setTenantId($tenantId);
                                $package = $this->packageRepo->getById($pkgId);
                                
                                if ($package && !empty($package['services'])) {
                                    $expiresAt = null;
                                    if (!empty($package['validity_months'])) {
                                        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$package['validity_months']} months"));
                                    }
                                    
                                    for ($p = 0; $p < $qty; $p++) {
                                        $cp = $this->customerPackageRepo->create([
                                            'customer_id' => $customerId,
                                            'package_id' => $pkgId,
                                            'status' => 'active',
                                            'expires_at' => $expiresAt
                                        ]);
                                        
                                        foreach ($package['services'] as $pkgService) {
                                            $sQuantity = $pkgService['quantity'] ?? 1;
                                            $cpsId = $this->customerPackageRepo->addService($cp['id'], $pkgService['id'], $sQuantity);
                                            
                                            // Deduct if it's the initial service
                                            if (!empty($item['initial_service_id']) && $item['initial_service_id'] == $pkgService['id']) {
                                                $this->customerPackageRepo->incrementUsedQuantity($cpsId);
                                            }
                                        }
                                    }
                                }
                            }
                            
                            if (!empty($item['initial_service_name'])) {
                                $itemName = 'Package: ' . $itemName . ' (First Service: ' . $item['initial_service_name'] . ')';
                            } else {
                                $itemName = 'Package: ' . $itemName;
                            }
                        }

                        for ($i = 0; $i < $qty; $i++) {
                            $this->appointmentRepo->create([
                                'customer_id' => $customerId,
                                'customer_name' => $customer['name'] ?? 'Unknown',
                                'stylist_id' => $employeeId,
                                'stylist_name' => $stylistName,
                                'service_id' => !empty($item['service_id']) ? (int)$item['service_id'] : (!empty($item['id']) ? (int)$item['id'] : null),
                                'service_name' => $itemName,
                                'date' => date('Y-m-d'),
                                'time' => date('H:i'),
                                'status' => 'done',
                                'booking_type' => 'Walk-in'
                            ]);
                    }
                }
            }

            $response = $response->withHeader('HX-Trigger', json_encode([
                    'show-toast' => ['type' => 'success', 'message' => 'Added to Unbilled Completed Appointments.']
                ]));
                
                if ($appId) {
                    return $response->withHeader('HX-Redirect', '/web/appointments')->withStatus(200);
                }
                
                return $response->withStatus(200);
            }

            $invoice = $this->invoiceService->checkout($data);
            
            if (!empty($data['appointment_id'])) {
                $appId = (int)$data['appointment_id'];
                $this->appointmentRepo->setTenantId($tenantId);
                $this->appointmentRepo->updateStatus($appId, 'paid');
                if (!empty($invoice['id'])) {
                    $this->appointmentRepo->setInvoiceId($appId, $invoice['id']);
                }
                
                // Generate PDF and send via WhatsApp
                $fullInvoice = $this->invoiceService->getInvoicePublic($invoice['id']);
                if ($fullInvoice && !empty($fullInvoice['customer_phone'])) {
                    $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
                    $pdfUrl = $this->pdfService->generateInvoicePdf($fullInvoice, $baseUrl);
                    $this->whatsappService->sendInvoice(
                        $fullInvoice['customer_phone'], 
                        $pdfUrl, 
                        $fullInvoice['customer_name'] ?? 'Customer'
                    );
                }
                
                // Redirect back to the calendar after checking out an appointment
                return $response->withHeader('HX-Redirect', '/web/appointments')->withStatus(200);
            }

            // Return HTMX OOB success message for standard POS checkout
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'success', 'message' => 'Invoice #' . $invoice['id'] . ' created for QAR ' . number_format($invoice['total_amount'], 2)]
            ]))->withHeader('HX-Redirect', '/web/invoices')->withStatus(200);
            
        } catch (Exception $e) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'error', 'message' => 'Checkout failed. ' . $e->getMessage()]
            ]))->withStatus(200);
        }
    }

    public function payRemaining(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int)$args['id'];
        $data = $request->getParsedBody();
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->invoiceService->setTenantId($tenantId);
        
        try {
            $invoice = $this->invoiceService->payInvoice($invoiceId, $data);
            
            if (!empty($invoice['appointment_id'])) {
                $appId = (int)$invoice['appointment_id'];
                $this->appointmentRepo->setTenantId($tenantId);
                $this->appointmentRepo->updateStatus($appId, 'paid');
            }

            // Generate PDF and send via WhatsApp
            $fullInvoice = $this->invoiceService->getInvoicePublic($invoiceId);
            if ($fullInvoice && !empty($fullInvoice['customer_phone'])) {
                $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
                $pdfUrl = $this->pdfService->generateInvoicePdf($fullInvoice, $baseUrl);
                $this->whatsappService->sendInvoice(
                    $fullInvoice['customer_phone'], 
                    $pdfUrl, 
                    $fullInvoice['customer_name'] ?? 'Customer'
                );
            }

            // Show toast and reload page after a brief delay
            $response->getBody()->write('
                <div id="payment-alerts" hx-swap-oob="true">
                    <script>
                        setTimeout(() => { window.location.reload(); }, 1500);
                    </script>
                </div>
            ');
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'Payment processed successfully!', 'type' => 'success']
            ]))->withStatus(200);
            
        } catch (Exception $e) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()]
            ]))->withStatus(200);
        }
    }
    
    public function bulkInvoice(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        if (empty($data['appointment_ids']) || !is_array($data['appointment_ids'])) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'No appointments selected for bulk invoice', 'type' => 'error']
            ]))->withStatus(400);
        }

        try {
            $this->invoiceService->setTenantId($tenantId);
            $this->invoiceService->createBulkInvoice($customerId, $data['appointment_ids']);
            
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'Bulk Invoice generated successfully!'],
                'refresh-customer-profile' => true
            ]))->withHeader('HX-Refresh', 'true');
        } catch (Exception $e) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'Error: ' . $e->getMessage(), 'type' => 'error']
            ]))->withStatus(400);
        }
    }

    public function paySelectedInvoices(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $data = $request->getParsedBody();

        if (empty($data['invoice_ids']) || !is_array($data['invoice_ids'])) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'error', 'message' => 'No invoices selected.']
            ]))->withStatus(200);
        }
        
        try {
            $this->invoiceService->setTenantId($tenantId);
            $this->invoiceService->paySelectedInvoices($customerId, $data['invoice_ids'], $data);
            
            $invoiceIdsParam = implode(',', $data['invoice_ids']);
            $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
            $receiptUrl = $baseUrl . '/web/receipts/bulk?invoices=' . $invoiceIdsParam;

            $response->getBody()->write('
                <div id="payment-alerts" hx-swap-oob="true">
                    <script>
                        window.open("' . $receiptUrl . '", "_blank");
                        setTimeout(() => window.location.reload(), 500);
                    </script>
                </div>
            ');
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'Bulk payment processed successfully!', 'type' => 'success']
            ]))->withStatus(200);
            
        } catch (Exception $e) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()]
            ]))->withStatus(200);
        }
    }

    public function printBulkReceipt(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->invoiceService->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        if (empty($params['invoices'])) {
            return $response->withStatus(400);
        }
        
        $invoiceIds = explode(',', $params['invoices']);
        $invoices = [];
        foreach ($invoiceIds as $id) {
            $inv = $this->invoiceService->getInvoicePublic((int)$id);
            if ($inv) {
                $invoices[] = $inv;
            }
        }
        
        if (empty($invoices)) {
            return $response->withStatus(404);
        }
        
        $baseUrl = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost() . ($request->getUri()->getPort() ? ':' . $request->getUri()->getPort() : '');
        $pdfUrl = $this->pdfService->generateBulkReceiptPdf($invoices, $baseUrl);
        
        return $response->withHeader('Location', $pdfUrl)->withStatus(302);
    }

    public function payAllInvoices(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $customerId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        try {
            $this->invoiceService->setTenantId($tenantId);
            $this->invoiceService->payAllUnpaidInvoices($customerId, $data);
            
            // In a real scenario, you might want to send a consolidated receipt 
            // or send individual PDFs for each invoice paid.
            // For simplicity, we assume we just paid them and the UI will reflect it.
            // A more robust implementation would fetch the paid invoices and send them.
            
            $response->getBody()->write('
                <script>
                    setTimeout(() => { window.location.reload(); }, 1500);
                </script>
            ');
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'All invoices paid successfully!', 'type' => 'success'],
                'refresh-customer-profile' => true
            ]))->withStatus(200);
        } catch (Exception $e) {
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()]
            ]))->withStatus(200);
        }
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $invoiceId = (int)$args['id'];
        
        $invoice = $this->invoiceService->getInvoicePublic($invoiceId);
        
        if (!$invoice) {
            $response->getBody()->write("Invoice not found.");
            return $response->withStatus(404);
        }

        return $this->view->render($response, 'invoices/view.twig', [
            'invoice' => $invoice,
            'title' => 'Invoice #' . sprintf('%05d', $invoice['id'])
        ]);
    }
}
