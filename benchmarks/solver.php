<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Benchmark of the cutting engine, without any model: `php benchmarks/solver.php`
 *
 * Plans every project on the demo inventory and on seeded random inventories, then checks
 * each plan from scratch: every part inside its piece, no two parts overlapping, the saw
 * kerf between them. It reports how often a plan exists, how much of the wood it uses and
 * how long it takes, in benchmarks/results/solver.json. A broken plan fails the run.
 */

use RecupBuild\Models\Projects;
use RecupBuild\Services\Demo;
use RecupBuild\Services\Solver;

require dirname(__DIR__) . '/src/bootstrap.php';

const RANDOM_INVENTORIES = 40;
const SEED = 2026;
const TIMING_RUNS = 5;

/**
 * A heap as a scan could read it: planks, battens, plywood and sheet metal.
 *
 * @return list<array{id: int, kind: string, length_mm: int, width_mm: int, thickness_mm: float}>
 */
function randomInventory(): array
{
    $kinds = [
        'plank' => [[800, 2400], [90, 220], [18, 27]],
        'batten' => [[600, 2400], [30, 60], [25, 45]],
        'plywood' => [[500, 1500], [300, 900], [9, 18]],
        'sheet_metal' => [[500, 1500], [300, 900], [1, 1]],
    ];
    $pieces = [];
    foreach (range(1, mt_rand(6, 16)) as $id) {
        $kind = array_keys($kinds)[min(mt_rand(0, 5), 3)];
        [$length, $width, $thickness] = $kinds[$kind];
        $pieces[] = [
            'id' => $id,
            'kind' => $kind,
            'length_mm' => 5 * (int) (mt_rand(...$length) / 5),
            'width_mm' => 5 * (int) (mt_rand(...$width) / 5),
            'thickness_mm' => $kind === 'sheet_metal' ? 0.6 : (float) mt_rand(...$thickness),
        ];
    }

    return $pieces;
}

/**
 * Everything wrong with a plan, read from the placements alone.
 *
 * @param array<string, mixed> $plan
 * @param list<array<string, mixed>> $pieces
 *
 * @return list<string>
 */
function faults(array $plan, array $pieces): array
{
    $byId = array_column($pieces, null, 'id');
    $kerf = (float) $plan['kerf_mm'];
    $faults = [];
    foreach ($plan['placements'] as $index => $part) {
        $piece = $byId[$part['piece_id']] ?? null;
        if ($piece === null) {
            $faults[] = "part {$part['number']} on an unknown piece";
            continue;
        }
        // The engine lays every piece with its long side as its length.
        $long = max($piece['length_mm'], $piece['width_mm']);
        $short = min($piece['length_mm'], $piece['width_mm']);
        if ($part['x'] < 0 || $part['y'] < 0 || $part['x'] + $part['length_mm'] > $long + 1e-6 || $part['y'] + $part['width_mm'] > $short + 1e-6) {
            $faults[] = "part {$part['number']} leaves piece {$piece['id']}";
        }
        foreach (array_slice($plan['placements'], $index + 1) as $other) {
            $apart = $part['x'] + $part['length_mm'] + $kerf <= $other['x'] + 1e-6
                || $other['x'] + $other['length_mm'] + $kerf <= $part['x'] + 1e-6
                || $part['y'] + $part['width_mm'] + $kerf <= $other['y'] + 1e-6
                || $other['y'] + $other['width_mm'] + $kerf <= $part['y'] + 1e-6;
            if ($part['piece_id'] === $other['piece_id'] && !$apart) {
                $faults[] = "parts {$part['number']} and {$other['number']} touch on piece {$piece['id']}";
            }
        }
    }

    return $faults;
}

/** The share of the pieces used that ends up in parts. */
function utilization(array $plan, array $pieces): float
{
    $byId = array_column($pieces, null, 'id');
    $parts = 0.0;
    foreach ($plan['placements'] as $part) {
        $parts += $part['length_mm'] * $part['width_mm'];
    }
    $stock = 0.0;
    foreach (array_unique(array_column($plan['placements'], 'piece_id')) as $id) {
        $stock += $byId[$id]['length_mm'] * $byId[$id]['width_mm'];
    }

    return $stock > 0 ? $parts / $stock : 0.0;
}

function median(array $values): float
{
    sort($values);

    return $values === [] ? 0.0 : (float) $values[(int) (count($values) / 2)];
}

$demo = array_map(static fn (array $piece): array => array_diff_key($piece, ['outline' => 1]), Demo::scan()['pieces']);
mt_srand(SEED);
$inventories = ['demo photo' => $demo];
foreach (range(1, RANDOM_INVENTORIES) as $number) {
    $inventories["random {$number}"] = randomInventory();
}

$projects = [...Projects::IDS, ...array_column(Demo::projects('en'), 'definition')];
$runs = 0;
$feasible = 0;
$placed = 0;
$times = [];
$usage = [];
$faulty = [];
$byProject = [];
foreach ($inventories as $name => $pieces) {
    foreach ($projects as $project) {
        $label = is_string($project) ? $project : $project['name'];
        $spent = [];
        for ($run = 0; $run < TIMING_RUNS; ++$run) {
            $start = hrtime(true);
            $result = Solver::solve($project, $pieces);
            $spent[] = (hrtime(true) - $start) / 1e6;
        }
        $times[] = median($spent);
        ++$runs;
        $byProject[$label] ??= ['runs' => 0, 'feasible' => 0];
        ++$byProject[$label]['runs'];
        if ($result['status'] !== 'feasible') {
            continue;
        }
        ++$feasible;
        ++$byProject[$label]['feasible'];
        $placed += count($result['plan']['placements']);
        $usage[] = utilization($result['plan'], $pieces);
        foreach (faults($result['plan'], $pieces) as $fault) {
            $faulty[] = "{$name} / {$label}: {$fault}";
        }
    }
}

$report = [
    'inventories' => count($inventories),
    'projects' => count($projects),
    'plans_attempted' => $runs,
    'plans_feasible' => $feasible,
    'parts_placed_and_checked' => $placed,
    'plans_with_faults' => count($faulty),
    'wood_use_median_pct' => round(100 * median($usage), 1),
    'solve_ms_median' => round(median($times), 2),
    'solve_ms_max' => round(max($times), 2),
    'by_project' => $byProject,
];

printf("%d plans attempted on %d inventories, %d feasible\n", $runs, count($inventories), $feasible);
printf("%d parts checked: %d faults\n", $placed, count($faulty));
printf("median wood use %.1f%%, median solve %.2f ms, slowest %.2f ms\n", $report['wood_use_median_pct'], $report['solve_ms_median'], $report['solve_ms_max']);
file_put_contents(__DIR__ . '/results/solver.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

foreach (array_slice($faulty, 0, 10) as $fault) {
    echo 'FAULT ', $fault, "\n";
}
exit($faulty === [] ? 0 : 1);
