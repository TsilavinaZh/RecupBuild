<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/**
 * The sample heap of the demo: a drawn photo (public/demo/sample-pile.svg) and the
 * scan that matches it, outlines in the pixels of that drawing. The demo never calls
 * the model: the projects are the four built-in ones, planned by the engine alone.
 */
final class Demo
{
    public const PHOTO_URL = '/demo/sample-pile.svg';

    /**
     * The scan of the sample photo, in the shape Scan::buildResponse answers.
     *
     * @return array{image: array{width: int, height: int}, pieces: list<array<string, mixed>>}
     */
    public static function scan(): array
    {
        $piece = static fn (int $id, string $kind, float $length, float $width, float $thickness, array $outline): array => [
            'id' => $id,
            'kind' => $kind,
            'length_mm' => $length,
            'width_mm' => $width,
            'thickness_mm' => $thickness,
            'outline' => $outline,
        ];

        return [
            'image' => ['width' => 1200, 'height' => 900],
            'pieces' => [
                $piece(1, 'plank', 1200, 140, 22, [[108.9, 113], [768.5, 90], [771.1, 167], [111.5, 190]]),
                $piece(2, 'plank', 1000, 140, 22, [[126.1, 209.3], [675.9, 223.7], [673.9, 300.7], [124.1, 286.3]]),
                $piece(3, 'plank', 800, 95, 22, [[124.2, 336.6], [564, 321.2], [565.8, 373.4], [126, 388.8]]),
                $piece(4, 'batten', 900, 40, 40, [[153.4, 441.1], [647.7, 467], [646.6, 488.9], [152.3, 463]]),
                $piece(5, 'plywood', 600, 400, 12, [[803.1, 73.8], [1132.3, 96.8], [1116.9, 316.2], [787.7, 293.2]]),
                $piece(6, 'sheet_metal', 700, 500, 0.6, [[584.2, 583.4], [967.1, 543.1], [995.8, 816.6], [612.9, 856.9]]),
                $piece(7, 'plank', 600, 180, 20, [[643.5, 323], [970.3, 368.9], [956.5, 467], [629.7, 421.1]]),
            ],
        ];
    }
}
