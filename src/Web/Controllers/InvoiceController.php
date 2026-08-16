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
        CustomerRepository $customerRepo
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

        $appointmentId = (int)($request->getQueryParams()['appointment_id'] ?? 0);
        $appointmentToCheckout = null;
        if ($appointmentId > 0) {
            $this->appointmentRepo->setTenantId($tenantId);
            $appointmentToCheckout = $this->appointmentRepo->getAppointmentDetails($appointmentId);
        }

        return $this->view->render($response, 'pos/index.twig', [
            'title' => 'Point of Sale',
            'active_menu' => 'pos',
            'services_by_category' => $servicesByCategory,
            'payment_types' => $paymentTypes,
            'employees' => $employees,
            'customers' => $customers,
            'appointment_to_checkout' => $appointmentToCheckout
        ]);
    }

    public function checkout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->invoiceService->setTenantId($tenantId);
        
        try {
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
            $response->getBody()->write('
                <div id="pos-alerts" hx-swap-oob="true">
                    <div class="p-4 mb-4 text-sm text-green-800 rounded-lg bg-green-50 border border-green-300 shadow-sm" role="alert">
                        <strong>Success!</strong> Invoice #' . $invoice['id'] . ' created for $' . number_format($invoice['total_amount'], 2) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200);
            
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="pos-alerts" hx-swap-oob="true">
                    <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 border border-red-300 shadow-sm" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200);
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

            // Trigger a page reload to reflect the updated statuses and totals
            return $response->withHeader('HX-Refresh', 'true')->withStatus(200);
            
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="payment-alerts" hx-swap-oob="true">
                    <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 border border-red-300 shadow-sm" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200);
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
            
            return $response->withHeader('HX-Trigger', json_encode([
                'show-toast' => ['message' => 'All invoices paid successfully!'],
                'refresh-customer-profile' => true
            ]))->withHeader('HX-Refresh', 'true');
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="payment-alerts" hx-swap-oob="true">
                    <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 border border-red-300 shadow-sm" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200);
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
