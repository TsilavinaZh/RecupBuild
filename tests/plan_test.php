<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The cutting engine of /api/plan and /api/projects, without calling the model:
 * the PHP port of frontend/src/domain/solver.test.ts.
 */

use RecupBuild\Models\Projects;
use RecupBuild\Services\Solver;

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
$built = RecupBuild\Services\Reverse::build($answer);
check('one numbered cut per copy, longest side first', count($built['placements']) === 5
    && $built['placements'][4]['number'] === 5 && $built['placements'][4]['length_mm'] === 900.0 && $built['placements'][4]['width_mm'] === 500.0);
check('keeps the 3D spots that make sense, drops a part with the same axis twice', count($built['assembly']) === 4);
check('cuts long texts, drops empty tools and steps', mb_strlen($built['description']) === 300 && count($built['tools']) === 1 && $built['steps'] === ['Coupez.', 'Assemblez.']);
check('refuses a photo with nothing to build', (static function (): bool {
    try {
        RecupBuild\Services\Reverse::build(['buildable' => false, 'parts' => []]);
    } catch (RecupBuild\Errors\ScanError $error) {
        return $error->errorCode === 'empty_segmentation';
    }

    return false;
})());

echo "drawings and workspace\n";

$matrix = RecupBuild\Services\Drawing::photoMatrix([[100, 50], [300, 50], [300, 90], [100, 90]], 1000, 200);
check('lays an upright outline onto the piece rectangle', $matrix === 'matrix(5 0 0 5 -500 -250)');
check('gives up on an outline that is not four corners', RecupBuild\Services\Drawing::photoMatrix([[0, 0], [1, 1]], 100, 50) === null);
$shelf = RecupBuild\Services\Solver::solve('shelf', demoPieces());
$layout = RecupBuild\Services\Assembly::of($shelf['plan'], null);
check('the built-in shelf has a place for each of its parts', $layout !== null && count($layout) === count($shelf['plan']['placements']));
$sizes = [];
foreach ($shelf['plan']['placements'] as $placement) {
    $sizes[$placement['number']] = $placement;
}
$iso = RecupBuild\Services\Drawing::isometric($layout, $sizes, 'Étagère <b>');
check('draws the assembled object, its label escaped', str_starts_with($iso, '<svg class="iso"') && substr_count($iso, '<polygon') === 3 * count($layout) && str_contains($iso, 'Étagère &lt;b&gt;'));
check('an invented project without layout has no picture', RecupBuild\Services\Assembly::of(['project' => 'ai-1', 'params' => [], 'placements' => []], null) === null);

$scanned = [['id' => 1, 'kind' => 'plank', 'length_mm' => 1200.0, 'width_mm' => 140.0, 'thickness_mm' => 22.0, 'outline' => [[0, 0], [1, 0], [1, 1], [0, 1]]],
    ['id' => 2, 'kind' => 'plank', 'length_mm' => 800.0, 'width_mm' => 95.0, 'thickness_mm' => null, 'outline' => []]];
check('flags a missing thickness', RecupBuild\Models\Workspace::issues($scanned) === [2 => ['thickness_mm' => 'missing']]);
$edited = RecupBuild\Models\Workspace::fromForm($scanned, [1 => ['kind' => 'batten', 'length_mm' => '1 150', 'width_mm' => '40,5', 'thickness_mm' => '40'], 2 => ['remove' => '1']]);
check('reads the corrected table, keeps the outline, drops a removed piece', count($edited) === 1 && $edited[0]['kind'] === 'batten'
    && $edited[0]['width_mm'] === 40.5 && $edited[0]['length_mm'] === null && $edited[0]['outline'] === [[0, 0], [1, 0], [1, 1], [0, 1]]);
check('refuses an unknown kind of material', RecupBuild\Models\Workspace::fromForm($scanned, [1 => ['kind' => '<script>']])[0]['kind'] === 'plank');


echo "realistic preview\n";

$plan = [
    'definition' => [
        'id' => 'bench',
        'name' => 'Low Workshop Bench',
        'description' => 'A sturdy low bench.',
        'parts' => [['name' => 'top', 'label' => 'Top'], ['name' => 'leg', 'label' => 'Leg']],
    ],
    'result' => ['plan' => [
        'params' => ['length' => 650.4, 'height' => 350],
        'placements' => [
            ['part' => 'top', 'length_mm' => 650, 'width_mm' => 150],
            ['part' => 'leg', 'length_mm' => 350, 'width_mm' => 150],
            ['part' => 'leg', 'length_mm' => 350, 'width_mm' => 150],
        ],
    ]],
];
$prompt = RecupBuild\Services\Preview::prompt($plan);
check('the prompt names the object', str_contains($prompt, 'finished Low Workshop Bench'));
check('the prompt gives the sizes of the plan', str_contains($prompt, 'length 650 mm') && str_contains($prompt, 'height 350 mm'));
check('the prompt counts the parts, under their labels', str_contains($prompt, '1 x Top 650 x 150 mm') && str_contains($prompt, '2 x Leg 350 x 150 mm'));
check('the object is made of the wood of the photo', str_contains($prompt, 'reclaimed materials in the reference photo'));
check('the prompt survives an empty plan', str_contains(RecupBuild\Services\Preview::prompt([]), 'finished object'));

$pdo = RecupBuild\Core\Database::open(':memory:');
$owner = RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'preview-owner', 'email' => null, 'name' => 'Owner', 'picture' => null]);
$other = RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'preview-other', 'email' => null, 'name' => 'Other', 'picture' => null]);
$saved = RecupBuild\Models\Plans::save($pdo, $owner['id'], ['id' => 'bench', 'name' => 'Bench'], [], []);
check('a new plan has no preview', RecupBuild\Models\Plans::find($pdo, $owner['id'], $saved)['preview'] === null);
RecupBuild\Models\Plans::savePreview($pdo, $other['id'], $saved, 'stolen.jpg');
check('nobody can set the preview of a plan of someone else', RecupBuild\Models\Plans::find($pdo, $owner['id'], $saved)['preview'] === null);
RecupBuild\Models\Plans::savePreview($pdo, $owner['id'], $saved, 'picture.jpg');
check('a preview stays with its plan', RecupBuild\Models\Plans::find($pdo, $owner['id'], $saved)['preview'] === 'picture.jpg');


echo "instant demo
";

$sample = array_map(static fn (array $piece): array => array_diff_key($piece, ['outline' => 1]), RecupBuild\Services\Demo::scan()['pieces']);
foreach (['en', 'fr'] as $locale) {
    $projects = RecupBuild\Services\Demo::projects($locale);
    $complete = 0;
    foreach ($projects as $saved) {
        $result = Solver::solve($saved['definition'], $sample, $saved['params']);
        $numbers = array_column($result['plan']['placements'] ?? [], 'number');
        if ($result['status'] === 'feasible' && $result['plan']['params'] == $saved['params']
            && $saved['steps'] !== [] && $saved['assembly'] !== []
            && array_diff(array_column($saved['assembly'], 'number'), $numbers) === []) {
            ++$complete;
        }
    }
    check("the demo in {$locale} offers buildable projects with their steps and 3D view", count($projects) >= 3 && $complete === count($projects), $complete . ' of ' . count($projects));
}
check('a language without its own projects falls back on French', RecupBuild\Services\Demo::projects('mg') === RecupBuild\Services\Demo::projects('fr'));

// Files go to a folder of this test, never to the real photos.
$storage = getenv('STORAGE_PATH');
$folder = sys_get_temp_dir() . '/recupbuild-test-' . bin2hex(random_bytes(6));
putenv('STORAGE_PATH=' . $folder);
$picture = RecupBuild\Models\Photos::store('picture');
$kept = RecupBuild\Models\Plans::save($pdo, $owner['id'], ['id' => 'bench', 'name' => 'Bench'], [], []);
RecupBuild\Models\Plans::savePreview($pdo, $owner['id'], $kept, $picture);
RecupBuild\Models\Plans::delete($pdo, $other['id'], $kept);
check('nobody can delete the picture of a plan of someone else', RecupBuild\Models\Photos::path($picture) !== null);
RecupBuild\Models\Plans::delete($pdo, $owner['id'], $kept);
check('deleting a plan deletes its generated picture', RecupBuild\Models\Photos::path($picture) === null);
file_put_contents($folder . '/private.txt', 'stays');
RecupBuild\Models\Photos::remove('../private.txt');
check('removing refuses a name it did not give', is_file($folder . '/private.txt'));
unlink($folder . '/private.txt');
$plain = RecupBuild\Models\Plans::save($pdo, $owner['id'], ['id' => 'bench', 'name' => 'Bench'], [], []);
check('deleting a plan with no picture still works', RecupBuild\Models\Plans::delete($pdo, $owner['id'], $plain));
check('deleting a plan that is gone says so', !RecupBuild\Models\Plans::delete($pdo, $owner['id'], $plain));
@rmdir($folder . '/photos');
@rmdir($folder);
putenv($storage === false ? 'STORAGE_PATH' : 'STORAGE_PATH=' . $storage);


echo "open weights\n";

// A stand-in for a local model server, on a port of its own, never the one of the app.
const STUB_PORT = 8096;
$stub = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . STUB_PORT, __DIR__ . '/fixtures/openai_stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
    $pipes,
);
// Whatever happens below, the stand-in stops with the tests.
register_shutdown_function(static function () use ($stub): void {
    proc_terminate($stub);
});
for ($wait = 0; $wait < 100 && @fsockopen('127.0.0.1', STUB_PORT, $code, $message, 0.1) === false; ++$wait) {
    usleep(50000);
}

$local = new RecupBuild\Core\Config();
$local->openWeightsBase = 'http://127.0.0.1:' . STUB_PORT . '/v1';
$local->openWeightsModel = 'muse-glimmer:test';

$answer = RecupBuild\Services\Vision::complete('Hello', ['type' => 'object'], 'Steps', $local);
check('the text steps go to the local server, with no Meta key', $answer['path'] === '/v1/chat/completions' && $answer['model'] === 'muse-glimmer:test', json_encode($answer));
check('they keep their JSON schema', $answer['schema'] === 'Steps');
check('no key is sent to a local server that wants none', $answer['auth'] === '');

$local->openWeightsKey = 'local-key';
$answer = RecupBuild\Services\Vision::complete('Hello', ['type' => 'object'], 'Steps', $local);
check('a key is sent when the server wants one', $answer['auth'] === 'Bearer local-key');

$local->metaApiKey = 'meta-key';
$local->metaApiBase = 'http://127.0.0.1:' . STUB_PORT . '/hosted';
$local->openWeightsBase = 'http://127.0.0.1:' . (STUB_PORT - 1) . '/v1';
ini_set('log_errors', '0');
$answer = RecupBuild\Services\Vision::complete('Hello', ['type' => 'object'], 'Steps', $local);
check('when the local server is down the app goes back to Muse Spark', $answer['path'] === '/hosted/chat/completions' && $answer['model'] === $local->visionModel && $answer['auth'] === 'Bearer meta-key', json_encode($answer));

$local->metaApiKey = '';
$failed = false;
try {
    RecupBuild\Services\Vision::complete('Hello', ['type' => 'object'], 'Steps', $local);
} catch (RecupBuild\Errors\VisionError) {
    $failed = true;
}
ini_set('log_errors', '1');
check('with neither model the failure is reported', $failed);

$hosted = new RecupBuild\Core\Config();
$hosted->metaApiKey = 'meta-key';
$hosted->metaApiBase = 'http://127.0.0.1:' . STUB_PORT . '/hosted';
$answer = RecupBuild\Services\Vision::complete('Hello', ['type' => 'object'], 'Steps', $hosted);
check('without a local server nothing changes', $answer['path'] === '/hosted/chat/completions' && $answer['model'] === $hosted->visionModel);


echo "the simple project
";

foreach ([
    'a wide short panel' => [320, 170, 12],
    'a long plank' => [2400, 140, 22],
    'a nearly square board' => [300, 290, 18],
    'a short offcut' => [120, 95, 24],
] as $label => [$pieceLength, $pieceWidth, $pieceThickness]) {
    $offcut = [['id' => 1, 'kind' => 'plank', 'length_mm' => $pieceLength, 'width_mm' => $pieceWidth, 'thickness_mm' => $pieceThickness]];
    $simple = Projects::simplest(Solver::toStock($offcut), 'en');
    check("the simple project always fits {$label}", $simple !== null && Solver::solve($simple, $offcut)['status'] === 'feasible');
}


echo "preview retries\n";

$retryable = static fn (string $code, string $message): bool => RecupBuild\Services\Preview::retryable(new RecupBuild\Errors\VisionError($code, $message));
check('a busy image model is asked again', $retryable('server', 'Model answered HTTP 503 on /images/edits: busy'));
check('a refused image is not', !$retryable('server', 'Model answered HTTP 400 on /images/edits: content_policy_violation'));
check('a timeout is not', !$retryable('timeout', 'Operation timed out'));


echo "one picture per plan\n";

$claims = RecupBuild\Core\Database::open(':memory:');
$buyer = RecupBuild\Models\Users::signedIn($claims, ['sub' => 'claim-owner', 'email' => null, 'name' => 'Owner', 'picture' => null]);
$stranger = RecupBuild\Models\Users::signedIn($claims, ['sub' => 'claim-other', 'email' => null, 'name' => 'Other', 'picture' => null]);
$claimed = RecupBuild\Models\Plans::save($claims, $buyer['id'], ['id' => 'bench', 'name' => 'Bench'], [], []);
check('nobody reserves the picture of a plan of someone else', !RecupBuild\Models\Plans::claimPreview($claims, $stranger['id'], $claimed, 1000));
check('the first click reserves the picture', RecupBuild\Models\Plans::claimPreview($claims, $buyer['id'], $claimed, 1000));
check('a second click pays for nothing', !RecupBuild\Models\Plans::claimPreview($claims, $buyer['id'], $claimed, 1001));
RecupBuild\Models\Plans::releasePreview($claims, $buyer['id'], $claimed);
check('a failed picture can be tried again', RecupBuild\Models\Plans::claimPreview($claims, $buyer['id'], $claimed, 1002));
check('a request that died does not block the plan forever', RecupBuild\Models\Plans::claimPreview($claims, $buyer['id'], $claimed, 1002 + 301));
RecupBuild\Models\Plans::savePreview($claims, $buyer['id'], $claimed, 'picture.jpg');
check('a finished picture is never made again', !RecupBuild\Models\Plans::claimPreview($claims, $buyer['id'], $claimed, 9999));
RecupBuild\Models\Plans::releasePreview($claims, $buyer['id'], $claimed);
check('releasing leaves a finished picture alone', RecupBuild\Models\Plans::find($claims, $buyer['id'], $claimed)['preview'] === 'picture.jpg');
