<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use App\Repositories\AppointmentRepository;

class AppointmentController
{
    private Twig $view;
    private AppointmentRepository $appointments;

    public function __construct(Twig $view, AppointmentRepository $appointments)
    {
        $this->view = $view;
        $this->appointments = $appointments;
    }

    public function index(Request $request, Response $response): Response
    {
        // For Phase 3, we mock the tenant_id as 1.
        $tenantId = 1;
        
        $appointments = $this->appointments->getAllForTenant($tenantId);

        return $this->view->render($response, 'appointments/index.twig', [
            'title' => 'Appointments',
            'active_menu' => 'appointments',
            'appointments' => $appointments
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = 1; // Mock tenant ID for now
        
        $newAppointment = $this->appointments->create($tenantId, $data);

        return $this->view->render($response, 'appointments/list_item.twig', [
            'appointment' => $newAppointment
        ]);
    }
}
