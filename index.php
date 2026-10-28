<?php

declare(strict_types=1);

/**
 * RecupBuild, front controller: every request of the site comes in here.
 *
 * MVC without a framework: the routes are in src/routes.php, the controllers in
 * src/Controllers, the data in src/Models (SQLite) and the work in src/Services
 * (the model, the cutting engine), the pages in src/Views.
 *
 * Locally, from this folder:  php -S localhost:8080   (or: php -S localhost:8080 index.php)
 * The site's own files (css/, js/, fonts/, images/, vendor/, demo/, examples/) sit next to
 * this one. The secrets are in .env.php, which prints nothing if requested, and the
 * database and the photos live outside this folder (Config::storageDir), so nothing
 * private can be served.
 */

use RecupBuild\Core\Config;
use RecupBuild\Core\Cors;
use RecupBuild\Core\Router;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// PHP's built-in server with this file as its router: the site's own files are served
// by the server; any other file of the folder is left to the app, which answers 404.
$asset = rawurldecode($path);
if (PHP_SAPI === 'cli-server' && preg_match('#^/(css|js|fonts|images|vendor|demo|examples)/[^\0]+$|^/favicon\.svg$#', $asset) === 1) {
    $file = realpath(__DIR__ . $asset);
    if ($file !== false && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require __DIR__ . '/src/bootstrap.php';

// Two model calls follow each other (sizes, then steps): give them the time,
// however strict the php.ini of the host is.
@set_time_limit(300);

$config = Config::load(__DIR__ . '/.env.php');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

Cors::apply($config, $method);

$router = new Router($config);
(require __DIR__ . '/src/routes.php')($router);
$router->dispatch($method, $path);
