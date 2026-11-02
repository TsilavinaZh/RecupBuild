<?php

declare(strict_types=1);

/**
 * The app as a stranger's copy of the repository runs it: no .env.php, so no model key
 * and no Google sign-in, and its data in a temporary folder. Started by tests/demo_test.php.
 */

use RecupBuild\Core\Config;
use RecupBuild\Core\Router;

$database = getenv('RECUPBUILD_TEST_DATABASE');
if ($database === false) {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$config = new Config();
$config->databasePath = $database;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$router = new Router($config);
(require dirname(__DIR__, 2) . '/src/routes.php')($router);
$router->dispatch(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), is_string($path) ? $path : '/');
