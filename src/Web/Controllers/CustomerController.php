<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\CustomerRepository;

class CustomerController
{
    private Twig $view;
    private CustomerRepository $customers;

    public function __construct(Twig $view, CustomerRepository $customers)
    {
        $this->view = $view;
        $this->customers = $customers;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        $customersList = $this->customers->getAll();

        return $this->view->render($response, 'customers/index.twig', [
            'title' => 'Customers',
            'active_menu' => 'customers',
            'customers' => $customersList
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
        $customer = $this->customers->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null
        ]);

        $rowHtml = $this->view->fetch('customers/row.twig', ['customer' => $customer]);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . '<tbody hx-swap-oob="beforeend:#customers-table-body">' . $rowHtml . '</tbody>');
        
        return $response->withHeader('HX-Trigger', 'close-modal');
    }
}
