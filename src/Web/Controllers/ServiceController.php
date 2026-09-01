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
    private \App\Repositories\ServiceCategoryRepository $serviceCategoryRepo;

    public function __construct(Twig $view, ServiceRepository $services, \App\Repositories\ServiceCategoryRepository $serviceCategoryRepo)
    {
        $this->view = $view;
        $this->services = $services;
        $this->serviceCategoryRepo = $serviceCategoryRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'id',
            'dir' => $params['dir'] ?? 'desc',
            'page' => (int)($params['page'] ?? 1),
            'limit' => 10,
            'filters' => []
        ];
        
        $paginated = $this->services->getPaginated($options);

        return $this->view->render($response, 'services/index.twig', [
            'title' => 'Services',
            'active_menu' => 'services',
            'services' => $paginated['data'],
            'pagination' => $paginated,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir']
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->serviceCategoryRepo->setTenantId($tenantId);
        $categories = $this->serviceCategoryRepo->getAll();

        return $this->view->render($response, 'services/modal.twig', [
            'categories' => $categories
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        $imagePaths = $this->handleImageUploads($uploadedFiles);

        $service = $this->services->create([
            'name' => $data['name'],
            'arabic_name' => $data['arabic_name'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'],
            'images' => $imagePaths,
            'duration_minutes' => (int)$data['duration_minutes'],
            'price' => (float)$data['price'],
            'arabic_price' => $data['arabic_price'] ?? null
        ]);

        $rowHtml = $this->view->fetch('services/row.twig', ['service' => $service]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="beforeend:#services-table-body" id=', $rowHtml);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . $rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Service added successfully!']
                        ]));
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        $service = $this->services->getById($serviceId);
        
        if (!$service) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('services/view.twig', ['service' => $service]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        $service = $this->services->getById($serviceId);
        
        if (!$service) {
            return $response->withStatus(404);
        }

        $this->serviceCategoryRepo->setTenantId($tenantId);
        $categories = $this->serviceCategoryRepo->getAll();

        $html = $this->view->fetch('services/modal.twig', [
            'service' => $service,
            'categories' => $categories
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        $updateData = [
            'name' => $data['name'],
            'arabic_name' => $data['arabic_name'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'duration_minutes' => (int)$data['duration_minutes'],
            'price' => (float)$data['price'],
            'arabic_price' => $data['arabic_price'] ?? null
        ];

        $newImagePaths = $this->handleImageUploads($uploadedFiles);
        
        // If there are new images, or we want to support deleting images, we need to handle existing images.
        // For simplicity now, we append new images if they exist, or overwrite if requested.
        // The HTML form can send an array of existing images to keep.
        $existingImages = $data['existing_images'] ?? [];
        if (!is_array($existingImages)) {
            $existingImages = [$existingImages]; // Ensure it's an array if only one
        }
        
        // Combine existing and new
        $finalImages = array_merge($existingImages, $newImagePaths);
        $updateData['images'] = $finalImages;

        $service = $this->services->update($serviceId, $updateData);

        $rowHtml = $this->view->fetch('services/row.twig', ['service' => $service]);
        $rowHtmlWithOob = str_replace('<tr id=', '<tr hx-swap-oob="outerHTML:#service-row-' . $serviceId . '" id=', $rowHtml);
        
        $response->getBody()->write($rowHtmlWithOob);
        
        return $response->withHeader('Content-Type', 'text/html')
                        ->withHeader('HX-Trigger', json_encode([
                            'close-modal' => true,
                            'show-toast' => ['message' => 'Service updated successfully!']
                        ]));
    }

    private function handleImageUploads(array $uploadedFiles): array
    {
        $imagePaths = [];
        $uploadDir = dirname($_SERVER['SCRIPT_FILENAME']) . '/uploads/services';
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if (isset($uploadedFiles['images'])) {
            $files = $uploadedFiles['images'];
            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $file) {
                if ($file->getError() === UPLOAD_ERR_OK) {
                    $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
                    $basename = bin2hex(random_bytes(8));
                    $filename = sprintf('%s.%0.8s', $basename, $extension);
                    
                    $file->moveTo($uploadDir . DIRECTORY_SEPARATOR . $filename);
                    $imagePaths[] = '/uploads/services/' . $filename;
                }
            }
        }
        
        return $imagePaths;
    }
    public function delete(Request $request, Response $response, array $args): Response
    {
        $role = $request->getAttribute('role');
        if ($role !== 'admin') {
            return $response->withStatus(403);
        }
        
        $tenantId = $request->getAttribute('tenant_id');
        $this->services->setTenantId($tenantId);
        
        $serviceId = (int) $args['id'];
        
        try {
            $this->services->delete($serviceId);
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Service deleted successfully!']
                            ]));
        } catch (\Exception $e) {
            return $response->withHeader('Content-Type', 'text/html')
                            ->withHeader('HX-Trigger', json_encode([
                                'show-toast' => ['message' => 'Error deleting service: ' . $e->getMessage(), 'type' => 'error']
                            ]));
        }
    }
}
