<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ServiceRepository;

class ServiceController
{
    private Twig $view;
    private ServiceRepository $services;

    public function __construct(Twig $view, ServiceRepository $services)
    {
        $this->view = $view;
        $this->services = $services;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        $servicesList = $this->services->getAll();

        return $this->view->render($response, 'services/index.twig', [
            'title' => 'Services',
            'active_menu' => 'services',
            'services' => $servicesList
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'services/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $service = $this->services->create([
            'name' => $data['name'],
            'description' => $data['description'],
            'duration_minutes' => (int)$data['duration_minutes'],
            'price' => (float)$data['price']
        ]);

        $rowHtml = $this->view->fetch('services/row.twig', ['service' => $service]);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . '<tbody hx-swap-oob="beforeend:#services-table-body">' . $rowHtml . '</tbody>');
        $response->getBody()->write('<div id="form-messages" hx-swap-oob="true"><div class="p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">Service added successfully!</div></div>');
        
        return $response->withHeader('Content-Type', 'text/html')->withHeader('HX-Trigger', 'close-modal');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        $service = $this->services->getById($serviceId);
        
        if (!$service) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('services/modal.twig', ['service' => $service]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        $data = $request->getParsedBody();
        
        $service = $this->services->update($serviceId, [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'duration_minutes' => (int)$data['duration_minutes'],
            'price' => (float)$data['price']
        ]);

        $rowHtml = $this->view->fetch('services/row.twig', ['service' => $service]);
        
        // Return updated row wrapped in OOB swap for the specific ID
        $oobHtml = '<tbody hx-swap-oob="outerHTML:#service-row-' . $serviceId . '">' . $rowHtml . '</tbody>';
        
        $response->getBody()->write($oobHtml);
        $response->getBody()->write('<div id="form-messages" hx-swap-oob="true"><div class="p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">Service updated successfully!</div></div>');
        
        return $response->withHeader('Content-Type', 'text/html')->withHeader('HX-Trigger', 'close-modal');
    }
}
