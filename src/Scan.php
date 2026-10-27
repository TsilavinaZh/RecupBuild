<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * From the model's answer to the scan response the frontend expects
 * (port of backend/app/scan.py).
 *
 * The A4 sheet (210 x 297 mm) gives the scale: millimetres per pixel are measured on
 * its sides, then every piece's rotated rectangle is converted to millimetres.
 * Thickness cannot be measured from above: the model estimates it from the edges,
 * shadows and the kind of piece, and a typical value of that kind stands in when
 * the estimate is missing or implausible. The user can still correct it.
 */
final class Scan
{
    public const A4_SHORT_MM = 210;
    public const A4_LONG_MM = 297;

    /** @var list<string> */
    public const KINDS = ['plank', 'batten', 'plywood', 'sheet_metal', 'pallet_board', 'other'];

    /**
     * Per kind: the usual thickness of reclaimed stock and what is still plausible, in mm.
     *
     * @var array<string, array{0: float, 1: float, 2: float}>
     */
    public const THICKNESS = [
        'plank' => [22, 8, 60],
        'batten' => [30, 10, 80],
        'plywood' => [12, 3, 30],
        'sheet_metal' => [0.5, 0.2, 3],
        'pallet_board' => [18, 10, 30],
        'other' => [18, 2, 80],
    ];

    /**
     * Validates the model's answer and turns it into the /api/scan response.
     *
     * @param array<string, mixed> $model the model's JSON answer (0-1000 normalized coordinates)
     *
     * @throws ScanError a4_not_detected / empty_segmentation / invalid_json
     */
    public static function buildResponse(array $model, int $width, int $height): array
    {
        $a4 = is_array($model['a4'] ?? null) ? $model['a4'] : [];
        $corners = is_array($a4['corners'] ?? null) ? $a4['corners'] : [];
        if (empty($a4['found']) || count($corners) !== 4) {
            throw new ScanError('a4_not_detected');
        }

        $a4Pixels = self::toPixels($corners, $width, $height);
        [$shortPx, $longPx] = self::sortedSides($a4Pixels);
        if ($shortPx < 10 || $longPx < 10) {
            throw new ScanError('a4_not_detected');
        }
        $mmPerPx = (self::A4_SHORT_MM / $shortPx + self::A4_LONG_MM / $longPx) / 2;

        $pieces = [];
        foreach (is_array($model['pieces'] ?? null) ? $model['pieces'] : [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $outline = is_array($raw['corners'] ?? null) ? $raw['corners'] : [];
            if (count($outline) !== 4) {
                continue;
            }
            $points = self::toPixels($outline, $width, $height);
            [$a, $b] = self::sides($points);
            $length = max($a, $b) * $mmPerPx;
            $widthMm = min($a, $b) * $mmPerPx;
            if ($widthMm < 10) {
                continue; // a line, not a piece
            }
            $kind = in_array($raw['kind'] ?? null, self::KINDS, true) ? $raw['kind'] : 'other';

            $pieces[] = [
                'id' => count($pieces) + 1,
                'kind' => $kind,
                'length_mm' => self::roundMm($length),
                'width_mm' => self::roundMm($widthMm),
                'thickness_mm' => self::thickness($kind, $raw['thickness_mm'] ?? null, $widthMm),
                'outline' => $points,
            ];
        }

        if ($pieces === []) {
            throw new ScanError('empty_segmentation');
        }

        return [
            'image' => ['width' => $width, 'height' => $height],
            'a4' => ['detected' => true, 'corners' => $a4Pixels, 'mm_per_px' => round($mmPerPx, 4)],
            'pieces' => $pieces,
        ];
    }

    /**
     * Normalized 0-1000 coordinates to pixels, clamped to the grid like the Python version.
     *
     * @param list<mixed> $corners
     *
     * @return list<array{0: float, 1: float}>
     *
     * @throws ScanError invalid_json when a point is not a pair of numbers
     */
    private static function toPixels(array $corners, int $width, int $height): array
    {
        $points = [];
        foreach ($corners as $point) {
            if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])) {
                throw new ScanError('invalid_json');
            }
            $x = (float) $point[0];
            $y = (float) $point[1];
            $points[] = [
                round(min(max($x, 0), 1000) * $width / 1000, 1),
                round(min(max($y, 0), 1000) * $height / 1000, 1),
            ];
        }

        return $points;
    }

    /**
     * The two side lengths of a quadrilateral, each averaged over its opposite pair.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return array{0: float, 1: float}
     */
    private static function sides(array $points): array
    {
        $edge = [];
        for ($i = 0; $i < 4; $i++) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % 4];
            $edge[] = hypot($x2 - $x1, $y2 - $y1);
        }

        return [($edge[0] + $edge[2]) / 2, ($edge[1] + $edge[3]) / 2];
    }

    /** @param list<array{0: float, 1: float}> $points */
    private static function sortedSides(array $points): array
    {
        $sides = self::sides($points);
        sort($sides);

        return $sides;
    }

    /** To the nearest 5 mm: a photo is not more precise than that. */
    /**
     * The model's thickness estimate when it is plausible for that kind of piece,
     * otherwise the usual thickness of that kind. Never thicker than the piece is wide.
     */
    public static function thickness(string $kind, mixed $estimate, float $widthMm): float
    {
        [$usual, $low, $high] = self::THICKNESS[$kind];
        $value = is_numeric($estimate) && (float) $estimate >= $low && (float) $estimate <= $high ? (float) $estimate : (float) $usual;
        $value = min($value, max($low, $widthMm));

        // Wood to the millimetre, metal to the tenth.
        return $kind === 'sheet_metal' ? round($value, 1) : round($value);
    }

    private static function roundMm(float $value): int
    {
        return (int) (5 * round($value / 5));
    }
}