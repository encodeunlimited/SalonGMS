<?php

namespace App\Web\Controllers\Portal;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AuthService;
use App\Repositories\CustomerRepository;
use Exception;

class AuthController
{
    private Twig $view;
    private AuthService $authService;
    private CustomerRepository $customerRepo;

    public function __construct(Twig $view, AuthService $authService, CustomerRepository $customerRepo)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->customerRepo = $customerRepo;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['customer_id'])) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'portal/auth/login.twig');
    }

    public function processLogin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        try {
            // Note: tenant is assumed to be 1 for now, or based on subdomain.
            // For a single-tenant portal, this is fine.
            $this->customerRepo->setTenantId(1); // Set default tenant id for public portal
            
            $customer = $this->authService->attemptCustomerLogin($email, $password, $this->customerRepo);
            
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['tenant_id'] = $customer['tenant_id'];
            $_SESSION['customer_name'] = $customer['name'];
            $_SESSION['customer_profile_image'] = $customer['profile_image'] ?? null;

            // Since it's HTMX, we can redirect client-side via HX-Redirect
            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);
            
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="login-error" style="margin-bottom: 1rem; padding: 0.75rem; border-radius: 0.5rem; background-color: #fef2f2; color: #991b1b; font-size: 0.875rem; border: 1px solid #fecaca;">
                    ' . htmlspecialchars($e->getMessage()) . '
                </div>
            ');
            return $response->withStatus(200);
        }
    }

    public function showRegister(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['customer_id'])) {
            return $response->withHeader('Location', '/portal/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'portal/auth/register.twig');
    }

    public function processRegister(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $name = $data['name'] ?? '';
        $email = $data['email'] ?? '';
        $countryCode = $data['country_code'] ?? '';
        $phoneNum = $data['phone'] ?? '';
        $phone = (!empty($phoneNum)) ? $countryCode . $phoneNum : '';
        
        $dateOfBirth = $data['date_of_birth'] ?? '';
        $password = $data['password'] ?? '';
        $passwordConfirm = $data['password_confirm'] ?? '';

        try {
            if (empty($name) || empty($email) || empty($password)) {
                throw new Exception("Name, email and password are required.");
            }
            if ($password !== $passwordConfirm) {
                throw new Exception("Passwords do not match.");
            }
            
            $this->customerRepo->setTenantId(1); // Default tenant

            // Check if email already exists
            if ($this->customerRepo->getByEmail($email)) {
                throw new Exception("Email is already registered. Please login.");
            }

            // Handle profile image upload
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

            // Create customer
            $customerData = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'date_of_birth' => $dateOfBirth,
                'password' => $password,
                'profile_image' => $profileImagePath,
            ];
            
            $customer = $this->customerRepo->create($customerData);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['tenant_id'] = $customer['tenant_id'];
            $_SESSION['customer_name'] = $customer['name'];
            $_SESSION['customer_profile_image'] = $customer['profile_image'] ?? null;

            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);

        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="register-error" style="margin-bottom: 1rem; padding: 0.75rem; border-radius: 0.5rem; background-color: #fef2f2; color: #991b1b; font-size: 0.875rem; border: 1px solid #fecaca;">
                    ' . htmlspecialchars($e->getMessage()) . '
                </div>
            ');
            return $response->withStatus(200);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['customer_id']);
        unset($_SESSION['customer_name']);
        
        // If staff session exists, keep it, otherwise destroy entirely
        if (!isset($_SESSION['user_id'])) {
            session_destroy();
        }
        
        return $response->withHeader('Location', '/portal/login')->withStatus(302);
    }
}
