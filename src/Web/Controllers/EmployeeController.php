<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;
use App\Repositories\CommissionRepository;
use App\Repositories\AppointmentRepository;

class EmployeeController
{
    private Twig $view;
    private UserRepository $users;
    private ServiceRepository $services;
    private CommissionRepository $commissions;
    private AppointmentRepository $appointments;

    public function __construct(
        Twig $view, 
        UserRepository $users, 
        ServiceRepository $services,
        CommissionRepository $commissions,
        AppointmentRepository $appointments
    ) {
        $this->view = $view;
        $this->users = $users;
        $this->services = $services;
        $this->commissions = $commissions;
        $this->appointments = $appointments;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'id',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        if (!empty($params['role'])) {
            $options['filters']['role'] = $params['role'];
        }
        
        $paginated = $this->users->getPaginated($options);

        return $this->view->render($response, 'employees/index.twig', [
            'title' => 'Employees',
            'active_menu' => 'employees',
            'employees' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir'],
            'role_filter' => $params['role'] ?? ''
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        $services = $this->services->getAll();

        return $this->view->render($response, 'employees/modal.twig', [
            'services' => $services
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $profileImagePath = null;

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/profiles';
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $profileImagePath = '/uploads/profiles/' . $filename;
        }

        try {
            $employee = $this->users->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => $data['role'],
                'commission_rate' => (float)$data['commission_rate'],
                'profile_image' => $profileImagePath,
                'specialist_areas' => $data['specialist_areas'] ?? []
            ]);

            $rowHtml = $this->view->fetch('employees/row.twig', ['employee' => $employee]);
            $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="beforeend:#employees-table-body" id=', $rowHtml);
            
            $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
            
            $response->getBody()->write($oobEmptyState . $rowHtmlWithOob);
            
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'close-modal' => true,
                                'show-toast' => ['message' => 'Employee added successfully!']
                            ]));
        } catch (\Exception $e) {
            $errorMsg = 'Error adding employee: ' . $e->getMessage();
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $errorMsg = 'Error: Email already exists!';
            }
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => $errorMsg, 'type' => 'error']
                            ]));
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        $this->services->setTenantId($tenantId);
        
        $employeeId = (int) $args['id'];
        $employee = $this->users->getById($employeeId);
        
        if (!$employee) {
            return $response->withStatus(404);
        }

        $services = $this->services->getAll();

        $html = $this->view->fetch('employees/modal.twig', [
            'employee' => $employee,
            'services' => $services
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        
        $employeeId = (int) $args['id'];
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'commission_rate' => (float)$data['commission_rate'],
            'specialist_areas' => $data['specialist_areas'] ?? []
        ];
        
        if (!empty($data['password'])) {
            $updateData['password'] = $data['password'];
        }

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/profiles';
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $updateData['profile_image'] = '/uploads/profiles/' . $filename;
        }

        try {
            $employee = $this->users->update($employeeId, $updateData);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $employeeId) {
                if (isset($updateData['profile_image'])) {
                    $_SESSION['profile_image'] = $updateData['profile_image'];
                }
                if (isset($updateData['name'])) {
                    $_SESSION['name'] = $updateData['name'];
                }
            }

            $rowHtml = $this->view->fetch('employees/row.twig', ['employee' => $employee]);
            $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#employee-row-' . $employeeId . '" id=', $rowHtml);
            
            $response->getBody()->write($rowHtmlWithOob);
            
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'close-modal' => true,
                                'show-toast' => ['message' => 'Employee updated successfully!']
                            ]));
        } catch (\Exception $e) {
            $errorMsg = 'Error updating employee: ' . $e->getMessage();
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $errorMsg = 'Error: Email already exists!';
            }
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => $errorMsg, 'type' => 'error']
                            ]));
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(500);
        }
        
        $tenantId = $request->getAttribute('tenant_id');
        $this->users->setTenantId($tenantId);
        
        $employeeId = (int) $args['id'];
        
        try {
            $this->users->delete($employeeId);
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Employee deleted successfully!']
                            ]));
        } catch (\Exception $e) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error deleting employee: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }

    public function profile(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $employeeId = (int)$args['id'];
        
        $this->users->setTenantId($tenantId);
        $this->commissions->setTenantId($tenantId);
        $this->appointments->setTenantId($tenantId);

        $employee = $this->users->getById($employeeId);
        if (!$employee) {
            $response->getBody()->write("Employee not found");
            return $response->withStatus(404);
        }

        $commissionsList = $this->commissions->getByUserId($employeeId);
        $totalCommission = $this->commissions->getTotalByUserId($employeeId);
        
        $appointments = $this->appointments->getByUserId($employeeId);
        
        $paginatedCommissions = array_slice($commissionsList, 0, 10);
        $commissionsPagination = [
            'page' => 1, 'limit' => 10, 'total' => count($commissionsList), 'total_pages' => ceil(count($commissionsList) / 10)
        ];

        $paginatedAppointments = array_slice($appointments, 0, 10);
        $appointmentsPagination = [
            'page' => 1, 'limit' => 10, 'total' => count($appointments), 'total_pages' => ceil(count($appointments) / 10)
        ];

        return $this->view->render($response, 'employees/profile.twig', [
            'title' => 'Employee Profile',
            'active_menu' => 'employees',
            'employee' => $employee,
            'commissions' => $paginatedCommissions,
            'commissions_pagination' => $commissionsPagination,
            'appointments' => $paginatedAppointments,
            'appointments_pagination' => $appointmentsPagination,
            'stats' => [
                'total_commission' => $totalCommission,
                'total_appointments' => count($appointments)
            ]
        ]);
    }

    public function commissionsTable(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $employeeId = (int)$args['id'];
        $this->commissions->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'created_at',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => ['user_id' => $employeeId]
        ];

        $paginated = $this->commissions->getPaginatedCommissions($options);

        return $this->view->render($response, 'employees/partials/commissions_table.twig', [
            'commissions' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir'],
            'employee_id' => $employeeId
        ]);
    }

    public function appointmentsTable(Request $request, Response $response, array $args): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $employeeId = (int)$args['id'];
        $this->appointments->setTenantId($tenantId);

        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'date',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => ['user_id' => $employeeId]
        ];

        $paginated = $this->appointments->getPaginatedAppointments($options);

        return $this->view->render($response, 'employees/partials/appointments_table.twig', [
            'appointments' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir'],
            'employee_id' => $employeeId
        ]);
    }
}
