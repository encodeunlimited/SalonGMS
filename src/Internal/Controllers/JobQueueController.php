<?php

namespace App\Internal\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class JobQueueController
{
    public function process(Request $request, Response $response): Response
    {
        // TODO: Implement job processing logic
        $response->getBody()->write("Processed 0 jobs.");
        return $response;
    }
}
