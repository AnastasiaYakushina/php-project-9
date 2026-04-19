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
    $urlSql = "SELECT * FROM urls WHERE id = :id";
    $stmt = $pdo->prepare($urlSql);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $url = $stmt->fetch();

    try {
        $checksSql = "SELECT * FROM url_checks WHERE url_id = :id ORDER BY created_at DESC";
        $stmt = $pdo->prepare($checksSql);
        $stmt->execute([':id' => $id]);
        $checks = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $checks = [];
    }

    $params = [
        'url' => $url,
        'errors' => [],
        'flash' => $flash,
        'checks' => $checks,
    ];
    return $this->get('renderer')->render($response, 'urls/show.phtml', $params);
})->setName('url');

$app->get('/urls', function ($request, $response) {
    $flash = $this->get('flash')->getMessages();

    $pdo = $this->get(\PDO::class);
    $sql = "SELECT DISTINCT ON (urls.id)
            urls.id,
            urls.name,
            urls.created_at,
            url_checks.created_at AS last_check,
            url_checks.status_code AS last_status_code
            FROM urls
            LEFT JOIN url_checks ON urls.id = url_checks.url_id
            ORDER BY urls.id, url_checks.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $urls = $stmt->fetchAll();

    usort($urls, function ($a, $b) {
        $dateA = $a['last_check'] ?? '0000-00-00 00:00:00';
        $dateB = $b['last_check'] ?? '0000-00-00 00:00:00';
        return strcmp($dateB, $dateA);
    });

    $params = [
        'urls' => $urls,
        'errors' => [],
        'flash' => $flash,
    ];
    return $this->get('renderer')->render($response, 'urls/index.phtml', $params);
})->setName('urls');

$app->post('/urls/{id}/checks', function ($request, $response, $args) use ($router) {

    $url_id = $args['id'];

    $pdo = $this->get(\PDO::class);
    $urlSql = "SELECT name FROM urls WHERE id = :id";
    $stmt = $pdo->prepare($urlSql);
    $stmt->execute([':id' => $url_id]);
    $normalizedUrl = $stmt->fetchColumn();

    $client = new \GuzzleHttp\Client(['timeout' => 5.0]);

    $statusCode = null;
    $h1 = '';
    $title = '';
    $description = '';

    try {
        $guzzleResponse = $client->get($normalizedUrl);
        $statusCode = $guzzleResponse->getStatusCode();
        $html = (string) $guzzleResponse->getBody();
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        $h1 = optional($crawler->filter('h1')->getNode(0))->textContent;
        $title = optional($crawler->filter('title')->getNode(0))->textContent;
        $description = optional($crawler->filter('meta[name="description"]')->getNode(0))->getAttribute('content');

        $this->get('flash')->addMessage('success', "Страница успешно проверена");
    } catch (\GuzzleHttp\Exception\BadResponseException $e) {
        $statusCode = $e->getResponse()->getStatusCode();
        $this->get('flash')->addMessage('success', "Страница успешно проверена");
    } catch (\GuzzleHttp\Exception\TransferException $e) {
        $this->get('flash')->addMessage('danger', "Произошла ошибка при проверке, не удалось подключиться");
        return $response->withRedirect($router->urlFor('url', ['id' => $url_id]), 303);
    } catch (\Exception $e) {
        $this->get('flash')->addMessage('danger', "Произошла ошибка при проверке, не удалось подключиться");
        return $response->withRedirect($router->urlFor('url', ['id' => $url_id]), 303);
    }

    if ($statusCode !== null) {
        $createdAt = \Carbon\Carbon::now()->toDateTimeString();
        $sql = "INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                VALUES (:url_id, :status_code, :h1, :title, :description, :created_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
           ':url_id' => $url_id,
           ':status_code' => $statusCode,
           ':h1' => $h1,
           ':title' => $title,
           ':description' => $description,
           ':created_at' => $createdAt
        ]);
    }

    return $response->withRedirect($router->urlFor('url', ['id' => $url_id]), 303);
});

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
            $stmt->execute([':name' => $normalizedUrl, ':created_at' => $createdAt]);

            $id = $pdo->lastInsertId();

            $this->get('flash')->addMessage('success', "Страница успешно добавлена");
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                $stmt = $pdo->prepare("SELECT id FROM urls WHERE name = :name");
                $stmt->execute([':name' => $normalizedUrl]);
                $id = $stmt->fetchColumn();

                $this->get('flash')->addMessage('danger', "Страница уже существует");
            }
        }
        return $response->withRedirect($router->urlFor('url', ['id' => $id]), 303);
    }

    $params = ['url' => $url,
               'errors' => $validator->errors(),
               'flash' => $this->get('flash')->getMessages()
    ];
    return $this->get('renderer')->render($response->withStatus(422), 'index.phtml', $params);
});

$app->run();
