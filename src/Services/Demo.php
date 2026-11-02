<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/**
 * The sample heap of the demo: a real photo (demo/pile.jpg) and the scan the
 * model made of it once (demo-scan.json, outlines in the pixels of that photo). The demo
 * never calls the model again: the engine alone plans the projects kept for that heap.
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

    /**
     * The projects the model invented once for the sample heap (demo-projects.json), with
     * the sizes, steps and 3D layout it gave them: small offcuts fit none of the built-in
     * projects. The engine still checks them at every visit; the demo calls no model.
     *
     * @return list<array{definition: array<string, mixed>, params: array<string, int>, steps: list<string>, assembly: list<array<string, mixed>>}>
     */
    public static function projects(string $locale): array
    {
        $projects = json_decode((string) file_get_contents(__DIR__ . '/demo-projects.json'), true, flags: JSON_THROW_ON_ERROR);

        return $projects[$locale] ?? $projects['fr'];
    }
}
