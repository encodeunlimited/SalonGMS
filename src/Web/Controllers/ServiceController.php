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
        $tenantId = 1;
        $this->services->setTenantId($tenantId);
        $servicesList = $this->services->getAll();

        return $this->view->render($response, 'services/index.twig', [
            'title' => 'Services',
            'active_menu' => 'services',
            'services' => $servicesList
        ]);
    }
}
