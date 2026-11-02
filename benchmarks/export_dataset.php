<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Exports the scenes of Scenes.php as a dataset, ready for the Hugging Face Hub:
 *
 *   php benchmarks/export_dataset.php [folder] [scenes]
 *
 * Each image goes to train/, its true sizes to train/metadata.jsonl, and the dataset card
 * (filled with the measured results of results/scan.json) to README.md. No model is called.
 */

require __DIR__ . '/Scenes.php';

const FIRST_SEED = 101;

$root = dirname(__DIR__);
$folder = rtrim($argv[1] ?? dirname($root) . '/recupbuild-scan-benchmark', '/\\');
$count = max(1, (int) ($argv[2] ?? 50));
if (!is_dir($folder . '/train') && !mkdir($folder . '/train', 0770, true)) {
    exit("cannot create {$folder}/train\n");
}

$lines = [];
$pieces = 0;
$kinds = [];
for ($seed = FIRST_SEED; $seed < FIRST_SEED + $count; ++$seed) {
    $scene = Scenes::make($seed, $root . '/demo/pile.jpg');
    $name = "scene-{$seed}.jpg";
    file_put_contents("{$folder}/train/{$name}", $scene['jpeg']);
    $lines[] = json_encode([
        'file_name' => $name,
        'seed' => $seed,
        'width' => Scenes::WIDTH,
        'height' => Scenes::HEIGHT,
        'mm_per_px' => round(1 / Scenes::PIXELS_PER_MM, 4),
        'a4' => $scene['a4'],
        'pieces' => $scene['pieces'],
    ], JSON_UNESCAPED_SLASHES);
    $pieces += count($scene['pieces']);
    foreach ($scene['pieces'] as $piece) {
        $kinds[$piece['kind']] = ($kinds[$piece['kind']] ?? 0) + 1;
    }
}
file_put_contents("{$folder}/train/metadata.jsonl", implode("\n", $lines) . "\n");

$results = json_decode((string) file_get_contents(__DIR__ . '/results/scan.json'), true, flags: JSON_THROW_ON_ERROR);
$spark = $results['muse_spark_alone'];
$sam = $results['muse_spark_with_sam'];
arsort($kinds);
$kindRows = '';
foreach ($kinds as $kind => $number) {
    $kindRows .= "| `{$kind}` | {$number} |\n";
}

$card = strtr((string) file_get_contents(__DIR__ . '/dataset-card.md'), [
    '{{scenes}}' => (string) $count,
    '{{pieces}}' => (string) $pieces,
    '{{kinds}}' => rtrim($kindRows),
    '{{first}}' => (string) FIRST_SEED,
    '{{last}}' => (string) (FIRST_SEED + $count - 1),
    '{{benchmark_scenes}}' => (string) $results['scenes'],
    '{{benchmark_last}}' => (string) (FIRST_SEED + $results['scenes'] - 1),
    '{{benchmark_pieces}}' => (string) $sam['pieces_scored'],
    '{{spark_f1}}' => number_format($spark['f1'], 3),
    '{{sam_f1}}' => number_format($sam['f1'], 3),
    '{{spark_length}}' => (string) $spark['length_mae_mm'],
    '{{sam_length}}' => (string) $sam['length_mae_mm'],
    '{{spark_width}}' => (string) $spark['width_mae_mm'],
    '{{sam_width}}' => (string) $sam['width_mae_mm'],
    '{{spark_scale}}' => (string) $spark['a4_scale_error_pct'],
    '{{sam_scale}}' => (string) $sam['a4_scale_error_pct'],
]);
file_put_contents($folder . '/README.md', $card);

printf("%d scenes, %d pieces in %s\n", $count, $pieces, $folder);
