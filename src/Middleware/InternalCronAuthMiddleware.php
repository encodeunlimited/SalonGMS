<?php

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class InternalCronAuthMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // TODO: Implement Basic Auth or Secret Key verification for Cron jobs
        // Example: checking an INTERNAL_API_KEY header or query param
        
        return $handler->handle($request);
    }
}
