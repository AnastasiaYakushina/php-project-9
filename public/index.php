<?php

require __DIR__ . '/../vendor/autoload.php';

session_start();

use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Views\PhpRenderer;
use Slim\Flash\Messages;
use DI\Container;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Carbon\Carbon;
use Valitron\Validator;

$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            putenv(trim($line));
        }
    }
}

$container = new Container();

$container->set('renderer', function () {
    $renderer = new PhpRenderer(__DIR__ . '/../templates');
    $renderer->setLayout('layout.phtml');
    return $renderer;
});

$container->set(PDO::class, function () {
    $databaseUrl = getenv('DATABASE_URL');

    if ($databaseUrl === false) {
        throw new RuntimeException("Некорректный DATABASE_URL");
    }

    $databaseUrlParams = parse_url($databaseUrl);

    if (!isset($databaseUrlParams['host'])) {
        throw new RuntimeException("Отсутствует хост");
    }

    $dsn = sprintf(
        "pgsql:host=%s;port=%d;dbname=%s",
        $databaseUrlParams['host'],
        $databaseUrlParams['port'] ?? 5432,
        ltrim($databaseUrlParams['path'] ?? '', '/')
    );

    $conn = new PDO(
        $dsn,
        $databaseUrlParams['user'] ?? null,
        $databaseUrlParams['pass'] ?? null
    );

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $conn;
});

$container->set('flash', function () {
    return new Messages();
});

$app = AppFactory::createFromContainer($container);

$router = $app->getRouteCollector()->getRouteParser();

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(false, true, true);

$errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails) use ($app, $container) {
    $response = $app->getResponseFactory()->createResponse();
    $router = $app->getRouteCollector()->getRouteParser();

    $statusCode = 500;
    $template = 'errors/500.phtml';

    if ($exception instanceof HttpNotFoundException) {
        $statusCode = 404;
        $template = 'errors/404.phtml';
    }

    return $container->get('renderer')->render($response->withStatus($statusCode), $template, [
        'message' => $exception->getMessage(),
        'router' => $router
    ]);
});

$app->add(function ($request, $handler) use ($container) {

    $request = $request->withAttribute(
        'flash',
        $container->get('flash')->getMessages()
    );

    return $handler->handle($request);
});

$app->get('/', function ($request, $response) use ($router) {

    $params = [
        'url' => '',
        'errors' => [],
        'flash' => $request->getAttribute('flash'),
        'router' => $router
    ];
    return $this->get('renderer')->render($response, 'index.phtml', $params);
})->setName('home');

$app->get('/urls/{id:\d+}', function ($request, $response, $args) use ($router) {

    $id = $args['id'];

    $pdo = $this->get(PDO::class);
    $urlSql = "SELECT * FROM urls WHERE id = :id";
    $stmt = $pdo->prepare($urlSql);
    $stmt->execute([':id' => $id]);
    $url = $stmt->fetch();

    if (!$url) {
        throw new HttpNotFoundException($request);
    }

    $checksSql = "SELECT * FROM url_checks WHERE url_id = :id ORDER BY created_at DESC";
    $stmt = $pdo->prepare($checksSql);
    $stmt->execute([':id' => $id]);
    $checks = $stmt->fetchAll();

    $params = [
        'url' => $url,
        'errors' => [],
        'checks' => $checks,
        'flash' => $request->getAttribute('flash'),
        'router' => $router
    ];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url');

$app->get('/urls', function ($request, $response) use ($router) {

    $pdo = $this->get(PDO::class);

    $stmt = $pdo->prepare("
        SELECT id, name, created_at
        FROM urls
        ORDER BY id DESC
    ");
    $stmt->execute();
    $urls = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT DISTINCT ON (url_id)
            url_id,
            created_at AS last_check,
            status_code AS last_status_code
        FROM url_checks
        ORDER BY url_id, created_at DESC
    ");
    $stmt->execute();
    $checks = $stmt->fetchAll();

    foreach ($urls as &$url) {
        $urlId = $url['id'];
        $filtered = array_filter($checks, fn($item) => $item['url_id'] == $urlId);

        $check = array_shift($filtered);
        $url['last_check'] = $check['last_check'] ?? null;
        $url['last_status_code'] = $check['last_status_code'] ?? null;
    }

    $params = [
        'urls' => $urls,
        'errors' => [],
        'flash' => $request->getAttribute('flash'),
        'router' => $router
    ];

    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls');

$app->post('/urls/{id:\d+}/checks', function ($request, $response, $args) use ($router) {

    $urlId = $args['id'];

    $pdo = $this->get(PDO::class);
    $urlSql = "SELECT name FROM urls WHERE id = :id";
    $stmt = $pdo->prepare($urlSql);
    $stmt->execute([':id' => $urlId]);
    $normalizedUrl = $stmt->fetchColumn();

    $client = new Client(['timeout' => 5.0, 'http_errors' => true]);

    try {
        $guzzleResponse = $client->get($normalizedUrl);
        $statusCode = $guzzleResponse->getStatusCode();
        $html = (string) $guzzleResponse->getBody();
        $crawler = new Crawler($html);

        $h1 = $crawler->filter('h1')->count() > 0 ? $crawler->filter('h1')->text() : '';
        $title = $crawler->filter('title')->count() > 0 ? $crawler->filter('title')->text() : '';

        $description = $crawler->filter('meta[name="description"]')->count() > 0
        ? $crawler->filter('meta[name="description"]')->attr('content')
        : '';

        $this->get('flash')->addMessage('success', "Страница успешно проверена");
    } catch (Exception $e) {
        $this->get('flash')->addMessage('danger', "Произошла ошибка при проверке, не удалось подключиться");

        return $response->withRedirect($router->urlFor('url', ['id' => $urlId]), 303);
    }

    $createdAt = Carbon::now()->toDateTimeString();
    $sql = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':url_id' => $urlId,
        ':status_code' => $statusCode,
        ':h1' => $h1,
        ':title' => $title,
        ':description' => $description,
        ':created_at' => $createdAt
    ]);

    return $response->withRedirect($router->urlFor('url', ['id' => $urlId]), 303);
})->setName('url_check');

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

    $validator = new Validator(['url' => $normalizedUrl]);

    $validator->rule('required', 'url')->message('URL не должен быть пустым');
    $validator->rule('url', 'url')->message('Некорректный URL');
    $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');

    $pdo = $this->get(PDO::class);

    if ($validator->validate()) {
        $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = :name");
        $stmt->execute([':name' => $normalizedUrl]);
        $existingId = $stmt->fetchColumn();

        if ($existingId) {
            $this->get('flash')->addMessage('danger', "Страница уже существует");
            return $response->withRedirect($router->urlFor('url', ['id' => $existingId]), 303);
        }

        $createdAt = Carbon::now()->toDateTimeString();

        $sql = "INSERT INTO urls (name, created_at) VALUES (:name, :created_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':name' => $normalizedUrl, ':created_at' => $createdAt]);

        $id = $pdo->lastInsertId();

        $this->get('flash')->addMessage('success', "Страница успешно добавлена");

        return $response->withRedirect($router->urlFor('url', ['id' => $id]), 303);
    }

    $params = ['url' => $url,
               'errors' => $validator->errors(),
               'router' => $router
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
})->setName('url_store');

$app->run();
