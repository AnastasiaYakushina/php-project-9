<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$container = new Container();

$container->set('renderer', function () {
    $renderer = new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    return $renderer;
});

$app = AppFactory::createFromContainer($container);

$app->get('/', function ($request, $response) {
    $params = [
        'flash' => [],
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->run();
