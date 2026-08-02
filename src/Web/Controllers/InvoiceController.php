<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\InvoiceService;
use App\Repositories\ServiceRepository;
use Exception;

class InvoiceController
{
    private Twig $view;
    private InvoiceService $invoiceService;
    private ServiceRepository $serviceRepo;

    public function __construct(Twig $view, InvoiceService $invoiceService, ServiceRepository $serviceRepo)
    {
        $this->view = $view;
        $this->invoiceService = $invoiceService;
        $this->serviceRepo = $serviceRepo;
    }

    public function pos(Request $request, Response $response): Response
    {
        $tenantId = 1; // Mock tenant ID for Phase 5
        $this->serviceRepo->setTenantId($tenantId);
        
        $services = $this->serviceRepo->getAll();

        return $this->view->render($response, 'pos/index.twig', [
            'title' => 'Point of Sale',
            'active_menu' => 'pos',
            'services' => $services
        ]);
    }

    public function checkout(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = 1;
        
        $this->invoiceService->setTenantId($tenantId);
        
        try {
            $invoice = $this->invoiceService->checkout($data);
            
            // Return HTMX OOB success message
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
