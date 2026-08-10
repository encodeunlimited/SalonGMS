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
use Exception;

use App\Repositories\AppointmentRepository;

class InvoiceController
{
    private Twig $view;
    private InvoiceService $invoiceService;
    private ServiceRepository $serviceRepo;
    private TenantSettingRepository $settingsRepo;
    private PaymentTypeRepository $paymentTypeRepo;
    private UserRepository $userRepo;
    private AppointmentRepository $appointmentRepo;

    public function __construct(
        Twig $view, 
        InvoiceService $invoiceService, 
        ServiceRepository $serviceRepo, 
        TenantSettingRepository $settingsRepo, 
        PaymentTypeRepository $paymentTypeRepo,
        UserRepository $userRepo,
        AppointmentRepository $appointmentRepo
    ) {
        $this->view = $view;
        $this->invoiceService = $invoiceService;
        $this->serviceRepo = $serviceRepo;
        $this->settingsRepo = $settingsRepo;
        $this->paymentTypeRepo = $paymentTypeRepo;
        $this->userRepo = $userRepo;
        $this->appointmentRepo = $appointmentRepo;
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
}
