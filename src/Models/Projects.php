<?php

declare(strict_types=1);

namespace RecupBuild\Models;

use RecupBuild\Services\Solver;

/**
 * Project definitions: sizing parameters (default, minimum, upper bound) and the
 * parts they produce, in millimetres.
 *
 * A definition is plain data, so the same code sizes, packs and describes the four
 * built-in projects (the port of frontend/src/domain/projects.ts) and the projects
 * the model invents from the user's stock (see Planner::projects). Each part side is
 * either driven by a parameter (`param` + `add` mm) or fixed (`param` null, `add` mm).
 *
 * The upper bound of the built-in projects is not in the frontend: it tells the
 * model how far it may enlarge a dimension (150 % of the default). Parameters are
 * always round numbers ending in 0, like the solver rounds them.
 *
 * Definition shape:
 *   ['id' => 'shelf', 'name' => ?string, 'description' => ?string,
 *    'params' => ['width' => ['default' => 600, 'min' => 300, 'max' => 900, 'label' => ?string]],
 *    'parts' => [['name' => 'side', 'label' => ?string, 'material' => 'wood',
 *                 'length' => ['param' => 'height', 'add' => 0], 'width' => ['param' => null, 'add' => 40],
 *                 'thickness' => ['min' => 15, 'max' => 40], 'quantity' => 2]],
 *    'steps' => 4]
 */
final class Projects
{
    /** @var list<string> */
    public const IDS = ['dryer', 'coop', 'shelf', 'crate'];

    /** Names of the built-in parts, keys under `parts` in the locale files. */
    public const PART_NAMES = [
        'side', 'board', 'brace', 'post', 'longSlat', 'shortSlat', 'bottom',
        'absorber', 'frontPanel', 'sidePanel', 'trayRail', 'roof', 'wall',
    ];

    public const WOOD_BOARD = ['min' => 15, 'max' => 40];
    public const WOOD_SLAT = ['min' => 8, 'max' => 25];
    public const WOOD_POST = ['min' => 30, 'max' => 60];
    public const METAL = ['min' => 0.3, 'max' => 3];

    /** Fabrication steps the model writes for any project. */
    public const STEPS = 4;

    /** What a definition may hold, whoever wrote it. */
    private const MAX_PARAMS = 4;
    private const MAX_PARTS = 10;
    private const MAX_CUTS = 40;
    private const MAX_MM = 4000;

    /**
     * The built-in projects: parameter bounds and their parts.
     *
     * @var array<string, array{params: array<string, array{default: int, min: int}>, parts: list<array<string, mixed>>}>
     */
    public const CATALOGUE = [
        'shelf' => [
            'params' => ['width' => ['default' => 600, 'min' => 300], 'depth' => ['default' => 140, 'min' => 90], 'height' => ['default' => 800, 'min' => 400]],
            'parts' => [
                ['name' => 'side', 'material' => 'wood', 'length' => ['param' => 'height', 'add' => 0], 'width' => ['param' => 'depth', 'add' => 0], 'thickness' => self::WOOD_BOARD, 'quantity' => 2],
                ['name' => 'board', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => 'depth', 'add' => 0], 'thickness' => self::WOOD_BOARD, 'quantity' => 3],
                ['name' => 'brace', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => null, 'add' => 40], 'thickness' => ['min' => 15, 'max' => 45], 'quantity' => 1],
            ],
        ],
        'crate' => [
            'params' => ['length' => ['default' => 500, 'min' => 300], 'width' => ['default' => 320, 'min' => 200], 'height' => ['default' => 240, 'min' => 150]],
            'parts' => [
                ['name' => 'post', 'material' => 'wood', 'length' => ['param' => 'height', 'add' => 0], 'width' => ['param' => null, 'add' => 40], 'thickness' => self::WOOD_POST, 'quantity' => 4],
                ['name' => 'longSlat', 'material' => 'wood', 'length' => ['param' => 'length', 'add' => 0], 'width' => ['param' => null, 'add' => 90], 'thickness' => self::WOOD_SLAT, 'quantity' => 4],
                ['name' => 'shortSlat', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => null, 'add' => 90], 'thickness' => self::WOOD_SLAT, 'quantity' => 4],
                ['name' => 'bottom', 'material' => 'wood', 'length' => ['param' => 'length', 'add' => 0], 'width' => ['param' => 'width', 'add' => 0], 'thickness' => ['min' => 6, 'max' => 25], 'quantity' => 1],
            ],
        ],
        'dryer' => [
            'params' => ['width' => ['default' => 600, 'min' => 400], 'depth' => ['default' => 400, 'min' => 300], 'height' => ['default' => 180, 'min' => 120]],
            'parts' => [
                ['name' => 'absorber', 'material' => 'metal', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => 'depth', 'add' => 0], 'thickness' => self::METAL, 'quantity' => 1],
                ['name' => 'frontPanel', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => 'height', 'add' => 0], 'thickness' => self::WOOD_BOARD, 'quantity' => 2],
                ['name' => 'sidePanel', 'material' => 'wood', 'length' => ['param' => 'depth', 'add' => 0], 'width' => ['param' => 'height', 'add' => 0], 'thickness' => self::WOOD_BOARD, 'quantity' => 2],
                ['name' => 'trayRail', 'material' => 'wood', 'length' => ['param' => 'depth', 'add' => 0], 'width' => ['param' => null, 'add' => 40], 'thickness' => ['min' => 15, 'max' => 45], 'quantity' => 2],
            ],
        ],
        'coop' => [
            'params' => ['width' => ['default' => 900, 'min' => 700], 'depth' => ['default' => 700, 'min' => 600], 'height' => ['default' => 700, 'min' => 500]],
            'parts' => [
                ['name' => 'roof', 'material' => 'metal', 'length' => ['param' => 'width', 'add' => 100], 'width' => ['param' => 'depth', 'add' => 100], 'thickness' => self::METAL, 'quantity' => 1],
                ['name' => 'post', 'material' => 'wood', 'length' => ['param' => 'height', 'add' => 0], 'width' => ['param' => null, 'add' => 40], 'thickness' => self::WOOD_POST, 'quantity' => 4],
                ['name' => 'wall', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => null, 'add' => 140], 'thickness' => self::WOOD_BOARD, 'quantity' => 6],
            ],
        ],
    ];

    /**
     * The definition of a project: a built-in id, or a definition already checked
     * by `sanitize` (returned as is).
     *
     * @param string|array<string, mixed> $project
     *
     * @return array<string, mixed>
     */
    public static function definition(string|array $project): array
    {
        if (is_array($project)) {
            return $project;
        }

        $entry = self::CATALOGUE[$project];
        $params = [];
        foreach ($entry['params'] as $name => $spec) {
            $upper = (int) (10 * ceil(($spec['default'] * 1.5) / 10));
            $params[$name] = ['default' => $spec['default'], 'min' => $spec['min'], 'max' => max($spec['min'], $upper), 'label' => null];
        }
        $parts = [];
        foreach ($entry['parts'] as $part) {
            $parts[] = $part + ['label' => null];
        }

        return ['id' => $project, 'name' => null, 'description' => null, 'params' => $params, 'parts' => $parts, 'steps' => self::STEPS];
    }

    /** True for the four projects that have their own names, art and texts in the app. */
    public static function isBuiltIn(string|array $project): bool
    {
        return in_array(self::definition($project)['id'], self::IDS, true);
    }

    /** Parameters at their default values. */
    public static function defaults(string|array $project): array
    {
        $defaults = [];
        foreach (self::definition($project)['params'] as $name => $spec) {
            $defaults[$name] = $spec['default'];
        }

        return $defaults;
    }

    /**
     * Bounds of each parameter: [min, max], as round tens.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function bounds(string|array $project): array
    {
        $bounds = [];
        foreach (self::definition($project)['params'] as $name => $spec) {
            $bounds[$name] = [$spec['min'], $spec['max']];
        }

        return $bounds;
    }

    /**
     * Keeps as many of the given parameters as possible: unknown keys dropped, the
     * rest rounded to tens and clamped. Missing keys take their default value.
     */
    public static function clamp(string|array $project, array $params): array
    {
        $result = self::defaults($project);
        foreach (self::bounds($project) as $name => [$min, $max]) {
            if (!isset($params[$name]) || !is_numeric($params[$name])) {
                continue;
            }
            $value = (int) (10 * round(((float) $params[$name]) / 10));
            $result[$name] = min($max, max($min, $value));
        }

        return $result;
    }

    /**
     * The parts a project needs for given parameters (already clamped).
     *
     * @return list<array{name: string, material: string, lengthMm: float, widthMm: float, thickness: array{min: float, max: float}, quantity: int}>
     */
    public static function parts(string|array $project, array $p): array
    {
        $parts = [];
        foreach (self::definition($project)['parts'] as $part) {
            $parts[] = [
                'name' => $part['name'],
                'material' => $part['material'],
                'lengthMm' => self::side($part['length'], $p),
                'widthMm' => self::side($part['width'], $p),
                'thickness' => $part['thickness'],
                'quantity' => $part['quantity'],
            ];
        }

        return $parts;
    }

    /** The parameter that sets a part's length or width (what to shrink when it does not fit). */
    public static function driver(string|array $project, string $partName, string $side): ?string
    {
        foreach (self::definition($project)['parts'] as $part) {
            if ($part['name'] === $partName) {
                return $part[$side]['param'];
            }
        }

        return null;
    }

    /** Bounds and parts at the given parameters, as the model prompt needs them. */
    public static function describe(string|array $project, array $params): array
    {
        $definition = self::definition($project);
        $bounds = [];
        foreach ($definition['params'] as $name => $spec) {
            $bounds[$name] = ['min' => $spec['min'], 'default' => $spec['default'], 'max' => $spec['max']];
        }

        $parts = [];
        foreach (self::parts($definition, $params) as $index => $part) {
            $parts[] = [
                'name' => $definition['parts'][$index]['label'] ?? $part['name'],
                'material' => $part['material'],
                'quantity' => $part['quantity'],
                'length_mm' => round($part['lengthMm']),
                'width_mm' => round($part['widthMm']),
                'thickness_mm' => ['min' => $part['thickness']['min'], 'max' => $part['thickness']['max']],
                'length_from' => $definition['parts'][$index]['length']['param'],
                'width_from' => $definition['parts'][$index]['width']['param'],
            ];
        }

        return [
            'name' => $definition['name'] ?? $definition['id'],
            'description' => $definition['description'],
            'params' => $bounds,
            'parts' => $parts,
            'assembly_steps' => $definition['steps'],
        ];
    }

    // ------------------------------------------------------------ untrusted input

    /**
     * Checks a definition written by the model, or sent back by the browser, and
     * returns it in the canonical shape. Null when it cannot be planned safely:
     * every number is bounded, every text is short, every part side resolves to a
     * positive size at the smallest and the largest parameters.
     *
     * A built-in id always gives the built-in definition, whatever came with it.
     *
     * @return ?array<string, mixed>
     */
    public static function sanitize(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) !== 1) {
            return null;
        }
        if (in_array($id, self::IDS, true)) {
            return self::definition($id);
        }

        $name = self::text($raw['name'] ?? null, 80);
        if ($name === null || !is_array($raw['params'] ?? null) || !is_array($raw['parts'] ?? null)) {
            return null;
        }

        $params = [];
        foreach ($raw['params'] as $key => $spec) {
            if (!is_string($key) || preg_match('/^[a-z][a-zA-Z0-9_]{0,23}$/', $key) !== 1 || !is_array($spec)) {
                return null;
            }
            $values = [];
            foreach (['min', 'default', 'max'] as $field) {
                if (!is_numeric($spec[$field] ?? null)) {
                    return null;
                }
                $values[$field] = (int) min(self::MAX_MM, max(10, 10 * round(((float) $spec[$field]) / 10)));
            }
            $params[$key] = [
                'default' => $values['default'],
                'min' => min($values['min'], $values['default']),
                'max' => max($values['max'], $values['default']),
                'label' => self::text($spec['label'] ?? null, 40) ?? $key,
            ];
        }
        if ($params === [] || count($params) > self::MAX_PARAMS) {
            return null;
        }

        $parts = [];
        $names = [];
        $cuts = 0;
        foreach ($raw['parts'] as $part) {
            if (!is_array($part)) {
                return null;
            }
            $partName = $part['name'] ?? null;
            $material = $part['material'] ?? null;
            $quantity = $part['quantity'] ?? null;
            if (!is_string($partName) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,31}$/', $partName) !== 1 || isset($names[$partName])) {
                return null;
            }
            if (!in_array($material, ['wood', 'metal'], true) || !is_numeric($quantity)) {
                return null;
            }
            $length = self::dimension($part['length'] ?? null, $params);
            $width = self::dimension($part['width'] ?? null, $params);
            $thickness = $part['thickness'] ?? null;
            if ($length === null || $width === null || !is_array($thickness) || !is_numeric($thickness['min'] ?? null) || !is_numeric($thickness['max'] ?? null)) {
                return null;
            }
            $low = round(min(100.0, max(0.3, (float) $thickness['min'])), 1);
            $high = round(min(200.0, max($low, (float) $thickness['max'])), 1);
            $count = (int) min(12, max(1, (int) $quantity));
            $names[$partName] = true;
            $cuts += $count;
            $parts[] = [
                'name' => $partName,
                'label' => self::text($part['label'] ?? null, 60) ?? $partName,
                'material' => $material,
                'length' => $length,
                'width' => $width,
                'thickness' => ['min' => $low, 'max' => $high],
                'quantity' => $count,
            ];
        }
        if ($parts === [] || count($parts) > self::MAX_PARTS || $cuts > self::MAX_CUTS) {
            return null;
        }

        $definition = [
            'id' => $id,
            'name' => $name,
            'description' => self::text($raw['description'] ?? null, 240),
            'params' => $params,
            'parts' => $parts,
            'steps' => self::STEPS,
        ];

        // Every side must stay a real size, from the smallest parameters to the largest.
        $smallest = [];
        $largest = [];
        foreach ($params as $key => $spec) {
            $smallest[$key] = $spec['min'];
            $largest[$key] = $spec['max'];
        }
        foreach ([...self::parts($definition, $smallest), ...self::parts($definition, $largest)] as $part) {
            if (min($part['lengthMm'], $part['widthMm']) < 5 || max($part['lengthMm'], $part['widthMm']) > self::MAX_MM) {
                return null;
            }
        }

        return $definition;
    }

    /**
     * The same project, easier to make from this stock: every part accepts any
     * thickness the stock has in its material, and every parameter may go down to
     * half its minimum. Null-safe: a definition that would turn invalid is kept as is.
     *
     * @param list<array{id: int, material: string, length: float, width: float, thickness: float}> $stock
     */
    public static function relax(array $definition, array $stock): array
    {
        $relaxed = $definition;
        foreach ($relaxed['parts'] as $index => $part) {
            $thicknesses = [];
            foreach ($stock as $item) {
                if ($item['material'] === $part['material']) {
                    $thicknesses[] = $item['thickness'];
                }
            }
            if ($thicknesses !== []) {
                $relaxed['parts'][$index]['thickness'] = [
                    'min' => min($part['thickness']['min'], min($thicknesses)),
                    'max' => max($part['thickness']['max'], max($thicknesses)),
                ];
            }
        }
        foreach ($relaxed['params'] as $name => $spec) {
            $relaxed['params'][$name]['min'] = (int) max(10, 10 * round($spec['min'] / 20));
        }

        return self::sanitize($relaxed) ?? $definition;
    }

    /**
     * A project the engine makes on its own, for when nothing else fits: a wall
     * ledge cut from the longest wood piece, which always fits that piece.
     *
     * @param list<array{id: int, material: string, length: float, width: float, thickness: float}> $stock
     */
    public static function simplest(array $stock, string $locale): ?array
    {
        $longest = null;
        foreach ($stock as $item) {
            if ($item['material'] === 'wood' && ($longest === null || $item['length'] > $longest['length'])) {
                $longest = $item;
            }
        }
        if ($longest === null || $longest['length'] < 60 || $longest['width'] < 20) {
            return null;
        }

        $words = [
            'fr' => ['Tablette murale', 'Une petite tablette à fixer au mur, pour poser plantes, épices ou clés.', 'Longueur', 'Tablette', 'Support'],
            'en' => ['Wall ledge', 'A small ledge to fix on the wall, for plants, spices or keys.', 'Length', 'Ledge', 'Bracket'],
            'mg' => ['Talantalana amin’ny rindrina', 'Talantalana kely apetaka amin’ny rindrina, ho an’ny zavamaniry, zava-manitra na lakile.', 'Halavany', 'Takelaka', 'Fitazonana'],
        ][$locale] ?? null;
        $words ??= ['Tablette murale', 'Une petite tablette à fixer au mur, pour poser plantes, épices ou clés.', 'Longueur', 'Tablette', 'Support'];

        // The ledge and its two brackets share the piece along its length.
        // Too short a piece for brackets too: the ledge alone.
        $bracket = $longest['length'] < 300 ? 0 : (int) min(150, 10 * floor($longest['length'] / 50));
        $length = (int) (10 * floor(($longest['length'] - ($bracket > 0 ? 2 * ($bracket + Solver::KERF_MM) : 0)) / 10));
        $width = (int) min(200, floor($longest['width']));
        $thickness = ['min' => $longest['thickness'], 'max' => $longest['thickness']];

        return self::sanitize([
            'id' => 'simple-ledge',
            'name' => $words[0],
            'description' => $words[1],
            'params' => ['length' => ['label' => $words[2], 'min' => min(50, $length), 'default' => min(800, $length), 'max' => min(800, $length)]],
            'parts' => [
                ['name' => 'ledge', 'label' => $words[3], 'material' => 'wood', 'length' => ['param' => 'length', 'add' => 0], 'width' => ['param' => null, 'add' => $width], 'thickness' => $thickness, 'quantity' => 1],
                ...($bracket > 0 ? [['name' => 'bracket', 'label' => $words[4], 'material' => 'wood', 'length' => ['param' => null, 'add' => $bracket], 'width' => ['param' => null, 'add' => $width], 'thickness' => $thickness, 'quantity' => 2]] : []),
            ],
        ]);
    }

    /** @param array{param: ?string, add: int|float} $side */
    private static function side(array $side, array $p): float
    {
        return (float) (($side['param'] === null ? 0 : $p[$side['param']]) + $side['add']);
    }

    /** @return ?array{param: ?string, add: int} */
    private static function dimension(mixed $raw, array $params): ?array
    {
        if (!is_array($raw) || !is_numeric($raw['add'] ?? null)) {
            return null;
        }
        $param = $raw['param'] ?? null;
        if ($param === '' || $param === null) {
            $param = null;
        } elseif (!is_string($param) || !isset($params[$param])) {
            return null;
        }
        $add = (int) round((float) $raw['add']);
        if (abs($add) > self::MAX_MM) {
            return null;
        }

        return ['param' => $param, 'add' => $add];
    }

    /** A short single-line text, or null when there is none. */
    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        if ($text === '') {
            return null;
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }
}
