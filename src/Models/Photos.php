<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * The photos users send (a heap to scan, an object to rebuild), kept as JPEG files in
 * storage/photos, outside public/: only their owner's pages show them, through a
 * controller. A name is random and never comes from the user.
 *
 * "demo" is the sample photo of the demo, served from public/ like any static file.
 */
final class Photos
{
    public const DEMO = 'demo';

    /** Keeps a prepared JPEG (Services\Image) and returns its name. */
    public static function store(string $jpeg): string
    {
        $folder = self::folder();
        if (!is_dir($folder) && !mkdir($folder, 0770, true) && !is_dir($folder)) {
            throw new \RuntimeException('cannot create the photos folder');
        }
        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (file_put_contents($folder . '/' . $name, $jpeg) === false) {
            throw new \RuntimeException('cannot write the photo');
        }

        return $name;
    }

    /** The file of a stored photo, or null when the name is not one this class gave. */
    public static function path(?string $name): ?string
    {
        if ($name === null || preg_match('/^[a-f0-9]{32}\.jpg$/', $name) !== 1) {
            return null;
        }
        $path = self::folder() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** Sends a stored photo to the browser (the caller checked who may see it). */
    public static function send(?string $name): never
    {
        $path = self::path($name);
        if ($path === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function folder(): string
    {
        return dirname(__DIR__, 2) . '/storage/photos';
    }
}
