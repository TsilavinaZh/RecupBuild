<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * A real run against the model: `php tests/live_plan.php`
 *
 * Needs MODEL_API_KEY (or MUSE_IMAGE_API_KEY) in backend-php/.env. It shows the two
 * calls of the Project and Calculation screens with the sample inventory: the model
 * invents and sizes the projects and writes the fabrication steps, the engine checks everything.
 */

use RecupBuild\Core\Config;
use RecupBuild\Services\Planner;

require_once __DIR__ . '/../src/bootstrap.php';

$config = Config::load(dirname(__DIR__) . '/.env.php');
if ($config->metaApiKey === '') {
    echo "no key in backend-php/.env (MODEL_API_KEY), nothing to ask\n";
    exit(1);
}

/** The sample inventory of the app, thicknesses filled in as a user would. */
$pieces = [
    ['id' => 1, 'kind' => 'plank', 'length_mm' => 1200, 'width_mm' => 140, 'thickness_mm' => 22],
    ['id' => 2, 'kind' => 'plank', 'length_mm' => 1000, 'width_mm' => 140, 'thickness_mm' => 22],
    ['id' => 3, 'kind' => 'plank', 'length_mm' => 800, 'width_mm' => 95, 'thickness_mm' => 18],
    ['id' => 4, 'kind' => 'batten', 'length_mm' => 900, 'width_mm' => 40, 'thickness_mm' => 40],
    ['id' => 5, 'kind' => 'plywood', 'length_mm' => 600, 'width_mm' => 400, 'thickness_mm' => 12],
    ['id' => 6, 'kind' => 'sheet_metal', 'length_mm' => 700, 'width_mm' => 500, 'thickness_mm' => 0.6],
    ['id' => 7, 'kind' => 'plank', 'length_mm' => 600, 'width_mm' => 180, 'thickness_mm' => 20],
];

$started = microtime(true);
$cards = Planner::projects($pieces, 'fr', $config)['projects'];
printf("POST /api/projects  %.1f s
", microtime(true) - $started);
foreach ($cards as $card) {
    $result = $card['result'];
    printf(
        "  %-26s %-11s %s%s
",
        $card['definition']['name'] ?? $card['definition']['id'],
        $result['status'],
        $result['plan'] ? implode(' ', array_map(static fn (string $k, mixed $v): string => $k . '=' . $v, array_keys($result['plan']['params']), $result['plan']['params'])) : 'reason=' . $result['reason'],
        $result['plan'] ? ' pieces=' . count($result['plan']['placements']) : '',
    );
    foreach ($card['definition']['parts'] as $part) {
        printf("      %d x %s
", $part['quantity'], $part['label'] ?? $part['name']);
    }
}

// The first project that can be built, as the user would click it.
$chosen = $cards[0];
$params = $chosen['result']['plan']['params'] ?? null;
$started = microtime(true);
$plan = Planner::plan($chosen['definition'], $pieces, 'fr', $params, $config);
printf("POST /api/plan (%s)  %.1f s
", $chosen['definition']['name'] ?? $chosen['definition']['id'], microtime(true) - $started);
printf("  status=%s attempts=%d placements=%d steps=%s
", $plan['status'], count($plan['iterations']), count($plan['plan']['placements'] ?? []), $plan['steps'] ? (count($plan['steps']) . ' ✓') : 'null (locales)');
foreach ($plan['steps'] ?? [] as $index => $step) {
    printf("  %d. %s
", $index + 1, $step);
}

$ok = $plan['status'] === 'feasible'
    && $plan['plan'] !== null
    && is_array($plan['steps'])
    && count($plan['steps']) === 4
    && count(array_filter($cards, static fn (array $c): bool => $c['result']['status'] === 'feasible')) >= 1;
echo $ok ? "\nlive plan OK\n" : "\nlive plan FAILED\n";
exit($ok ? 0 : 1);