<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\CustomerRepository;
use App\Repositories\PackageRepository;

class BirthdayController
{
    private Twig $view;
    private CustomerRepository $customerRepo;
    private PackageRepository $packageRepo;

    public function __construct(Twig $view, CustomerRepository $customerRepo, PackageRepository $packageRepo)
    {
        $this->view = $view;
        $this->customerRepo = $customerRepo;
        $this->packageRepo = $packageRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customerRepo->setTenantId($tenantId);
        $this->packageRepo->setTenantId($tenantId);

        $customers = $this->customerRepo->getAll();
        
        $today = date('Y-m-d');
        $currentMonth = date('m');
        $currentDay = date('d');
        
        $todaysBirthdays = [];
        $upcomingBirthdays = [];

        foreach ($customers as $c) {
            if (empty($c['date_of_birth'])) continue;
            
            $dobMonth = date('m', strtotime($c['date_of_birth']));
            $dobDay = date('d', strtotime($c['date_of_birth']));
            
            if ($dobMonth === $currentMonth && $dobDay === $currentDay) {
                $todaysBirthdays[] = $c;
            } elseif ($dobMonth === $currentMonth && $dobDay > $currentDay) {
                $upcomingBirthdays[] = $c;
            }
        }
        
        // Sort upcoming by day
        usort($upcomingBirthdays, function($a, $b) {
            return date('d', strtotime($a['date_of_birth'])) <=> date('d', strtotime($b['date_of_birth']));
        });

        $packages = $this->packageRepo->getAll(['active' => 1]);

        return $this->view->render($response, 'birthdays/index.twig', [
            'title' => 'Birthdays & Offers',
            'active_menu' => 'birthdays',
            'todays_birthdays' => $todaysBirthdays,
            'upcoming_birthdays' => $upcomingBirthdays,
            'packages' => $packages
        ]);
    }
}
