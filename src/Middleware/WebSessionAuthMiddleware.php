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
        // TODO: Implement Session verification
        // If unauthenticated:
        // $response = new SlimResponse();
        // return $response->withHeader('Location', '/web/login')->withStatus(302);
        
        return $handler->handle($request);
    }
}
