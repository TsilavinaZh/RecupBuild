<?php

declare(strict_types=1);

/**
 * Autoloader without Composer: the PHP port of the API keeps zero dependencies
 * (only ext-curl, ext-gd, ext-exif and ext-fileinfo are needed).
 */

// The views check this: run directly (PHP's built-in server serves the whole folder),
// they answer 404 instead of an error that would show the server's paths.
const RECUPBUILD = true;

spl_autoload_register(static function (string $class): void {
    $prefix = 'RecupBuild\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});