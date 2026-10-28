<?php

declare(strict_types=1);

// One-off: draw favicon.svg as images/logo.png (mail clients want a raster logo).
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$size = 128;
$img = imagecreatetruecolor($size, $size);
imagesavealpha($img, true);
imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
$s = $size / 32;
$forest = imagecolorallocate($img, 0x2F, 0x6B, 0x4F);
$paper = imagecolorallocate($img, 0xF6, 0xF3, 0xEC);
$copper = imagecolorallocate($img, 0xC4, 0x62, 0x2D);

/** A rounded rectangle the way the SVG draws one (corner circles + two rects). */
$round = static function (float $x0, float $y0, float $x1, float $y1, float $r, $color) use ($img, $s): void {
    $x0 *= $s;
    $y0 *= $s;
    $x1 *= $s;
    $y1 *= $s;
    $r *= $s;
    imagefilledrectangle($img, (int) ($x0 + $r), (int) $y0, (int) ($x1 - $r), (int) $y1, $color);
    imagefilledrectangle($img, (int) $x0, (int) ($y0 + $r), (int) $x1, (int) ($y1 - $r), $color);
    imagefilledellipse($img, (int) ($x0 + $r), (int) ($y0 + $r), (int) (2 * $r), (int) (2 * $r), $color);
    imagefilledellipse($img, (int) ($x1 - $r), (int) ($y0 + $r), (int) (2 * $r), (int) (2 * $r), $color);
    imagefilledellipse($img, (int) ($x0 + $r), (int) ($y1 - $r), (int) (2 * $r), (int) (2 * $r), $color);
    imagefilledellipse($img, (int) ($x1 - $r), (int) ($y1 - $r), (int) (2 * $r), (int) (2 * $r), $color);
};

$round(1, 1, 31, 31, 9, $forest);
$round(7, 8, 22, 12, 1.5, $paper);
$round(11, 14, 26, 18, 1.5, $paper);
$round(7, 20, 17, 24, 1.5, $paper);
$round(20.5, 20, 26, 26.5, 1.3, $copper);

$out = dirname(__DIR__) . '/images/logo.png';
imagepng($img, $out);
imagedestroy($img);
echo 'written ' . $out . ' (' . filesize($out) . " bytes)\n";
