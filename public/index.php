<?php

declare(strict_types=1);

/**
 * RecupBuild, front controller: every request of the site comes in here.
 *
 * MVC without a framework: the routes are in src/routes.php, the controllers in
 * src/Controllers, the data in src/Models (SQLite) and the work in src/Services
 * (the model, the cutting engine). The views are the JSON answers of the API and
 * the built frontend (public/index.html).
 */

use RecupBuild\Core\Config;
use RecupBuild\Core\Cors;
use RecupBuild\Core\Router;

require __DIR__ . '/../src/bootstrap.php';

// Two model calls follow each other (sizes, then steps): give them the time,
// however strict the php.ini of the host is.
@set_time_limit(300);

$config = Config::load(dirname(__DIR__) . '/.env');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

Cors::apply($config, $method);

$router = new Router($config);
(require __DIR__ . '/../src/routes.php')($router);
$router->dispatch($method, $path);
