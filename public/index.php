<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$container = new Container();
$app = AppFactory::createFromContainer($container);

$app->get('/', function ($request, $response) {
    $response->getBody()->write("Hello, Slim!");
    return $response;
});

$app->run();
