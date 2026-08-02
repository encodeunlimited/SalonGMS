<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class WebSessionAuthMiddleware
{
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
        $request = $request->withAttribute('tenant_id', $_SESSION['tenant_id']);
        $request = $request->withAttribute('user_id', $_SESSION['user_id']);
        $request = $request->withAttribute('role', $_SESSION['role'] ?? 'stylist');

        return $handler->handle($request);
    }
}
