<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use App\Repositories\AppointmentRepository;
use App\Services\AppointmentService;
use Exception;

class AppointmentController
{
    private Twig $view;
    private AppointmentRepository $appointments;
    private AppointmentService $appointmentService;

    public function __construct(Twig $view, AppointmentRepository $appointments, AppointmentService $appointmentService)
    {
        $this->view = $view;
        $this->appointments = $appointments;
        $this->appointmentService = $appointmentService;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->appointments->setTenantId($tenantId);
        $appointments = $this->appointments->getAllForTenant();

        return $this->view->render($response, 'appointments/index.twig', [
            'title' => 'Appointments',
            'active_menu' => 'appointments',
            'appointments' => $appointments
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = (int)$request->getAttribute('tenant_id');
        
        $this->appointmentService->setTenantId($tenantId);
        
        try {
            $newAppointment = $this->appointmentService->createAppointment($data);
            
            // Clear any previous error messages out of band, and append the new row
            $response->getBody()->write('
                <div id="form-messages" class="mb-4" hx-swap-oob="true"></div>
            ');
            return $this->view->render($response, 'appointments/list_item.twig', [
                'appointment' => $newAppointment
            ]);
        } catch (Exception $e) {
            // Return the error message out of band so HTMX updates the modal, without swapping the table row
            $response->getBody()->write('
                <div id="form-messages" class="mb-4" hx-swap-oob="true">
                    <div class="p-3 text-sm text-red-800 rounded-lg bg-red-50 border border-red-300" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>
                </div>
            ');
            return $response->withStatus(200); // 200 required for HTMX standard swap
        }
    }
}
