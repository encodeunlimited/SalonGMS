<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\CustomerRepository;

class CustomerController
{
    private Twig $view;
    private CustomerRepository $customers;

    public function __construct(Twig $view, CustomerRepository $customers)
    {
        $this->view = $view;
        $this->customers = $customers;
    }

    public function index(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $params = $request->getQueryParams();
        $options = [
            'search' => $params['search'] ?? '',
            'sort' => $params['sort'] ?? 'id',
            'dir' => $params['dir'] ?? 'desc',
            'filters' => []
        ];
        
        $customersList = $this->customers->getAll($options);

        return $this->view->render($response, 'customers/index.twig', [
            'title' => 'Customers',
            'active_menu' => 'customers',
            'customers' => $customersList,
            'search' => $options['search'],
            'sort' => $options['sort'],
            'dir' => $options['dir']
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'customers/modal.twig');
    }

    public function store(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $profileImagePath = null;

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = __DIR__ . '/../../../../public/uploads/profiles';
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $profileImagePath = '/uploads/profiles/' . $filename;
        }

        $customer = $this->customers->create([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'profile_image' => $profileImagePath
        ]);

        $rowHtml = $this->view->fetch('customers/row.twig', ['customer' => $customer]);
        
        $oobEmptyState = '<tr id="empty-state" hx-swap-oob="delete"></tr>';
        
        $response->getBody()->write($oobEmptyState . '<tbody hx-swap-oob="beforeend:#customers-table-body">' . $rowHtml . '</tbody>');
        $response->getBody()->write('<div id="form-messages" hx-swap-oob="true"><div class="p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">Customer added successfully!</div></div>');
        
        return $response->withHeader('Content-Type', 'text/html')->withHeader('HX-Trigger', 'close-modal');
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $customerId = (int) $args['id'];
        $customer = $this->customers->getById($customerId);
        
        if (!$customer) {
            return $response->withStatus(404);
        }

        $html = $this->view->fetch('customers/modal.twig', ['customer' => $customer]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $customerId = (int) $args['id'];
        $data = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        $updateData = [
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null
        ];

        if (isset($uploadedFiles['profile_image']) && $uploadedFiles['profile_image']->getError() === UPLOAD_ERR_OK) {
            $uploadedFile = $uploadedFiles['profile_image'];
            $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
            $basename = bin2hex(random_bytes(8));
            $filename = sprintf('%s.%0.8s', $basename, $extension);
            
            $directory = __DIR__ . '/../../../../public/uploads/profiles';
            $uploadedFile->moveTo($directory . DIRECTORY_SEPARATOR . $filename);
            
            $updateData['profile_image'] = '/uploads/profiles/' . $filename;
        }

        $customer = $this->customers->update($customerId, $updateData);

        $rowHtml = $this->view->fetch('customers/row.twig', ['customer' => $customer]);
        
        $oobHtml = '<tbody hx-swap-oob="outerHTML:#customer-row-' . $customerId . '">' . $rowHtml . '</tbody>';
        
        $response->getBody()->write($oobHtml);
        $response->getBody()->write('<div id="form-messages" hx-swap-oob="true"><div class="p-3 mb-4 text-sm text-green-700 bg-green-100 rounded-lg">Customer updated successfully!</div></div>');
        
        return $response->withHeader('Content-Type', 'text/html')->withHeader('HX-Trigger', 'close-modal');
    }

    public function apiStore(Request $request, Response $response): Response
    {
        $tenantId = (int)$request->getAttribute('tenant_id');
        $this->customers->setTenantId($tenantId);
        
        $data = json_decode((string)$request->getBody(), true);
        
        $customer = $this->customers->create([
            'name' => $data['name'] ?? 'Unknown',
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'profile_image' => null
        ]);

        $response->getBody()->write(json_encode($customer));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
