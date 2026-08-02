<?php

namespace App\Web\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use App\Services\AuthService;
use Exception;

class AuthController
{
    private Twig $view;
    private AuthService $authService;

    public function __construct(Twig $view, AuthService $authService)
    {
        $this->view = $view;
        $this->authService = $authService;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // If already logged in, redirect to dashboard
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/web/dashboard')->withStatus(302);
        }

        return $this->view->render($response, 'auth/login.twig');
    }

    public function processLogin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        try {
            $user = $this->authService->attemptLogin($email, $password);
            
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['tenant_id'] = $user['tenant_id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];

            // Since it's HTMX, we can redirect client-side via HX-Redirect
            return $response->withHeader('HX-Redirect', '/web/dashboard')->withStatus(200);
            
        } catch (Exception $e) {
            $response->getBody()->write('
                <div id="login-error" class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm border border-red-200" hx-swap-oob="true">
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
        session_destroy();
        return $response->withHeader('Location', '/web/login')->withStatus(302);
    }
}
