<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Core\Config;
use RecupBuild\Errors\VisionError;

/**
 * SAM 3.1 (Meta Model API) sharpens what Muse Spark found. Muse Spark knows what each piece
 * is and how thick it looks, but its corners can sit tens of pixels off; SAM traces the real
 * edges. So SAM is asked for every piece of wood, every metal sheet and the sheet of paper,
 * each trace becomes the tightest rotated rectangle around it, and each piece of Muse Spark
 * takes the corners of the trace that overlaps it most. A piece SAM sees and Muse Spark
 * missed joins the inventory. The A4 sheet is measured on its real edges the same way.
 *
 * This step only refines: when SAM fails, or a trace does not look like the piece it would
 * replace, the scan keeps the corners of Muse Spark.
 *
 * Docs: https://dev.meta.ai/docs/sam/segmenting and /docs/sam/reading-segmentation.
 */
final class Segmentation
{
    public const MODEL = 'sam-3.1';

    /** @var array<string, string> what to look for, in SAM's short noun phrases */
    private const CONCEPTS = ['paper' => 'white sheet of paper', 'wood' => 'wood', 'metal' => 'metal sheet'];

    private const MIN_CONFIDENCE = 0.5;

    /** Only a sure trace adds a piece Muse Spark did not list. */
    private const NEW_PIECE_CONFIDENCE = 0.8;

    /** Share of the smaller box two objects must have in common to be the same object. */
    private const SAME_OBJECT = 0.3;
    private const DUPLICATE = 0.6;

    /** A trace this far from the size of the piece is another object. */
    private const MIN_AREA_RATIO = 0.45;
    private const MAX_AREA_RATIO = 1.8;

    /** A thin piece can miss its own trace altogether: same shape, this close (share of its length). */
    private const NEAR = 0.35;
    private const SAME_SHAPE = 0.6;

    private const A4_RATIO_LOW = 1.15;
    private const A4_RATIO_HIGH = 1.7;

    /**
     * The answer of Muse Spark with sharper corners (same 0-1000 grid), and how many
     * objects SAM changed in $model['refined'].
     *
     * @param array<string, mixed> $model the answer of Vision::analyse
     *
     * @return array<string, mixed>
     */
    public static function refine(array $model, string $jpeg, int $width, int $height, Config $config): array
    {
        $model['refined'] = 0;
        $found = [];
        foreach (self::CONCEPTS as $concept => $phrase) {
            try {
                $found[$concept] = self::objects(self::ask($jpeg, $phrase, $config));
            } catch (VisionError $error) {
                error_log('[recupbuild] SAM skipped: ' . $error->getMessage());

                return $model;
            }
        }

        return self::merge($model, $found, $width, $height);
    }

    /**
     * Applies the traces of SAM to the answer of Muse Spark.
     *
     * @param array<string, mixed> $model the answer of Vision::analyse
     * @param array<string, list<array<string, mixed>>> $found the objects of each concept, from objects()
     *
     * @return array<string, mixed>
     */
    public static function merge(array $model, array $found, int $width, int $height): array
    {
        $model['refined'] ??= 0;
        $toGrid = static fn (array $corners): array => array_map(static fn (array $point): array => [
            round($point[0] * 1000 / $width, 2),
            round($point[1] * 1000 / $height, 2),
        ], $corners);

        $a4 = self::sheet($found['paper'] ?? [], self::box($model['a4']['corners'] ?? [], $width, $height));
        if ($a4 !== null) {
            $model['a4'] = ['found' => true, 'corners' => $toGrid($a4['corners'])];
            ++$model['refined'];
        }

        // The sheet itself is flat and pale: never a piece, whatever SAM calls it.
        $traces = [];
        foreach (['wood' => 'plank', 'metal' => 'sheet_metal'] as $concept => $kind) {
            foreach ($found[$concept] ?? [] as $object) {
                if ($a4 === null || self::overlap($object['box'], $a4['box']) < self::DUPLICATE) {
                    $traces[] = $object + ['kind' => $kind];
                }
            }
        }
        usort($traces, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $pieces = is_array($model['pieces'] ?? null) ? array_values(array_filter($model['pieces'], 'is_array')) : [];
        $pairs = [];
        foreach ($pieces as $index => $piece) {
            $box = self::box($piece['corners'] ?? [], $width, $height);
            foreach ($box === null ? [] : $traces as $at => $trace) {
                $score = self::overlap($box['box'], $trace['box']);
                $ratio = $box['area'] > 0 ? $trace['area'] / $box['area'] : 0;
                if ($score >= self::SAME_OBJECT && $ratio >= self::MIN_AREA_RATIO && $ratio <= self::MAX_AREA_RATIO) {
                    $pairs[] = [$score, $index, $at];
                }
            }
        }
        rsort($pairs);

        $usedPieces = [];
        $usedTraces = [];
        foreach ($pairs as [, $index, $at]) {
            if (isset($usedPieces[$index]) || isset($usedTraces[$at])) {
                continue;
            }
            $usedPieces[$index] = true;
            $usedTraces[$at] = true;
            $pieces[$index]['corners'] = $toGrid($traces[$at]['corners']);
            ++$model['refined'];
        }

        // A piece still alone: its trace may be next to it, the same shape.
        foreach ($pieces as $index => $piece) {
            $box = isset($usedPieces[$index]) ? null : self::box($piece['corners'] ?? [], $width, $height);
            if ($box === null) {
                continue;
            }
            $nearest = null;
            $distance = self::NEAR * max($box['sides']);
            foreach ($traces as $at => $trace) {
                $away = hypot(($box['box'][0] + $box['box'][2] - $trace['box'][0] - $trace['box'][2]) / 2, ($box['box'][1] + $box['box'][3] - $trace['box'][1] - $trace['box'][3]) / 2);
                if (!isset($usedTraces[$at]) && $away <= $distance && self::sameShape($box['sides'], self::sides($trace['corners']))) {
                    $distance = $away;
                    $nearest = $at;
                }
            }
            if ($nearest !== null) {
                $usedPieces[$index] = true;
                $usedTraces[$nearest] = true;
                $pieces[$index]['corners'] = $toGrid($traces[$nearest]['corners']);
                ++$model['refined'];
            }
        }

        // What SAM traced and Muse Spark did not list, once: wood and metal can overlap.
        $taken = array_map(static fn (int $at): array => $traces[$at]['box'], array_keys($usedTraces));
        foreach ($pieces as $index => $piece) {
            if (!isset($usedPieces[$index]) && ($box = self::box($piece['corners'] ?? [], $width, $height)) !== null) {
                $taken[] = $box['box'];
            }
        }
        foreach ($traces as $at => $trace) {
            if (isset($usedTraces[$at]) || $trace['confidence'] < self::NEW_PIECE_CONFIDENCE) {
                continue;
            }
            foreach ($taken as $box) {
                if (self::overlap($box, $trace['box']) >= self::DUPLICATE) {
                    continue 2;
                }
            }
            $taken[] = $trace['box'];
            $pieces[] = ['kind' => $trace['kind'], 'corners' => $toGrid($trace['corners']), 'thickness_mm' => null];
            ++$model['refined'];
        }

        $model['pieces'] = $pieces;

        return $model;
    }

    /**
     * The sheet of paper among SAM's objects: A4 proportions, and the one on Muse Spark's
     * sheet when there is one.
     *
     * @param list<array<string, mixed>> $objects
     * @param ?array{box: array{0: float, 1: float, 2: float, 3: float}, area: float, sides: array{0: float, 1: float}} $spark
     *
     * @return ?array<string, mixed>
     */
    private static function sheet(array $objects, ?array $spark): ?array
    {
        $best = null;
        $bestScore = -1.0;
        foreach ($objects as $object) {
            [$short, $long] = self::sides($object['corners']);
            $ratio = $short > 0 ? $long / $short : 0;
            if ($ratio < self::A4_RATIO_LOW || $ratio > self::A4_RATIO_HIGH) {
                continue;
            }
            $score = $object['confidence'] + ($spark === null ? 0 : self::overlap($object['box'], $spark['box']));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $object;
            }
        }

        return $best;
    }

    /**
     * The axis-aligned box, the area and the sides of four corners on the 0-1000 grid, in pixels.
     *
     * @param mixed $corners
     *
     * @return ?array{box: array{0: float, 1: float, 2: float, 3: float}, area: float, sides: array{0: float, 1: float}}
     */
    private static function box(mixed $corners, int $width, int $height): ?array
    {
        if (!is_array($corners) || count($corners) !== 4) {
            return null;
        }
        $points = [];
        foreach ($corners as $point) {
            if (!is_array($point) || !is_numeric($point[0] ?? null) || !is_numeric($point[1] ?? null)) {
                return null;
            }
            $points[] = [(float) $point[0] * $width / 1000, (float) $point[1] * $height / 1000];
        }

        return ['box' => self::bounds($points), 'area' => self::area($points), 'sides' => self::sides($points)];
    }

    /**
     * The short and the long side of four corners in order around a rectangle.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return array{0: float, 1: float}
     */
    private static function sides(array $points): array
    {
        [$a, $b, $c] = $points;
        $first = hypot($b[0] - $a[0], $b[1] - $a[1]);
        $second = hypot($c[0] - $b[0], $c[1] - $b[1]);

        return [min($first, $second), max($first, $second)];
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function sameShape(array $a, array $b): bool
    {
        return $a[0] > 0 && $b[0] > 0 && $a[1] > 0 && $b[1] > 0
            && min($a[0], $b[0]) / max($a[0], $b[0]) >= self::SAME_SHAPE
            && min($a[1], $b[1]) / max($a[1], $b[1]) >= self::SAME_SHAPE;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     *
     * @return array{0: float, 1: float, 2: float, 3: float} left, top, right, bottom
     */
    private static function bounds(array $points): array
    {
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * The share of the smaller box covered by the other: an inner piece counts as the same.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     */
    private static function overlap(array $a, array $b): float
    {
        $across = min($a[2], $b[2]) - max($a[0], $b[0]);
        $down = min($a[3], $b[3]) - max($a[1], $b[1]);
        if ($across <= 0 || $down <= 0) {
            return 0.0;
        }
        $smaller = min(($a[2] - $a[0]) * ($a[3] - $a[1]), ($b[2] - $b[0]) * ($b[3] - $b[1]));

        return $smaller > 0 ? $across * $down / $smaller : 0.0;
    }

    /** @throws VisionError */
    private static function ask(string $jpeg, string $phrase, Config $config): string
    {
        $answer = Vision::post('/responses', [
            'model' => self::MODEL,
            'input' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'input_text', 'text' => $phrase],
                    ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,' . base64_encode($jpeg)],
                ],
            ]],
            'metadata' => ['include_confidence' => 'true', 'mask_encoding' => 'one_bit'],
        ], $config);

        $text = '';
        foreach (is_array($answer['output'] ?? null) ? $answer['output'] : [] as $item) {
            foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $part) {
                $text .= is_string($part['text'] ?? null) ? $part['text'] : '';
            }
        }

        return $text;
    }

    /**
     * Every sure object of an answer as a rotated rectangle, in pixels of the photo.
     *
     * @return list<array{corners: list<array{0: float, 1: float}>, box: array{0: float, 1: float, 2: float, 3: float}, area: float, confidence: float}>
     */
    public static function objects(string $text): array
    {
        $objects = [];
        foreach (self::parse($text) as $record) {
            if ($record['confidence'] < self::MIN_CONFIDENCE) {
                continue;
            }
            try {
                $mask = SamMask::decode($record['payload'], $record['width'], $record['height']);
            } catch (\RuntimeException $error) {
                error_log('[recupbuild] SAM mask skipped: ' . $error->getMessage());
                continue;
            }
            $corners = self::tightestRectangle(self::edgePoints($mask, $record['width'], $record['height'], $record['left'], $record['top']));
            if ($corners !== null) {
                $objects[] = ['corners' => $corners, 'box' => self::bounds($corners), 'area' => self::area($corners), 'confidence' => $record['confidence']];
            }
        }

        return $objects;
    }

    /**
     * The records of an image answer: a box, then a mask cropped to that box.
     *
     * @return list<array{left: int, top: int, confidence: float, height: int, width: int, payload: string}>
     */
    public static function parse(string $text): array
    {
        $records = [];
        preg_match_all('/\d+<\|box;([^|]*)\|><\|mask;([^|]*)\|>/', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as [, $box, $mask]) {
            $fields = [];
            foreach (explode(';', $box) as $field) {
                [$key, $value] = array_pad(explode('=', $field, 2), 2, '');
                $fields[$key] = $value;
            }
            if (!preg_match('/(?:^|;)data=(\d+),(\d+),([^;|]+)$/', $mask, $data) || !isset($fields['x1'], $fields['y1'])) {
                continue;
            }
            $records[] = [
                'left' => (int) $fields['x1'],
                'top' => (int) $fields['y1'],
                'confidence' => (float) ($fields['c'] ?? 1),
                'height' => (int) $data[1],
                'width' => (int) $data[2],
                'payload' => $data[3],
            ];
        }

        return $records;
    }

    /**
     * The outer corners of the first and last pixels of every row of the mask.
     *
     * @param list<int> $mask
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function edgePoints(array $mask, int $width, int $height, int $left, int $top): array
    {
        $points = [];
        for ($y = 0; $y < $height; ++$y) {
            $first = null;
            $last = null;
            for ($x = 0; $x < $width; ++$x) {
                if ($mask[$y * $width + $x] === 1) {
                    $first ??= $x;
                    $last = $x;
                }
            }
            if ($first !== null) {
                array_push(
                    $points,
                    [(float) ($left + $first), (float) ($top + $y)],
                    [(float) ($left + $first), (float) ($top + $y + 1)],
                    [(float) ($left + $last + 1), (float) ($top + $y)],
                    [(float) ($left + $last + 1), (float) ($top + $y + 1)],
                );
            }
        }

        return $points;
    }

    /**
     * Rotating calipers: the smallest rectangle around a set of points has one side on an
     * edge of their convex hull, so each edge is tried.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return ?list<array{0: float, 1: float}> four corners in order around the rectangle
     */
    public static function tightestRectangle(array $points): ?array
    {
        $hull = self::convexHull($points);
        $count = count($hull);
        if ($count < 3) {
            return null;
        }

        $best = null;
        $bestArea = INF;
        for ($i = 0; $i < $count; ++$i) {
            [$x1, $y1] = $hull[$i];
            [$x2, $y2] = $hull[($i + 1) % $count];
            $length = hypot($x2 - $x1, $y2 - $y1);
            if ($length == 0.0) {
                continue;
            }
            $ux = ($x2 - $x1) / $length;
            $uy = ($y2 - $y1) / $length;

            $minU = INF;
            $maxU = -INF;
            $minV = INF;
            $maxV = -INF;
            foreach ($hull as [$x, $y]) {
                $u = $x * $ux + $y * $uy;
                $v = $y * $ux - $x * $uy;
                $minU = min($minU, $u);
                $maxU = max($maxU, $u);
                $minV = min($minV, $v);
                $maxV = max($maxV, $v);
            }

            $area = ($maxU - $minU) * ($maxV - $minV);
            if ($area < $bestArea) {
                $bestArea = $area;
                $best = array_map(
                    static fn (array $c): array => [$c[0] * $ux - $c[1] * $uy, $c[0] * $uy + $c[1] * $ux],
                    [[$minU, $minV], [$maxU, $minV], [$maxU, $maxV], [$minU, $maxV]],
                );
            }
        }

        return $best;
    }

    /**
     * Andrew's monotone chain.
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function convexHull(array $points): array
    {
        usort($points, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $points = array_values(array_unique($points, SORT_REGULAR));
        if (count($points) < 3) {
            return $points;
        }

        $half = static function (array $points): array {
            $chain = [];
            foreach ($points as $point) {
                while (($size = count($chain)) >= 2) {
                    [$ox, $oy] = $chain[$size - 2];
                    [$ax, $ay] = $chain[$size - 1];
                    if (($ax - $ox) * ($point[1] - $oy) - ($ay - $oy) * ($point[0] - $ox) > 0) {
                        break;
                    }
                    array_pop($chain);
                }
                $chain[] = $point;
            }
            array_pop($chain);

            return $chain;
        };

        return array_merge($half($points), $half(array_reverse($points)));
    }

    /**
     * Shoelace formula.
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private static function area(array $points): float
    {
        $sum = 0.0;
        $count = count($points);
        for ($i = 0; $i < $count; ++$i) {
            [$x1, $y1] = $points[$i];
            [$x2, $y2] = $points[($i + 1) % $count];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return abs($sum) / 2;
    }
}
