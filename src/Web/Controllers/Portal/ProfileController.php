<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Repositories\CustomerRepository;
use Exception;

class ProfileController
{
    private Twig $view;
    private CustomerRepository $customerRepo;

    public function __construct(Twig $view, CustomerRepository $customerRepo)
    {
        $this->view = $view;
        $this->customerRepo = $customerRepo;
    }

    public function index(Request $request, Response $response): Response
    {
        $customerId = $request->getAttribute('customer_id');
        $tenantId = $request->getAttribute('tenant_id');
        
        $this->customerRepo->setTenantId($tenantId);
        $customer = $this->customerRepo->getById($customerId);

        return $this->view->render($response, 'portal/profile.twig', [
            'customer' => $customer
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        $customerId = $request->getAttribute('customer_id');
        $tenantId = $request->getAttribute('tenant_id');
        
        $data = $request->getParsedBody();
        $this->customerRepo->setTenantId($tenantId);
        $customer = $this->customerRepo->getById($customerId);

        try {
            $updateData = [
                'name' => $data['name'] ?? $customer['name'],
                'phone' => $data['phone'] ?? $customer['phone'],
                'email' => $data['email'] ?? $customer['email'],
                'date_of_birth' => $data['date_of_birth'] ?? $customer['date_of_birth']
            ];

            // Handle profile image upload
            $uploadedFiles = $request->getUploadedFiles();
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
                
                // Update session
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['customer_profile_image'] = $updateData['profile_image'];
            }

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['customer_name'] = $updateData['name'];

            $this->customerRepo->update($customerId, $updateData);

            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="profile-error" class="bg-red-50 text-red-700 p-4 rounded-lg mb-4 text-sm font-medium border border-red-200">
                    ' . htmlspecialchars($e->getMessage()) . '
                </div>
            ');
            return $response->withStatus(200);
        }
    }
}
