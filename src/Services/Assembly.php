<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Models\Projects;

/**
 * How the cut parts go together, for the 3D view and the card previews: per placed
 * part, the corner of its box (mm; x right, y up, z towards the viewer) and the axes
 * its length and width lie along. The model gives it for the projects it invents;
 * the four built-in projects have their own layouts here.
 */
final class Assembly
{
    /**
     * The layout of a plan: the model's when it gave one, the built-in one otherwise,
     * null when there is none.
     *
     * @param array{project: string, params: array<string, int>, placements: list<array<string, mixed>>} $plan
     * @param ?list<array<string, mixed>> $fromModel
     *
     * @return ?list<array{number: int, x: float, y: float, z: float, length_axis: string, width_axis: string}>
     */
    public static function of(array $plan, ?array $fromModel): ?array
    {
        if ($fromModel !== null && $fromModel !== []) {
            return $fromModel;
        }
        if (!in_array($plan['project'], Projects::IDS, true)) {
            return null;
        }

        $byPart = [];
        $placements = $plan['placements'];
        usort($placements, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);
        foreach ($placements as $placement) {
            $byPart[$placement['part']][] = $placement;
        }
        $thick = static fn (string $part): float => (float) ($byPart[$part][0]['thickness_mm'] ?? 20);
        $spots = self::layout($plan['project'], $plan['params'], $thick);

        $items = [];
        foreach ($byPart as $part => $list) {
            foreach ($list as $index => $placement) {
                $spot = $spots[$part][$index] ?? null;
                if ($spot !== null) {
                    $items[] = ['number' => $placement['number']] + $spot;
                }
            }
        }

        return $items === [] ? null : $items;
    }

    /**
     * Per part name, one spot per copy, in placement order.
     *
     * @param callable(string): float $thick the thickness of a part
     *
     * @return array<string, list<array{x: float, y: float, z: float, length_axis: string, width_axis: string}>>
     */
    private static function layout(string $project, array $p, callable $thick): array
    {
        $spot = static fn (float $x, float $y, float $z, string $length, string $width): array => [
            'x' => $x, 'y' => $y, 'z' => $z, 'length_axis' => $length, 'width_axis' => $width,
        ];

        return match ($project) {
            'shelf' => [
                'side' => [$spot(-$thick('side'), 0, 0, 'y', 'z'), $spot($p['width'], 0, 0, 'y', 'z')],
                'board' => array_map(static fn (float $y): array => $spot(0, $y, 0, 'x', 'z'), [0, ($p['height'] - $thick('board')) / 2, $p['height'] - $thick('board')]),
                'brace' => [$spot(0, $p['height'] - $thick('board') - 40, -$thick('brace'), 'x', 'y')],
            ],
            'crate' => [
                'bottom' => [$spot(0, 0, 0, 'x', 'z')],
                'post' => [
                    $spot(0, $thick('bottom'), 0, 'y', 'x'),
                    $spot($p['length'] - 40, $thick('bottom'), 0, 'y', 'x'),
                    $spot(0, $thick('bottom'), $p['width'] - $thick('post'), 'y', 'x'),
                    $spot($p['length'] - 40, $thick('bottom'), $p['width'] - $thick('post'), 'y', 'x'),
                ],
                'longSlat' => [
                    $spot(0, $thick('bottom') + 10, -$thick('longSlat'), 'x', 'y'),
                    $spot(0, $thick('bottom') + 110, -$thick('longSlat'), 'x', 'y'),
                    $spot(0, $thick('bottom') + 10, $p['width'], 'x', 'y'),
                    $spot(0, $thick('bottom') + 110, $p['width'], 'x', 'y'),
                ],
                'shortSlat' => [
                    $spot(-$thick('shortSlat'), $thick('bottom') + 10, 0, 'z', 'y'),
                    $spot(-$thick('shortSlat'), $thick('bottom') + 110, 0, 'z', 'y'),
                    $spot($p['length'], $thick('bottom') + 10, 0, 'z', 'y'),
                    $spot($p['length'], $thick('bottom') + 110, 0, 'z', 'y'),
                ],
            ],
            'dryer' => [
                'absorber' => [$spot(0, 0, 0, 'x', 'z')],
                'frontPanel' => [$spot(0, 0, -$thick('frontPanel'), 'x', 'y'), $spot(0, 0, $p['depth'], 'x', 'y')],
                'sidePanel' => [$spot(-$thick('sidePanel'), 0, 0, 'z', 'y'), $spot($p['width'], 0, 0, 'z', 'y')],
                'trayRail' => [$spot(0, $p['height'] / 2, 0, 'z', 'y'), $spot($p['width'] - $thick('trayRail'), $p['height'] / 2, 0, 'z', 'y')],
            ],
            'coop' => [
                'roof' => [$spot(-50, $p['height'], -50, 'x', 'z')],
                'post' => [
                    $spot(0, 0, 0, 'y', 'x'),
                    $spot($p['width'] - 40, 0, 0, 'y', 'x'),
                    $spot(0, 0, $p['depth'] - $thick('post'), 'y', 'x'),
                    $spot($p['width'] - 40, 0, $p['depth'] - $thick('post'), 'y', 'x'),
                ],
                'wall' => array_merge(...array_map(
                    static fn (float $y): array => [$spot(0, $y, -$thick('wall'), 'x', 'y'), $spot(0, $y, $p['depth'], 'x', 'y')],
                    [0, 140, 280],
                )),
            ],
            default => [],
        };
    }
}
