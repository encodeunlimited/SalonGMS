<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class JwtAuthMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // TODO: Implement JWT verification (from Authorization: Bearer header)
        // If unauthenticated:
        // $response = new SlimResponse();
        // $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        // return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        
        return $handler->handle($request);
    }
}
