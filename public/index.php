<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        putenv(trim($line));
    }
}

$container = new Container();

$container->set('renderer', function () {
    $renderer = new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    return $renderer;
});

$container->set(\PDO::class, function () {
    $databaseUrl = getenv('DATABASE_URL');

    $databaseUrl = parse_url($databaseUrl);

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $databaseUrl['host'],
        $databaseUrl['port'] ?? 5432,
        ltrim($databaseUrl['path'], '/')
    );

    $conn = new \PDO($dsn, $databaseUrl['user'], $databaseUrl['pass']);
    $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $conn;
});

$app = AppFactory::createFromContainer($container);

$app->get('/', function ($request, $response) {
    $params = [
        'flash' => [],
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
});

$app->run();
