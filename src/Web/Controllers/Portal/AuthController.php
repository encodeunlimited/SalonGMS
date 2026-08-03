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

            // Since it's HTMX, we can redirect client-side via HX-Redirect
            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);
            
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="login-error" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200" hx-swap-oob="true">
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
        $phone = $data['phone'] ?? '';
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

            // Create customer
            $customerData = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => $password,
            ];
            
            $customer = $this->customerRepo->create($customerData);

            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['customer_id'] = $customer['id'];
            $_SESSION['tenant_id'] = $customer['tenant_id'];
            $_SESSION['customer_name'] = $customer['name'];

            return $response->withHeader('HX-Redirect', '/portal/dashboard')->withStatus(200);

        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="register-error" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200" hx-swap-oob="true">
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
