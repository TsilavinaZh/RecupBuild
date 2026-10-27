<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server (development only):
 *
 *   php -S localhost:8080 router.php
 *
 * Files under public/ are served by this script itself (the document root stays the
 * folder where the command runs), everything else goes to the API in public/index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$public = realpath(__DIR__ . '/public');
$file = realpath($public . $path);

if ($path !== '/' && $file !== false && str_starts_with($file, $public) && is_file($file)) {
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($extension, ['php', 'env', 'ini', 'log'], true)) {
        http_response_code(404);
        exit;
    }

    $types = [
        'avif' => 'image/avif', 'css' => 'text/css; charset=utf-8', 'gif' => 'image/gif',
        'html' => 'text/html; charset=utf-8', 'ico' => 'image/x-icon', 'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg', 'js' => 'text/javascript; charset=utf-8', 'json' => 'application/json',
        'map' => 'application/json', 'png' => 'image/png', 'svg' => 'image/svg+xml',
        'txt' => 'text/plain; charset=utf-8', 'webmanifest' => 'application/manifest+json',
        'webp' => 'image/webp', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    if ($extension !== 'html' && preg_match('/-[A-Za-z0-9_]{8}\.\w+$/', $path) === 1) {
        header('Cache-Control: public, max-age=31536000, immutable'); // Vite hashes its file names
    }
    readfile($file);
    exit;
}

require __DIR__ . '/public/index.php';