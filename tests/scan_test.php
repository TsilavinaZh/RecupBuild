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
use RecupBuild\Services\Scan;

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