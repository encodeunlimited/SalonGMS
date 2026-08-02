<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\UserRepository;

class EmployeeController
{
    private Twig $view;
    private UserRepository $users;

    public function __construct(Twig $view, UserRepository $users)
    {
        $this->view = $view;
        $this->users = $users;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        $employees = $this->users->getAll();

        return $this->view->render($response, 'employees/index.twig', [
            'title' => 'Employees',
            'active_menu' => 'employees',
            'employees' => $employees
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'employees/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $employee = $this->users->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'commission_rate' => (float)$data['commission_rate']
        ]);

        $rowHtml = $this->view->fetch('employees/row.twig', ['employee' => $employee]);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . '<tbody hx-swap-oob="beforeend:#employees-table-body">' . $rowHtml . '</tbody>');
        
        return $response->withHeader('HX-Trigger', 'close-modal');
    }
}
