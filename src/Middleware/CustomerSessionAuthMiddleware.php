<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class CustomerSessionAuthMiddleware
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['customer_id']) || !isset($_SESSION['tenant_id'])) {
            $response = new SlimResponse();
            return $response->withHeader('Location', '/portal/login')->withStatus(302);
        }

        // Inject the tenant_id and customer_id into the request attributes
        $request = $request->withAttribute('tenant_id', $_SESSION['tenant_id']);
        $request = $request->withAttribute('customer_id', $_SESSION['customer_id']);

        // Provide session data to all Twig templates globally
        $this->view->getEnvironment()->addGlobal('auth_customer', current($_SESSION['customer_data'] ?? [])); // just an idea, maybe better to just pass what we need
        $this->view->getEnvironment()->addGlobal('auth_customer', [
            'id' => $_SESSION['customer_id'],
            'name' => $_SESSION['customer_name'] ?? 'Customer',
            'tenant_id' => $_SESSION['tenant_id']
        ]);

        return $handler->handle($request);
    }
}
