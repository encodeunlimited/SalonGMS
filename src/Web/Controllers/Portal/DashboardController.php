<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\AppointmentRepository;
use App\Repositories\CustomerRepository;

class DashboardController
{
    private Twig $view;
    private AppointmentRepository $appointmentRepo;
    private CustomerRepository $customerRepo;

    public function __construct(Twig $view, AppointmentRepository $appointmentRepo, CustomerRepository $customerRepo)
    {
        $this->view = $view;
        $this->appointmentRepo = $appointmentRepo;
        $this->customerRepo = $customerRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $customerId = $request->getAttribute('customer_id');
        $tenantId = $request->getAttribute('tenant_id');
        
        $this->appointmentRepo->setTenantId($tenantId);
        $this->customerRepo->setTenantId($tenantId);

        $customer = $this->customerRepo->getById($customerId);
        
        // Fetch appointments for this customer
        $appointments = $this->appointmentRepo->getAll(['sort' => 'apt_date', 'dir' => 'DESC']);
        // Filter in memory for now, or add getByCustomerId to repository.
        // Assuming AppointmentRepository has a way to filter, but let's just filter here if it doesn't.
        $customerAppointments = array_filter($appointments, function($app) use ($customerId) {
            return $app['customer_id'] == $customerId;
        });

        // Separate into upcoming and past
        $now = new \DateTime();
        $upcoming = [];
        $past = [];

        foreach ($customerAppointments as &$app) {
            $aptDate = $app['apt_date'] ?? date('Y-m-d');
            $aptTime = $app['apt_time'] ?? '00:00';
            $aptEndTime = $app['apt_end_time'] ?? $aptTime;
            
            $startTime = new \DateTime("$aptDate $aptTime");
            $app['start_time'] = $startTime->format('Y-m-d H:i:s');
            
            $endTime = new \DateTime("$aptDate $aptEndTime");
            $app['end_time'] = $endTime->format('Y-m-d H:i:s');
            
            if ($startTime >= $now && in_array(strtolower($app['status']), ['scheduled'])) {
                $upcoming[] = $app;
            } else {
                $past[] = $app;
            }
        }
        unset($app);

        return $this->view->render($response, 'portal/dashboard.twig', [
            'customer' => $customer,
            'upcoming_appointments' => $upcoming,
            'past_appointments' => $past
        ]);
    }
}
