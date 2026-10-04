<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use App\Repositories\TenantSettingRepository;

class WebSessionAuthMiddleware
{
    private Twig $view;
    private TenantSettingRepository $settingsRepo;

    public function __construct(Twig $view, TenantSettingRepository $settingsRepo)
    {
        $this->view = $view;
        $this->settingsRepo = $settingsRepo;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id']) || !isset($_SESSION['tenant_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/web/login')->withStatus(302);
        }

        // Inject the tenant_id and user_id into the request attributes
        $tenantId = (int)$_SESSION['tenant_id'];
        $request = $request->withAttribute('tenant_id', $tenantId);
        $request = $request->withAttribute('user_id', $_SESSION['user_id']);
        $request = $request->withAttribute('role', $_SESSION['role'] ?? 'stylist');

        // Provide session data to all Twig templates globally
        $this->view->getEnvironment()->addGlobal('auth_user', $_SESSION);

        // Provide tenant settings globally to Twig
        try {
            $this->settingsRepo->setTenantId($tenantId);
            $settings = $this->settingsRepo->getAll();
            $this->view->getEnvironment()->addGlobal('system_settings', $settings);
        } catch (\Throwable $e) {
            $this->view->getEnvironment()->addGlobal('system_settings', []);
        }

        return $handler->handle($request);
    }
}
