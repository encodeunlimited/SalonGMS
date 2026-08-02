<?php
require 'vendor/autoload.php';
$container = new \DI\ContainerBuilder();
$dependencies = require 'config/dependencies.php';
$dependencies($container);
$c = $container->build();
var_dump($c->has('view'));
var_dump(get_class($c->get('view')));
