<?php

declare(strict_types=1);

/**
 * The cutting engine of /api/plan and /api/projects, without calling the model:
 * the PHP port of frontend/src/domain/solver.test.ts.
 */

use RecupBuild\Projects;
use RecupBuild\Solver;

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../src/bootstrap.php';

function stockPiece(int $id, float $length, float $width, ?float $thickness, string $kind = 'plank'): array
{
    return ['id' => $id, 'kind' => $kind, 'length_mm' => $length, 'width_mm' => $width, 'thickness_mm' => $thickness];
}

function spec(array $overrides = []): array
{
    return $overrides + [
        'name' => 'board',
        'material' => 'wood',
        'lengthMm' => 500,
        'widthMm' => 100,
        'thickness' => ['min' => 15, 'max' => 40],
        'quantity' => 1,
    ];
}

/** The sample inventory of the app, thicknesses filled in as a user would. */
function demoPieces(): array
{
    return [
        stockPiece(1, 1200, 140, 22),
        stockPiece(2, 1000, 140, 22),
        stockPiece(3, 800, 95, 18),
        stockPiece(4, 900, 40, 40),
        stockPiece(5, 600, 400, 12, 'plywood'),
        stockPiece(6, 700, 500, 0.6, 'sheet_metal'),
        stockPiece(7, 600, 180, 20),
    ];
}

echo "toStock\n";

$stock = Solver::toStock([
    stockPiece(1, 140, 1200, 22),
    stockPiece(2, 800, 95, null),
    stockPiece(3, 700, 500, 0.6, 'sheet_metal'),
]);
check('keeps the longest side as the length, drops incomplete pieces', same([
    ['id' => 1, 'material' => 'wood', 'length' => 1200.0, 'width' => 140.0, 'thickness' => 22.0],
    ['id' => 3, 'material' => 'metal', 'length' => 700.0, 'width' => 500.0, 'thickness' => 0.6],
], $stock));

echo "pack\n";

['placements' => $placements, 'unsatisfied' => $unsatisfied] = Solver::pack([spec(['quantity' => 2])], Solver::toStock([stockPiece(1, 1100, 140, 22)]));
check('cuts several parts from one piece, with the saw kerf between them', $unsatisfied === [] && same([0, 503], array_column($placements, 'x')));

['unsatisfied' => $unsatisfied] = Solver::pack([spec(['quantity' => 3])], Solver::toStock([stockPiece(1, 1100, 140, 22)]));
check('reports what does not fit', same([['part' => 'board', 'missing' => 1]], $unsatisfied));

$stock = Solver::toStock([stockPiece(1, 2000, 500, 0.6, 'sheet_metal'), stockPiece(2, 2000, 200, 8)]);
check('never cuts wood from metal nor from a wrong thickness', same([['part' => 'board', 'missing' => 1]], Solver::pack([spec()], $stock)['unsatisfied']));

['placements' => $placements, 'unsatisfied' => $unsatisfied] = Solver::pack([spec(['widthMm' => 40, 'quantity' => 3])], Solver::toStock([stockPiece(1, 500, 140, 20)]));
check('places narrow parts in lanes across a wide piece', $unsatisfied === [] && count(array_unique(array_column($placements, 'y'))) === 3);

['placements' => $placements] = Solver::pack([spec(['quantity' => 3]), spec(['name' => 'brace', 'lengthMm' => 300, 'widthMm' => 40, 'quantity' => 4])], Solver::toStock(demoPieces()));
$inside = true;
$overlapping = false;
$stockById = [];
foreach (Solver::toStock(demoPieces()) as $item) {
    $stockById[$item['id']] = $item;
}
foreach ($placements as $a) {
    $s = $stockById[$a['piece_id']];
    if ($a['x'] + $a['length_mm'] > $s['length'] + 1e-9 || $a['y'] + $a['width_mm'] > $s['width'] + 1e-9) {
        $inside = false;
    }
    foreach ($placements as $b) {
        if ($a === $b || $a['piece_id'] !== $b['piece_id']) {
            continue;
        }
        $apart = $a['x'] + $a['length_mm'] <= $b['x'] || $b['x'] + $b['length_mm'] <= $a['x'] || $a['y'] + $a['width_mm'] <= $b['y'] || $b['y'] + $b['width_mm'] <= $a['y'];
        if (!$apart) {
            $overlapping = true;
        }
    }
}
check('keeps every part inside its piece and never overlapping', $inside && !$overlapping);

['placements' => $placements] = Solver::pack([spec(['quantity' => 3])], Solver::toStock(demoPieces()));
check('numbers the parts 1, 2, 3 in plan order', same([1, 2, 3], array_column($placements, 'number')));

echo "solve\n";

$result = Solver::solve('shelf', demoPieces());
$earlierOk = true;
foreach (array_slice($result['iterations'], 0, -1) as $attempt) {
    if ($attempt['status'] !== 'infeasible' || $attempt['unsatisfied'] === [] || $attempt['adjustments'] === []) {
        $earlierOk = false;
    }
    foreach ($attempt['adjustments'] as $change) {
        if ($change['to'] >= $change['from']) {
            $earlierOk = false;
        }
    }
}
check('finds the shelf after adjusting its size, and logs every attempt', $result['status'] === 'feasible' && count($result['iterations']) > 1 && $earlierOk);
check('the shelf plan holds six parts', $result['plan'] !== null && same(6, count($result['plan']['placements'])));
check('the last attempt of a success is feasible', $result['iterations'][array_key_last($result['iterations'])]['status'] === 'feasible');
check('the plan names its project, its sizes and the saw kerf', $result['plan'] !== null
    && same('shelf', $result['plan']['project'])
    && $result['plan']['kerf_mm'] === Solver::KERF_MM
    && isset($result['plan']['params']['width'], $result['plan']['params']['depth'], $result['plan']['params']['height']));

$result = Solver::solve('shelf', [stockPiece(1, 300, 100, 20)]);
$boundsOk = true;
foreach ($result['iterations'] as $attempt) {
    if ($attempt['params']['width'] < Projects::CATALOGUE['shelf']['params']['width']['min']) {
        $boundsOk = false;
    }
    if ($attempt['params']['height'] < Projects::CATALOGUE['shelf']['params']['height']['min']) {
        $boundsOk = false;
    }
}
check('never goes below the minimum size of a project', $result['status'] === 'infeasible' && $boundsOk);

$result = Solver::solve('coop', demoPieces());
check('explains the chicken coop is impossible: the sheet is too small', $result['status'] === 'infeasible' && same('metal_too_small', $result['reason']) && $result['plan'] === null);

check('says when there is no metal at all', same('no_metal', Solver::solve('dryer', [stockPiece(1, 2000, 300, 20)])['reason']));
check('says when no piece has a usable thickness', same('thickness', Solver::solve('shelf', [stockPiece(1, 3000, 300, 5)])['reason']));
check('is deterministic', same(Solver::solve('crate', demoPieces()), Solver::solve('crate', demoPieces())));

echo "parameters\n";

check('clamps a proposal inside its bounds and rounds it to tens', same(
    ['width' => 300, 'depth' => 140, 'height' => 1190],
    Projects::clamp('shelf', ['width' => 10, 'height' => 1193, 'depth' => 144]),
));
check('falls back to the defaults for the parameters it does not know', same(
    Projects::defaults('shelf'),
    Projects::clamp('shelf', ['unknown' => 500]),
));
echo "invented projects\n";

/** A stool as the model could invent it: one parameter driven, one side fixed. */
function stool(array $overrides = []): array
{
    return $overrides + [
        'id' => 'ai-1',
        'name' => 'Tabouret',
        'description' => 'Pour s’asseoir à l’atelier.',
        'params' => ['height' => ['label' => 'Hauteur', 'min' => 300, 'default' => 450, 'max' => 600]],
        'parts' => [
            ['name' => 'leg', 'label' => 'Pied', 'material' => 'wood', 'length' => ['param' => 'height', 'add' => -20], 'width' => ['param' => '', 'add' => 40], 'thickness' => ['min' => 30, 'max' => 50], 'quantity' => 4],
            ['name' => 'seat', 'label' => 'Assise', 'material' => 'wood', 'length' => ['param' => null, 'add' => 300], 'width' => ['param' => null, 'add' => 95], 'thickness' => ['min' => 15, 'max' => 25], 'quantity' => 3],
        ],
    ];
}

$definition = Projects::sanitize(stool());
check('accepts a well formed invented project', $definition !== null && $definition['parts'][0]['width'] === ['param' => null, 'add' => 40]);
check('a built-in id always gives the built-in project', same(Projects::definition('shelf'), Projects::sanitize(['id' => 'shelf', 'name' => 'x'])));
check('refuses a side driven by an unknown parameter', Projects::sanitize(stool(['parts' => [['name' => 'leg', 'material' => 'wood', 'length' => ['param' => 'width', 'add' => 0], 'width' => ['param' => null, 'add' => 40], 'thickness' => ['min' => 30, 'max' => 50], 'quantity' => 4]]])) === null);
check('refuses a side that vanishes at the smallest size', Projects::sanitize(stool(['parts' => [['name' => 'leg', 'material' => 'wood', 'length' => ['param' => 'height', 'add' => -300], 'width' => ['param' => null, 'add' => 40], 'thickness' => ['min' => 30, 'max' => 50], 'quantity' => 4]]])) === null);
check('refuses a strange id', Projects::sanitize(stool(['id' => '../etc'])) === null);
check('cuts the texts short', mb_strlen((string) Projects::sanitize(stool(['name' => str_repeat('a', 500)]))['name']) === 80);

$result = Solver::solve($definition, [stockPiece(1, 1800, 40, 40, 'batten'), stockPiece(2, 1000, 95, 20)]);
check('plans an invented project with the same engine', $result['status'] === 'feasible' && $result['plan']['project'] === 'ai-1' && count($result['plan']['placements']) === 7);
$result = Solver::solve($definition, [stockPiece(1, 1500, 40, 40, 'batten'), stockPiece(2, 1000, 95, 20)]);
check('shrinks an invented project when its parts are too long', $result['status'] === 'feasible' && $result['plan']['params']['height'] < 450);

echo "reverse build\n";

$answer = [
    'buildable' => true,
    'name' => 'Table basse',
    'description' => str_repeat('x', 900),
    'dimensions' => ['width_mm' => 900, 'depth_mm' => 500, 'height_mm' => 450],
    'difficulty' => 'easy',
    'hours' => 6,
    'parts' => [
        ['label' => 'Pied', 'material' => 'wood', 'quantity' => 4, 'length_mm' => 430, 'width_mm' => 45, 'thickness_mm' => 45, 'layout' => [
            ['x' => 0, 'y' => 0, 'z' => 0, 'length_axis' => 'y', 'width_axis' => 'x'],
            ['x' => 855, 'y' => 0, 'z' => 0, 'length_axis' => 'y', 'width_axis' => 'x'],
            ['x' => 0, 'y' => 0, 'z' => 455, 'length_axis' => 'y', 'width_axis' => 'x'],
            ['x' => 855, 'y' => 0, 'z' => 455, 'length_axis' => 'y', 'width_axis' => 'x'],
        ]],
        ['label' => 'Plateau', 'material' => 'wood', 'quantity' => 1, 'length_mm' => 500, 'width_mm' => 900, 'thickness_mm' => 20, 'layout' => [
            ['x' => 0, 'y' => 430, 'z' => 0, 'length_axis' => 'x', 'width_axis' => 'x'],
        ]],
    ],
    'hardware' => [['name' => 'Vis 4×40', 'quantity' => '16']],
    'tools' => [['name' => 'Scie', 'why' => 'Couper'], ['name' => '', 'why' => 'ignoré']],
    'steps' => ['Coupez.', 'Assemblez.', ''],
    'tips' => [],
];
$built = RecupBuild\Reverse::build($answer);
check('one numbered cut per copy, longest side first', count($built['placements']) === 5
    && $built['placements'][4]['number'] === 5 && $built['placements'][4]['length_mm'] === 900.0 && $built['placements'][4]['width_mm'] === 500.0);
check('keeps the 3D spots that make sense, drops a part with the same axis twice', count($built['assembly']) === 4);
check('cuts long texts, drops empty tools and steps', mb_strlen($built['description']) === 300 && count($built['tools']) === 1 && $built['steps'] === ['Coupez.', 'Assemblez.']);
check('refuses a photo with nothing to build', (static function (): bool {
    try {
        RecupBuild\Reverse::build(['buildable' => false, 'parts' => []]);
    } catch (RecupBuild\ScanError $error) {
        return $error->errorCode === 'empty_segmentation';
    }

    return false;
})());
