<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AppointmentController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function index(Request $request, Response $response): Response
    {
        // Dummy data for the calendar and list
        $appointments = [
            ['id' => 1, 'customer' => 'Jane Doe', 'service' => 'Haircut', 'stylist' => 'Anna', 'date' => date('Y-m-d'), 'time' => '10:00 AM'],
            ['id' => 2, 'customer' => 'John Smith', 'service' => 'Coloring', 'stylist' => 'Marcus', 'date' => date('Y-m-d'), 'time' => '1:00 PM'],
        ];

        return $this->view->render($response, 'appointments/index.twig', [
            'title' => 'Appointments',
            'active_menu' => 'appointments',
            'appointments' => $appointments
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        // In a real app, we'd save to the database here.
        // For now, we'll just mock the created appointment.
        $newAppointment = [
            'id' => rand(100, 999),
            'customer' => $data['customer_name'] ?? 'Unknown',
            'service' => $data['service'] ?? 'Unknown',
            'stylist' => $data['stylist'] ?? 'Unknown',
            'date' => $data['date'] ?? date('Y-m-d'),
            'time' => $data['time'] ?? '12:00 PM',
        ];

        return $this->view->render($response, 'appointments/list_item.twig', [
            'appointment' => $newAppointment
        ]);
    }
}
