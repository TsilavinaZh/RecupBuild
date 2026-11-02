<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Geometry and validation of the scan, without calling the model.
 * The PHP port of backend/tests/test_scan.py.
 */

use RecupBuild\Core\Config;
use RecupBuild\Services\Image;
use RecupBuild\Services\SamMask;
use RecupBuild\Services\Scan;
use RecupBuild\Services\Segmentation;

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../src/bootstrap.php';

/** An upright A4 sheet: 210 x 297 units on the 0-1000 grid on a 1000 px image, so 1 unit = 1 mm. */
function a4(float $x = 100, float $y = 100, float $scale = 1.0): array
{
    $w = 210 * $scale;
    $h = 297 * $scale;

    return ['found' => true, 'corners' => [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]]];
}

function piece(float $x, float $y, float $length, float $width, string $kind = 'plank'): array
{
    return ['kind' => $kind, 'corners' => [[$x, $y], [$x + $length, $y], [$x + $length, $y + $width], [$x, $y + $width]]];
}

const W = 1000;
const H = 1000;

echo "scan geometry\n";

$result = Scan::buildResponse(['a4' => a4(), 'pieces' => [piece(400, 100, 500, 140)]], W, H);
check('measures pieces with the A4 scale (mm_per_px = 1)', same(1.0, $result['a4']['mm_per_px']), var_export($result['a4']['mm_per_px'], true));
check('measured piece (length, width, outline, usual thickness)', same([
    'id' => 1,
    'kind' => 'plank',
    'length_mm' => 500,
    'width_mm' => 140,
    'thickness_mm' => 22.0,
    'outline' => [[400.0, 100.0], [900.0, 100.0], [900.0, 240.0], [400.0, 240.0]],
], $result['pieces'][0]));

$result = Scan::buildResponse(['a4' => a4(100, 100, 0.5), 'pieces' => [piece(400, 100, 300, 50)]], W, H);
check('scale follows the size of the sheet (600 x 100 mm)', same([600, 100], [$result['pieces'][0]['length_mm'], $result['pieces'][0]['width_mm']]));

$result = Scan::buildResponse(['a4' => a4(), 'pieces' => [piece(400, 100, 60, 400)]], W, H);
check('length is the longest side whatever the orientation', same([400, 60], [$result['pieces'][0]['length_mm'], $result['pieces'][0]['width_mm']]));

$result = Scan::buildResponse(['a4' => a4(), 'pieces' => [piece(400, 100, 503, 138)]], W, H);
check('rounds to five millimetres', same([505, 140], [$result['pieces'][0]['length_mm'], $result['pieces'][0]['width_mm']]));

$result = Scan::buildResponse(['a4' => a4(), 'pieces' => [piece(400, 100, 500, 140, 'sheet_metal') + ['thickness_mm' => 0.8]]], W, H);
check('keeps a plausible thickness estimate of the model', $result['pieces'][0]['thickness_mm'] === 0.8);
check('replaces an implausible estimate by the usual thickness of the kind', Scan::thickness('plywood', 90, 400) === 12.0);
check('fills the thickness when the model gave none', Scan::thickness('batten', null, 40) === 30.0);
check('never makes a piece thicker than it is wide', Scan::thickness('batten', 60, 25) === 25.0);

echo "scan validation\n";

foreach ([
    'found=false' => ['found' => false, 'corners' => []],
    'found, no corner' => ['found' => true, 'corners' => []],
    'missing sheet' => null,
] as $label => $sheet) {
    throwsScan(
        static fn () => Scan::buildResponse(['a4' => $sheet, 'pieces' => [piece(400, 100, 500, 140)]], W, H),
        'a4_not_detected',
        "a4 not detected ({$label})",
    );
}

throwsScan(
    static fn () => Scan::buildResponse(['a4' => a4(), 'pieces' => []], W, H),
    'empty_segmentation',
    'no piece is reported',
);

$result = Scan::buildResponse([
    'a4' => a4(),
    'pieces' => [
        piece(400, 100, 500, 2),
        ['kind' => 'plank', 'corners' => [[0, 0]]],
        piece(400, 300, 500, 100, 'carpet'),
    ],
], W, H);
check('ignores degenerate outlines and unknown kinds', same(['other'], array_column($result['pieces'], 'kind')));
check('pieces are renumbered from 1', same([1], array_column($result['pieces'], 'id')));

$result = Scan::buildResponse(['a4' => a4(), 'pieces' => [piece(900, 100, 200, 100)]], W, H);
check('clamps coordinates outside the grid', same(1000.0, max(array_column($result['pieces'][0]['outline'], 0))));

echo "image preparation\n";

$config = Config::load(dirname(__DIR__) . '/.env.php');

$source = imagecreatetruecolor(4000, 3000);
imagefill($source, 0, 0, imagecolorallocate($source, 255, 255, 255));
ob_start();
imagepng($source);
$png = (string) ob_get_clean();
imagedestroy($source);

[$jpeg, $width, $height] = Image::prepare($png, $config);
check('downsizes a 4000 x 3000 photo to 1600 x 1200', same([1600, 1200], [$width, $height]), "{$width} x {$height}");
check('re-encodes as JPEG', str_starts_with($jpeg, "\xFF\xD8"));

throwsScan(static fn () => Image::prepare('not an image', $config), 'unsupported_type', 'refuses a file that is not an image');

echo "SAM 3.1\n";

$answer = (string) file_get_contents(__DIR__ . '/fixtures/sam-wood.txt');
$records = Segmentation::parse($answer);
check('reads every record of a real SAM answer', count($records) === 10, (string) count($records));

$record = $records[2];
$mask = SamMask::decode($record['payload'], $record['width'], $record['height']);
check('decodes a real mask to its full size', count($mask) === 72 * 395, (string) count($mask));
check('the mask holds only zeros and ones', array_diff($mask, [0, 1]) === []);
check('the mask fills the box of its plank', abs(array_sum($mask) / count($mask) - 0.939) < 0.001, (string) (array_sum($mask) / count($mask)));

$refused = false;
try {
    SamMask::decode('!!!!!!', 10, 10);
} catch (RuntimeException) {
    $refused = true;
}
check('refuses a mask that is not one', $refused);
$refused = false;
try {
    SamMask::decode($record['payload'], 100000, 100000);
} catch (RuntimeException) {
    $refused = true;
}
check('refuses a mask too large to decode', $refused);

$objects = Segmentation::objects($answer);
check('turns each trace into a rectangle', count($objects) === 10 && count($objects[0]['corners']) === 4);
check('a rectangle hugs a tilted plank tighter than its box', $objects[0]['area'] < 0.9 * (130 - 33) * (454 - 19), (string) $objects[0]['area']);

$angle = deg2rad(20);
$tilted = [];
foreach ([[0, 0], [300, 0], [300, 100], [0, 100], [150, 50]] as [$x, $y]) {
    $tilted[] = [500 + $x * cos($angle) - $y * sin($angle), 200 + $x * sin($angle) + $y * cos($angle)];
}
$rectangle = Segmentation::tightestRectangle($tilted);
$first = hypot($rectangle[1][0] - $rectangle[0][0], $rectangle[1][1] - $rectangle[0][1]);
$second = hypot($rectangle[2][0] - $rectangle[1][0], $rectangle[2][1] - $rectangle[1][1]);
check('finds the tightest rectangle of a tilted shape', abs(max($first, $second) - 300) < 0.01 && abs(min($first, $second) - 100) < 0.01, "{$first} x {$second}");
check('three aligned points make no rectangle', Segmentation::tightestRectangle([[0.0, 0.0], [1.0, 1.0], [2.0, 2.0]]) === null);

/** A trace of SAM, from its edges on a 1000 x 1000 photo. */
function trace(float $left, float $top, float $right, float $bottom, float $confidence = 0.95): array
{
    return [
        'corners' => [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]],
        'box' => [$left, $top, $right, $bottom],
        'area' => ($right - $left) * ($bottom - $top),
        'confidence' => $confidence,
    ];
}

function rectangleOn(float $left, float $top, float $right, float $bottom): array
{
    return [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]];
}

$sheet = ['found' => true, 'corners' => rectangleOn(790, 90, 940, 302)];
$spark = ['a4' => $sheet, 'pieces' => [['kind' => 'plywood', 'thickness_mm' => 12, 'corners' => rectangleOn(120, 120, 420, 320)]]];

$merged = Segmentation::merge($spark, ['paper' => [trace(800, 100, 950, 312)], 'wood' => [trace(100, 100, 400, 300)], 'metal' => []], 1000, 1000);
check('a piece takes the corners SAM traced', same(rectangleOn(100, 100, 400, 300), $merged['pieces'][0]['corners']));
check('and keeps what Muse Spark read', $merged['pieces'][0]['kind'] === 'plywood' && $merged['pieces'][0]['thickness_mm'] === 12);
check('the A4 sheet takes its real edges too', same(rectangleOn(800, 100, 950, 312), $merged['a4']['corners']));
check('counts what was sharpened', $merged['refined'] === 2, (string) $merged['refined']);

$thin = ['a4' => $sheet, 'pieces' => [['kind' => 'batten', 'thickness_mm' => 30, 'corners' => rectangleOn(130, 100, 160, 500)]]];
$merged = Segmentation::merge($thin, ['wood' => [trace(100, 100, 135, 500)]], 1000, 1000);
check('a thin piece finds its trace beside it, without a double', count($merged['pieces']) === 1 && same(rectangleOn(100, 100, 135, 500), $merged['pieces'][0]['corners']));

$merged = Segmentation::merge($spark, ['wood' => [trace(100, 100, 400, 300), trace(500, 500, 700, 600)]], 1000, 1000);
check('a piece only SAM saw joins the inventory', count($merged['pieces']) === 2 && $merged['pieces'][1]['kind'] === 'plank');
check('with no thickness, for the usual one of its kind', $merged['pieces'][1]['thickness_mm'] === null);

$merged = Segmentation::merge($spark, ['wood' => [trace(100, 100, 400, 300), trace(500, 500, 700, 600, 0.6)]], 1000, 1000);
check('an unsure trace adds nothing', count($merged['pieces']) === 1);

$merged = Segmentation::merge($spark, ['paper' => [trace(800, 100, 950, 312)], 'wood' => [trace(800, 100, 950, 312)]], 1000, 1000);
check('the A4 sheet is never a piece', count($merged['pieces']) === 1);

$merged = Segmentation::merge($spark, ['metal' => [trace(120, 120, 130, 130)]], 1000, 1000);
check('a trace of another size leaves the piece as it is', same($spark['pieces'][0]['corners'], $merged['pieces'][0]['corners']));

$merged = Segmentation::merge(['a4' => ['found' => false, 'corners' => []], 'pieces' => []], ['paper' => [trace(800, 100, 950, 312)]], 1000, 1000);
check('SAM can find the sheet Muse Spark missed', $merged['a4']['found'] === true);

ini_set('log_errors', '0');
$untouched = Segmentation::refine($spark, 'jpeg', 1000, 1000, new Config());
ini_set('log_errors', '1');
check('without SAM the scan keeps the corners of Muse Spark', same($spark['pieces'], $untouched['pieces']) && $untouched['refined'] === 0);


echo "benchmark scenes
";

require_once dirname(__DIR__) . '/benchmarks/Scenes.php';

$photo = dirname(__DIR__) . '/demo/pile.jpg';
$scene = Scenes::make(101, $photo);
check('a scene is rebuilt identically from its seed', $scene === Scenes::make(101, $photo));
check('another seed makes another scene', $scene['pieces'] !== Scenes::make(102, $photo)['pieces']);
check('a scene has its A4 sheet and its pieces', isset($scene['a4']['x'], $scene['a4']['y']) && count($scene['pieces']) >= 5);
check('each piece has its long side first', array_filter($scene['pieces'], static fn (array $piece): bool => $piece['length_mm'] < $piece['width_mm']) === []);
check('each angle is within a quarter turn of vertical', array_filter($scene['pieces'], static fn (array $piece): bool => $piece['rotation_deg'] <= -90 || $piece['rotation_deg'] > 90) === []);

