<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\ServiceRepository;
use App\Repositories\UserRepository;

class EmployeeController
{
    private Twig $view;
    private UserRepository $users;
    private ServiceRepository $services;

    public function __construct(Twig $view, UserRepository $users, ServiceRepository $services)
    {
        $this->view = $view;
        $this->users = $users;
        $this->services = $services;
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
            'filters' => []
        ];
        
        if (!empty($params['role'])) {
            $options['filters']['role'] = $params['role'];
        }
        
        $employeesList = $this->users->getAll($options);

        return $this->view->render($response, 'employees/index.twig', [
            'title' => 'Employees',
            'active_menu' => 'employees',
            'employees' => $employeesList,
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
            
            $directory = dirname(__DIR__, 3) . '/public/uploads/profiles';
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
            
            $directory = dirname(__DIR__, 3) . '/public/uploads/profiles';
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $updateData['profile_image'] = '/uploads/profiles/' . $filename;
        }

        try {
            $employee = $this->users->update($employeeId, $updateData);

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
            return $response->withStatus(403);
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
}
