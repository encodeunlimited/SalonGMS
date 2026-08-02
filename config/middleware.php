<?php

use Slim\App;
use Slim\Views\TwigMiddleware;
use Slim\Views\Twig;

return function (App $app) {
    // Parse json, form data and xml
    $app->addBodyParsingMiddleware();

    // Add the Slim built-in routing middleware
    $app->addRoutingMiddleware();
    
    // Add Twig-View Middleware
    $app->add(TwigMiddleware::createFromContainer($app, Twig::class));

    // Add Error Middleware
    $displayErrorDetails = $app->getContainer()->get('settings')['displayErrorDetails'];
    $app->addErrorMiddleware($displayErrorDetails, true, true);
};
