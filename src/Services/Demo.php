<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/**
 * The sample heap of the demo: a real photo (public/demo/pile.jpg) and the scan the
 * model made of it once (demo-scan.json, outlines in the pixels of that photo). The demo
 * never calls the model again: the projects are the four built-in ones, planned by the
 * engine alone.
 */
final class Demo
{
    public const PHOTO_URL = '/demo/pile.jpg';

    /**
     * The scan of the sample photo, in the shape Scan::buildResponse answers.
     *
     * @return array{image: array{width: int, height: int}, pieces: list<array<string, mixed>>}
     */
    public static function scan(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/demo-scan.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
