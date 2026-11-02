<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Photos whose true sizes are known: a tape measure cannot be held to a picture, so these
 * are composed. Real wood and metal, cut from demo/pile.jpg, are laid on real concrete from
 * the same photo at chosen sizes and small angles, each with a shadow, next to an A4 sheet.
 * The seed makes every scene the same at every run.
 */
final class Scenes
{
    public const WIDTH = 1600;
    public const HEIGHT = 1100;

    /** The true scale of every scene. */
    public const PIXELS_PER_MM = 0.7;

    /** An area of bare concrete on demo/pile.jpg: left, top, width, height. */
    private const CONCRETE = [825, 10, 200, 340];

    /**
     * Where the materials are on demo/pile.jpg, the wood standing: left, top, width, height.
     *
     * @var array<string, list<array{0: int, 1: int, 2: int, 3: int}>>
     */
    private const TEXTURES = [
        'plank' => [[45, 30, 70, 410], [165, 35, 70, 375], [280, 35, 55, 375], [390, 65, 35, 310]],
        'pallet_board' => [[50, 505, 65, 125], [160, 480, 65, 145], [275, 475, 65, 145], [400, 460, 55, 160]],
        'batten' => [[468, 70, 20, 335]],
        'plywood' => [[550, 92, 245, 230]],
        'sheet_metal' => [[548, 368, 255, 270]],
    ];

    /** @var array<string, array{length: array{0: int, 1: int}, width: array{0: int, 1: int}}> sizes in mm */
    private const SIZES = [
        'plank' => ['length' => [450, 1200], 'width' => [90, 200]],
        'pallet_board' => ['length' => [300, 800], 'width' => [80, 140]],
        'batten' => ['length' => [500, 1100], 'width' => [35, 60]],
        'plywood' => ['length' => [350, 700], 'width' => [300, 450]],
        'sheet_metal' => ['length' => [350, 700], 'width' => [300, 450]],
    ];

    /**
     * One scene and what is really in it.
     *
     * @return array{jpeg: string, pieces: list<array{kind: string, length_mm: int, width_mm: int, x: float, y: float}>}
     */
    public static function make(int $seed, string $photoPath): array
    {
        mt_srand($seed);
        $source = imagecreatefromjpeg($photoPath);
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        self::lay($canvas, $source);

        $taken = [];
        $sheetWidth = (int) round(210 * self::PIXELS_PER_MM);
        $sheetHeight = (int) round(297 * self::PIXELS_PER_MM);
        $sheet = imagecreatetruecolor($sheetWidth, $sheetHeight);
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 244, 244, 248));
        self::place($canvas, $sheet, 0, $taken);

        $pieces = [];
        $wanted = ['plywood', 'sheet_metal', 'batten', 'plank', 'plank', 'plank', 'pallet_board', 'pallet_board', 'batten'];
        foreach ($wanted as $kind) {
            $size = self::SIZES[$kind];
            $length = 5 * (int) (mt_rand(...$size['length']) / 5);
            $width = 5 * (int) (mt_rand(...$size['width']) / 5);
            $choices = self::TEXTURES[$kind];
            [$left, $top, $wide, $high] = $choices[mt_rand(0, count($choices) - 1)];

            // Standing like the texture, then turned a few degrees, or lying flat.
            $piece = imagecreatetruecolor((int) round($width * self::PIXELS_PER_MM), (int) round($length * self::PIXELS_PER_MM));
            imagecopyresampled($piece, $source, 0, 0, $left, $top, imagesx($piece), imagesy($piece), $wide, $high);
            $spot = self::place($canvas, $piece, (mt_rand(0, 1) * 90) + mt_rand(-12, 12), $taken);
            if ($spot !== null) {
                $pieces[] = ['kind' => $kind, 'length_mm' => max($length, $width), 'width_mm' => min($length, $width), 'x' => $spot[0], 'y' => $spot[1]];
            }
        }

        ob_start();
        imagejpeg($canvas, null, 90);

        return ['jpeg' => (string) ob_get_clean(), 'pieces' => $pieces];
    }

    /** Covers the canvas with concrete, mirrored at each tile so no seam shows. */
    private static function lay(\GdImage $canvas, \GdImage $source): void
    {
        [$left, $top, $wide, $high] = self::CONCRETE;
        $scale = 2;
        for ($y = 0, $row = 0; $y < self::HEIGHT; $y += $high * $scale, ++$row) {
            for ($x = 0, $column = 0; $x < self::WIDTH; $x += $wide * $scale, ++$column) {
                imagecopyresampled(
                    $canvas,
                    $source,
                    $x,
                    $y,
                    $column % 2 === 0 ? $left : $left + $wide - 1,
                    $row % 2 === 0 ? $top : $top + $high - 1,
                    $wide * $scale,
                    $high * $scale,
                    $column % 2 === 0 ? $wide : -$wide,
                    $row % 2 === 0 ? $high : -$high,
                );
            }
        }
    }

    /**
     * Lays an image at a free spot, turned, with a soft shadow.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $taken
     *
     * @return ?array{0: float, 1: float} its centre, or null when it finds no room
     */
    private static function place(\GdImage $canvas, \GdImage $item, int $degrees, array &$taken): ?array
    {
        imagealphablending($item, true);
        $clear = imagecolorallocatealpha($item, 0, 0, 0, 127);
        $turned = imagerotate($item, $degrees, $clear);
        imagesavealpha($turned, true);
        $wide = imagesx($turned);
        $high = imagesy($turned);

        $gap = 40;
        for ($attempt = 0; $attempt < 400; ++$attempt) {
            $x = mt_rand(30, self::WIDTH - $wide - 30);
            $y = mt_rand(30, self::HEIGHT - $high - 30);
            foreach ($taken as [$left, $top, $right, $bottom]) {
                if ($x < $right + $gap && $x + $wide + $gap > $left && $y < $bottom + $gap && $y + $high + $gap > $top) {
                    continue 2;
                }
            }

            $shadow = imagecreatetruecolor($wide, $high);
            imagesavealpha($shadow, true);
            imagealphablending($shadow, false);
            for ($row = 0; $row < $high; ++$row) {
                for ($column = 0; $column < $wide; ++$column) {
                    $alpha = (imagecolorat($turned, $column, $row) >> 24) & 0x7F;
                    imagesetpixel($shadow, $column, $row, imagecolorallocatealpha($shadow, 0, 0, 0, $alpha === 127 ? 127 : 70));
                }
            }
            imagecopy($canvas, $shadow, $x + 8, $y + 8, 0, 0, $wide, $high);
            imagecopy($canvas, $turned, $x, $y, 0, 0, $wide, $high);
            $taken[] = [(float) $x, (float) $y, (float) ($x + $wide), (float) ($y + $high)];

            return [$x + $wide / 2, $y + $high / 2];
        }

        return null;
    }
}
