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
        $tenantId = 1;
        $this->users->setTenantId($tenantId);
        $employees = $this->users->getAll();

        return $this->view->render($response, 'employees/index.twig', [
            'title' => 'Employees',
            'active_menu' => 'employees',
            'employees' => $employees
        ]);
    }
}
