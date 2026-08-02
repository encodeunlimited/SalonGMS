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
        
        // Remove empty state if it exists
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . '<tbody hx-swap-oob="beforeend:#services-table-body">' . $rowHtml . '</tbody>');
        
        return $response->withHeader('HX-Trigger', 'close-modal');
    }
}
