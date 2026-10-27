<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Models\Projects;

/**
 * The cutting engine (the port of frontend/src/domain/solver.ts).
 *
 * Parts are placed on stock pieces with a shelf (strip) strategy: biggest parts
 * first, each one where it leaves the least waste. When parts do not fit, the
 * size parameters are shrunk, never below their minimum, and every attempt is
 * recorded: that log is what the Calculation screen shows.
 *
 * The engine never invents anything: with `Planner` in front of it, it only
 * verifies and draws what the model proposed.
 */
final class Solver
{
    /** Saw blade width lost between two cuts, in mm. */
    public const KERF_MM = 3;
    /** Each adjustment shrinks a parameter to this share of its value. */
    private const SHRINK = 0.88;
    private const MAX_ITERATIONS = 6;

    /**
     * Inventory pieces as stock to cut from. Pieces with a missing dimension are left out.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return list<array{id: int, material: string, length: float, width: float, thickness: float}>
     */
    public static function toStock(array $pieces): array
    {
        $stock = [];
        foreach ($pieces as $piece) {
            $length = $piece['length_mm'] ?? null;
            $width = $piece['width_mm'] ?? null;
            $thickness = $piece['thickness_mm'] ?? null;
            if (!is_numeric($length) || !is_numeric($width) || !is_numeric($thickness)) {
                continue;
            }
            $stock[] = [
                'id' => (int) $piece['id'],
                'material' => ($piece['kind'] ?? null) === 'sheet_metal' ? 'metal' : 'wood',
                'length' => max((float) $length, (float) $width),
                'width' => min((float) $length, (float) $width),
                'thickness' => (float) $thickness,
            ];
        }

        return $stock;
    }

    /** @param array{name: string, material: string, thickness: array{min: float, max: float}} $spec */
    private static function fitsMaterial(array $spec, array $stock): bool
    {
        return $spec['material'] === $stock['material']
            && $stock['thickness'] >= $spec['thickness']['min']
            && $stock['thickness'] <= $spec['thickness']['max'];
    }

    /**
     * @param list<array<string, mixed>> $parts as returned by Projects::parts
     * @param list<array{id: int, material: string, length: float, width: float, thickness: float}> $stock
     *
     * @return array{placements: list<array<string, mixed>>, unsatisfied: list<array{part: string, missing: int}>}
     */
    public static function pack(array $parts, array $stock): array
    {
        $boards = [];
        foreach ($stock as $item) {
            $boards[] = ['stock' => $item, 'lanes' => []];
        }

        $instances = [];
        foreach ($parts as $spec) {
            for ($quantity = 0; $quantity < $spec['quantity']; $quantity++) {
                $instances[] = [
                    'spec' => $spec,
                    'length' => max($spec['lengthMm'], $spec['widthMm']),
                    'width' => min($spec['lengthMm'], $spec['widthMm']),
                ];
            }
        }
        usort($instances, static fn (array $a, array $b): int => [$b['width'], $b['length']] <=> [$a['width'], $a['length']]);

        $placed = [];
        $missing = [];
        foreach ($instances as $part) {
            $best = null;
            foreach ($boards as $boardIndex => $board) {
                $s = $board['stock'];
                if (!self::fitsMaterial($part['spec'], $s) || $part['width'] > $s['width'] || $part['length'] > $s['length']) {
                    continue;
                }

                foreach ($board['lanes'] as $laneIndex => $lane) {
                    $x = $lane['used'] === 0 ? 0 : $lane['used'] + self::KERF_MM;
                    if ($part['width'] > $lane['height'] || $x + $part['length'] > $s['length']) {
                        continue;
                    }
                    $waste = $s['length'] - ($x + $part['length']) + ($lane['height'] - $part['width']);
                    if ($best === null || $waste < $best['waste']) {
                        $best = ['board' => $boardIndex, 'lane' => $laneIndex, 'y' => $lane['y'], 'x' => $x, 'waste' => $waste];
                    }
                }

                $last = $board['lanes'] === [] ? null : $board['lanes'][array_key_last($board['lanes'])];
                $y = $last === null ? 0 : $last['y'] + $last['height'] + self::KERF_MM;
                if ($y + $part['width'] <= $s['width']) {
                    // A new lane costs its whole strip: prefer existing lanes when they fit.
                    $waste = $s['length'] - $part['length'] + 10000;
                    if ($best === null || $waste < $best['waste']) {
                        $best = ['board' => $boardIndex, 'lane' => null, 'y' => $y, 'x' => 0, 'waste' => $waste];
                    }
                }
            }

            if ($best === null) {
                $name = $part['spec']['name'];
                $missing[$name] = ($missing[$name] ?? 0) + 1;
                continue;
            }

            if ($best['lane'] === null) {
                $boards[$best['board']]['lanes'][] = ['y' => $best['y'], 'height' => $part['width'], 'used' => 0];
                $best['lane'] = array_key_last($boards[$best['board']]['lanes']);
            }
            $boards[$best['board']]['lanes'][$best['lane']]['used'] = $best['x'] + $part['length'];

            $placed[] = [
                'order' => $best['board'],
                'part' => $part['spec']['name'],
                'piece_id' => $boards[$best['board']]['stock']['id'],
                'x' => $best['x'],
                'y' => $best['y'],
                'length_mm' => $part['length'],
                'width_mm' => $part['width'],
                'thickness_mm' => $boards[$best['board']]['stock']['thickness'],
            ];
        }

        // Number parts piece by piece, then top to bottom and left to right: easy to follow on the plan.
        usort($placed, static fn (array $a, array $b): int => [$a['order'], $a['y'], $a['x']] <=> [$b['order'], $b['y'], $b['x']]);
        $placements = [];
        foreach ($placed as $index => $item) {
            unset($item['order']);
            $placements[] = ['number' => $index + 1] + $item;
        }

        $unsatisfied = [];
        foreach ($missing as $name => $count) {
            $unsatisfied[] = ['part' => $name, 'missing' => $count];
        }

        return ['placements' => $placements, 'unsatisfied' => $unsatisfied];
    }

    /** Why no plan was found: the reason the UI can translate. */
    public static function reasonFor(array $failing, array $stock): string
    {
        foreach ($failing as $spec) {
            if ($spec['material'] !== 'metal') {
                continue;
            }
            $metal = array_values(array_filter($stock, static fn (array $item): bool => $item['material'] === 'metal'));
            return $metal === [] ? 'no_metal' : 'metal_too_small';
        }
        foreach ($failing as $spec) {
            $compatible = array_filter($stock, static fn (array $item): bool => self::fitsMaterial($spec, $item));
            if ($compatible === []) {
                return 'thickness';
            }
        }

        return 'not_enough_wood';
    }

    /**
     * Looks for a cutting plan for a project (a built-in id or a checked definition),
     * starting at the given parameters (the model's proposal) or at their defaults.
     *
     * @param string|array<string, mixed> $project
     * @param list<array<string, mixed>> $pieces
     *
     * @return array{status: string, reason: ?string, iterations: list<array<string, mixed>>, plan: ?array<string, mixed>}
     */
    public static function solve(string|array $project, array $pieces, ?array $initialParams = null): array
    {
        $definition = Projects::definition($project);
        $projectId = $definition['id'];
        $stock = self::toStock($pieces);
        $params = $initialParams === null ? Projects::defaults($definition) : Projects::clamp($definition, $initialParams);
        $iterations = [];

        for ($index = 1; $index <= self::MAX_ITERATIONS; $index += 1) {
            $parts = Projects::parts($definition, $params);
            $total = 0;
            foreach ($parts as $spec) {
                $total += $spec['quantity'];
            }
            ['placements' => $placements, 'unsatisfied' => $unsatisfied] = self::pack($parts, $stock);

            if ($unsatisfied === []) {
                $iterations[] = self::iteration($index, 'feasible', $params, $total, $total, [], []);
                $pieceIds = [];
                foreach ($placements as $placement) {
                    if (!in_array($placement['piece_id'], $pieceIds, true)) {
                        $pieceIds[] = $placement['piece_id'];
                    }
                }

                return [
                    'status' => 'feasible',
                    'reason' => null,
                    'iterations' => $iterations,
                    'plan' => [
                        'project' => $projectId,
                        'params' => $params,
                        'placements' => $placements,
                        'piece_ids' => $pieceIds,
                        'kerf_mm' => self::KERF_MM,
                    ],
                ];
            }

            $failing = self::failingSpecs($parts, $unsatisfied);

            // Decide what to shrink, part by part.
            $next = $params;
            $adjustments = [];
            foreach ($failing as $spec) {
                $compatible = array_values(array_filter($stock, static fn (array $item): bool => self::fitsMaterial($spec, $item)));
                if ($compatible === []) {
                    continue;
                }
                $sides = [min($spec['lengthMm'], $spec['widthMm'])];
                $wideEnough = false;
                foreach ($compatible as $item) {
                    if ($item['width'] >= $sides[0]) {
                        $wideEnough = true;
                    }
                }
                $param = self::sideDriver($definition, $spec, $wideEnough);
                if ($param === null || self::hasAdjustment($adjustments, $param)) {
                    continue;
                }
                $limit = $definition['params'][$param]['min'];
                $shrunk = (int) max($limit, (10 * round(($next[$param] * self::SHRINK) / 10)));
                if ($shrunk < $next[$param]) {
                    $adjustments[] = ['param' => $param, 'from' => $next[$param], 'to' => $shrunk];
                    $next[$param] = $shrunk;
                }
            }

            // The longest parts use up the long pieces: shrinking them too frees room for the others.
            $someFits = false;
            foreach ($failing as $spec) {
                foreach ($stock as $item) {
                    if (self::fitsMaterial($spec, $item)) {
                        $someFits = true;
                    }
                }
            }
            if ($adjustments !== [] || $someFits) {
                $longest = $parts[0];
                foreach ($parts as $spec) {
                    if ($spec['lengthMm'] > $longest['lengthMm']) {
                        $longest = $spec;
                    }
                }
                $param = self::sideDriver($definition, $longest, true);
                if ($param !== null && !self::hasAdjustment($adjustments, $param)) {
                    $limit = $definition['params'][$param]['min'];
                    $shrunk = (int) max($limit, (10 * round(($next[$param] * self::SHRINK) / 10)));
                    if ($shrunk < $next[$param]) {
                        $adjustments[] = ['param' => $param, 'from' => $next[$param], 'to' => $shrunk];
                        $next[$param] = $shrunk;
                    }
                }
            }

            $placed = $total;
            foreach ($unsatisfied as $item) {
                $placed -= $item['missing'];
            }
            $iterations[] = self::iteration($index, 'infeasible', $params, $placed, $total, $unsatisfied, $adjustments);

            if ($adjustments === []) {
                return ['status' => 'infeasible', 'reason' => self::reasonFor($failing, $stock), 'iterations' => $iterations, 'plan' => null];
            }
            $params = $next;
        }

        $last = $iterations[array_key_last($iterations)];
        $failing = self::failingSpecs(Projects::parts($definition, $last['params']), $last['unsatisfied']);

        return ['status' => 'infeasible', 'reason' => self::reasonFor($failing, $stock), 'iterations' => $iterations, 'plan' => null];
    }

    /**
     * The parameter to shrink for a part: the one setting its long side, or its short
     * side when no piece is wide enough (parts are laid with their long side along the piece).
     */
    private static function sideDriver(array $definition, array $spec, bool $longSide): ?string
    {
        $lengthIsLong = $spec['lengthMm'] >= $spec['widthMm'];
        $side = $longSide === $lengthIsLong ? 'length' : 'width';

        return Projects::driver($definition, $spec['name'], $side);
    }

    /** @param list<array{param: string, from: int, to: int}> $adjustments */
    private static function hasAdjustment(array $adjustments, string $param): bool
    {
        foreach ($adjustments as $adjustment) {
            if ($adjustment['param'] === $param) {
                return true;
            }
        }

        return false;
    }

    /**
     * The PartSpecs behind an unsatisfied list (the parts that found no stock).
     *
     * @param list<array<string, mixed>> $parts
     * @param list<array{part: string, missing: int}> $unsatisfied
     *
     * @return list<array<string, mixed>>
     */
    public static function failingSpecs(array $parts, array $unsatisfied): array
    {
        $failing = [];
        foreach ($unsatisfied as $item) {
            foreach ($parts as $spec) {
                if ($spec['name'] === $item['part']) {
                    $failing[] = $spec;
                }
            }
        }

        return $failing;
    }

    public static function iteration(int $index, string $status, array $params, int $placed, int $total, array $unsatisfied, array $adjustments): array
    {
        return [
            'index' => $index,
            'status' => $status,
            'params' => $params,
            'placed' => $placed,
            'total' => $total,
            'unsatisfied' => $unsatisfied,
            'adjustments' => $adjustments,
        ];
    }
}