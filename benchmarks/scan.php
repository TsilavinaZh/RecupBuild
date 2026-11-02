<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Benchmark of the scan on photos of known sizes: `php benchmarks/scan.php [scenes]`
 *
 * Calls the real models (MODEL_API_KEY in .env.php). Each scene of benchmarks/Scenes.php is
 * read by Muse Spark alone, then by Muse Spark with SAM 3.1, and both are scored against
 * the truth: which pieces were found (precision, recall, F1), how far each measurement is
 * (mean absolute error in mm), how right the A4 scale is, and how long it took.
 * Results in benchmarks/results/scan.json.
 */

use RecupBuild\Core\Config;
use RecupBuild\Errors\ScanError;
use RecupBuild\Errors\VisionError;
use RecupBuild\Services\Image;
use RecupBuild\Services\Scan;
use RecupBuild\Services\Segmentation;
use RecupBuild\Services\Vision;

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/Scenes.php';

set_time_limit(0);

const FIRST_SEED = 101;

/**
 * Pairs each piece found with the true piece nearest to it, inside half of that piece.
 *
 * @param list<array<string, mixed>> $found the pieces of a scan
 * @param list<array{kind: string, length_mm: int, width_mm: int, x: float, y: float}> $truth
 *
 * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}>
 */
function matches(array $found, array $truth): array
{
    $pairs = [];
    foreach ($found as $f => $piece) {
        $points = $piece['outline'];
        $x = array_sum(array_column($points, 0)) / 4;
        $y = array_sum(array_column($points, 1)) / 4;
        foreach ($truth as $t => $true) {
            $away = hypot($x - $true['x'], $y - $true['y']);
            if ($away <= 0.5 * $true['length_mm'] * Scenes::PIXELS_PER_MM) {
                $pairs[] = [$away, $f, $t];
            }
        }
    }
    sort($pairs);

    $usedFound = [];
    $usedTruth = [];
    $matched = [];
    foreach ($pairs as [, $f, $t]) {
        if (!isset($usedFound[$f]) && !isset($usedTruth[$t])) {
            $usedFound[$f] = true;
            $usedTruth[$t] = true;
            $matched[] = [$found[$f], $truth[$t]];
        }
    }

    return $matched;
}

/** @param array<string, mixed> $total */
function score(array &$total, array $scan, array $truth): void
{
    $matched = matches($scan['pieces'], $truth);
    $total['true_pieces'] += count($truth);
    $total['found_pieces'] += count($scan['pieces']);
    $total['matched_pieces'] += count($matched);
    $total['scale_errors'][] = abs($scan['a4']['mm_per_px'] * Scenes::PIXELS_PER_MM - 1);
    foreach ($matched as [$piece, $true]) {
        $total['length_errors'][] = abs($piece['length_mm'] - $true['length_mm']);
        $total['width_errors'][] = abs($piece['width_mm'] - $true['width_mm']);
        $total['relative_errors'][] = abs($piece['length_mm'] - $true['length_mm']) / $true['length_mm'];
        $total['relative_errors'][] = abs($piece['width_mm'] - $true['width_mm']) / $true['width_mm'];
    }
}

/** @param array<string, mixed> $total */
function summary(array $total): array
{
    $mean = static fn (array $values): float => $values === [] ? 0.0 : array_sum($values) / count($values);
    $precision = $total['found_pieces'] > 0 ? $total['matched_pieces'] / $total['found_pieces'] : 0.0;
    $recall = $total['true_pieces'] > 0 ? $total['matched_pieces'] / $total['true_pieces'] : 0.0;

    return [
        'precision' => round($precision, 3),
        'recall' => round($recall, 3),
        'f1' => round($precision + $recall > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0, 3),
        'length_mae_mm' => round($mean($total['length_errors']), 1),
        'width_mae_mm' => round($mean($total['width_errors']), 1),
        'size_mape_pct' => round(100 * $mean($total['relative_errors']), 1),
        'a4_scale_error_pct' => round(100 * $mean($total['scale_errors']), 2),
        'pieces_scored' => count($total['length_errors']),
    ];
}

$root = dirname(__DIR__);
$config = Config::load($root . '/.env.php');
$count = max(1, (int) ($argv[1] ?? 6));
$blank = ['true_pieces' => 0, 'found_pieces' => 0, 'matched_pieces' => 0, 'length_errors' => [], 'width_errors' => [], 'relative_errors' => [], 'scale_errors' => []];
$spark = $blank;
$sam = $blank;
$sparkSeconds = [];
$samSeconds = [];
$failed = [];

for ($seed = FIRST_SEED; $seed < FIRST_SEED + $count; ++$seed) {
    $scene = Scenes::make($seed, $root . '/demo/pile.jpg');
    [$jpeg, $width, $height] = Image::prepare($scene['jpeg'], $config);
    try {
        $start = microtime(true);
        $answer = Vision::analyse($jpeg, $config);
        $sparkSeconds[] = microtime(true) - $start;

        $start = microtime(true);
        $refined = Segmentation::refine($answer, $jpeg, $width, $height, $config);
        $samSeconds[] = microtime(true) - $start;

        score($spark, Scan::buildResponse($answer, $width, $height), $scene['pieces']);
        score($sam, Scan::buildResponse($refined, $width, $height), $scene['pieces']);
        printf("scene %d: %d pieces, Muse Spark found %d, with SAM %d\n", $seed, count($scene['pieces']), count($answer['pieces']), count($refined['pieces']));
    } catch (ScanError | VisionError $error) {
        $failed[] = $seed;
        printf("scene %d failed: %s\n", $seed, $error->errorCode);
    }
}

$mean = static fn (array $values): float => $values === [] ? 0.0 : array_sum($values) / count($values);
$report = [
    'scenes' => $count,
    'scenes_failed' => $failed,
    'muse_spark_alone' => summary($spark),
    'muse_spark_with_sam' => summary($sam),
    'seconds_muse_spark_mean' => round($mean($sparkSeconds), 1),
    'seconds_sam_mean' => round($mean($samSeconds), 1),
];
echo json_encode($report, JSON_PRETTY_PRINT), "\n";
file_put_contents(__DIR__ . '/results/scan.json', json_encode($report, JSON_PRETTY_PRINT) . "\n");
