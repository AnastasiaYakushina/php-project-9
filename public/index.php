<?php

require __DIR__ . '/../vendor/autoload.php';

session_start();

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

    $databaseUrlParams = parse_url($databaseUrl);

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $databaseUrlParams['host'],
        $databaseUrlParams['port'] ?? 5432,
        ltrim($databaseUrlParams['path'], '/')
    );

    $conn = new \PDO($dsn, $databaseUrlParams['user'], $databaseUrlParams['pass']);
    $conn->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    return $conn;
});

$container->set('flash', function () {
    return new Slim\Flash\Messages();
});

$app = AppFactory::createFromContainer($container);

$router = $app->getRouteCollector()->getRouteParser();

$app->get('/', function ($request, $response) {
    $flash = $this->get('flash')->getMessages();

    $params = [
        'url' => '',
        'errors' => [],
        'flash' => $flash,
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('home');

$app->get('/urls/{id}', function ($request, $response, $args) {
    $flash = $this->get('flash')->getMessages();

    $id = $args['id'];

    $pdo = $this->get(\PDO::class);
    $sql = "SELECT * FROM urls WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $url = $stmt->fetch();

    $params = [
        'url' => $url,
        'errors' => [],
        'flash' => $flash,
    ];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url');

$app->get('/urls', function ($request, $response) {
    $flash = $this->get('flash')->getMessages();

    $pdo = $this->get(\PDO::class);
    $sql = "SELECT * FROM urls ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $urls = $stmt->fetchAll();

    $params = [
        'urls' => $urls,
        'errors' => [],
        'flash' => $flash,
    ];
    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls');

$app->post('/urls', function ($request, $response) use ($router) {

    $data = $request->getParsedBody();
    $url = $data['url'] ?? '';

    $parsedUrl = parse_url(strtolower(trim($url)));
    
    $scheme = isset($parsedUrl['scheme']) ? strtolower($parsedUrl['scheme']) : 'http';
    $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';

    if ($host) {
        $normalizedUrl = "{$scheme}://{$host}";
    } else {
        $normalizedUrl = $url;
    }

    $validator = new \Valitron\Validator(['url' => $normalizedUrl]);
  
    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    if ($validator->validate()) {
        try {
            $pdo = $this->get(\PDO::class);
            $createdAt = \Carbon\Carbon::now()->toDateTimeString();
            $sql = "INSERT INTO urls (name, created_at) VALUES (:name, :created_at)";
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':name', $normalizedUrl);
            $stmt->bindParam(':created_at', $createdAt);
            $stmt->execute();

            $id = $pdo->lastInsertId();
            
            $this->get('flash')->addMessage('success', "Страница успешно добавлена");
            return $response->withRedirect($router->urlFor('url', ['id' => $id]), 303);

        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                $this->get('flash')->addMessage('danger', "Страница уже существует");
                return $response->withRedirect($router->urlFor('home'), 303);
            }
        }
    }

    $params = ['url' => $url,
               'errors' => $validator->errors(),
               'flash' => $this->get('flash')->getMessages()
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
});

$app->run();
