<?php

declare(strict_types=1);

namespace RecupBuild\Services;

/**
 * Pictures drawn by the server, as SVG markup (no image file, no library):
 *
 *   - photoMatrix(): lays the user's photo onto a piece drawn as a straight rectangle
 *     (the real wood under the cutting plan);
 *   - isometric(): a still three-quarter view of an assembled object, for the project
 *     cards and the history (the result page has the interactive 3D);
 *   - plank(): one reclaimed plank with its own grain and knots, for the home page.
 *
 * Every number printed is formatted here, never user text: the markup is safe as is.
 */
final class Drawing
{
    /**
     * The SVG matrix(a b c d e f) mapping the photo onto a `length` × `width` rectangle
     * (x along the length): the outline on the photo lands on the rectangle, straightened.
     *
     * @param list<array{0: float, 1: float}> $outline the piece's four corners on the photo
     */
    public static function photoMatrix(array $outline, float $length, float $width): ?string
    {
        if (count($outline) !== 4 || $length <= 0 || $width <= 0) {
            return null;
        }
        [$p0, $p1, , $p3] = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], array_values($outline));
        $origin = $p0;
        $u = [$p1[0] - $p0[0], $p1[1] - $p0[1]];
        $v = [$p3[0] - $p0[0], $p3[1] - $p0[1]];
        // The longer side of the outline is the piece's length.
        if (hypot(...$u) < hypot(...$v)) {
            [$u, $v] = [$v, $u];
        }
        // Keep the photo the right way round: start from the other end of the width when needed.
        if ($u[0] * $v[1] - $u[1] * $v[0] < 0) {
            $origin = [$origin[0] + $v[0], $origin[1] + $v[1]];
            $v = [-$v[0], -$v[1]];
        }
        $det = $u[0] * $v[1] - $u[1] * $v[0];
        if (abs($det) < 1e-6) {
            return null;
        }
        $a = $length * $v[1] / $det;
        $c = -$length * $v[0] / $det;
        $b = -$width * $u[1] / $det;
        $d = $width * $u[0] / $det;
        $e = -($a * $origin[0] + $c * $origin[1]);
        $f = -($b * $origin[0] + $d * $origin[1]);

        return 'matrix(' . implode(' ', array_map(static fn (float $n): string => self::n($n, 6), [$a, $b, $c, $d, $e, $f])) . ')';
    }

    /**
     * A still three-quarter view of the assembled object: each part a box with its top,
     * front and side faces, drawn back to front, lit from the top left.
     *
     * @param list<array{number: int, x: float, y: float, z: float, length_axis: string, width_axis: string}> $assembly
     * @param array<int, array{length_mm: float, width_mm: float, thickness_mm: float, metal?: bool}> $sizes by placement number
     */
    public static function isometric(array $assembly, array $sizes, string $label = ''): string
    {
        $boxes = [];
        foreach ($assembly as $item) {
            $size = $sizes[$item['number']] ?? null;
            if ($size === null) {
                continue;
            }
            $third = array_values(array_diff(['x', 'y', 'z'], [$item['length_axis'], $item['width_axis']]))[0] ?? 'z';
            $dims = ['x' => 0.0, 'y' => 0.0, 'z' => 0.0];
            $dims[$item['length_axis']] = (float) $size['length_mm'];
            $dims[$item['width_axis']] = (float) $size['width_mm'];
            $dims[$third] = max(1.0, (float) $size['thickness_mm']);
            $boxes[] = ['x' => $item['x'], 'y' => $item['y'], 'z' => $item['z'], 'w' => $dims['x'], 'h' => $dims['y'], 'd' => $dims['z'], 'metal' => !empty($size['metal'])];
        }
        if ($boxes === []) {
            return '';
        }

        // Three-quarter view: x goes right and down, z goes left and down, y goes up.
        $cos = 0.866;
        $sin = 0.5;
        $project = static fn (float $x, float $y, float $z): array => [($x - $z) * $cos, ($x + $z) * $sin - $y];

        // Back to front: what is further (small x + z) and lower is drawn first.
        usort($boxes, static fn (array $a, array $b): int => [$a['x'] + $a['z'] + $a['w'] / 2 + $a['d'] / 2, $a['y']] <=> [$b['x'] + $b['z'] + $b['w'] / 2 + $b['d'] / 2, $b['y']]);

        $faces = '';
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($boxes as $box) {
            [$x, $y, $z, $w, $h, $d] = [$box['x'], $box['y'], $box['z'], $box['w'], $box['h'], $box['d']];
            $tone = $box['metal'] ? ['#c3c9ca', '#9aa3a5', '#7d878a'] : ['#e1bf93', '#c99a66', '#a8763f'];
            $polygons = [
                // Top (y + h), then the face towards the viewer on x (side) and on z (front).
                [$tone[0], [[$x, $y + $h, $z], [$x + $w, $y + $h, $z], [$x + $w, $y + $h, $z + $d], [$x, $y + $h, $z + $d]]],
                [$tone[2], [[$x + $w, $y, $z], [$x + $w, $y, $z + $d], [$x + $w, $y + $h, $z + $d], [$x + $w, $y + $h, $z]]],
                [$tone[1], [[$x, $y, $z + $d], [$x + $w, $y, $z + $d], [$x + $w, $y + $h, $z + $d], [$x, $y + $h, $z + $d]]],
            ];
            foreach ($polygons as [$fill, $corners]) {
                $points = [];
                foreach ($corners as [$cx, $cy, $cz]) {
                    [$px, $py] = $project($cx, $cy, $cz);
                    $points[] = self::n($px) . ',' . self::n($py);
                    $minX = min($minX, $px);
                    $maxX = max($maxX, $px);
                    $minY = min($minY, $py);
                    $maxY = max($maxY, $py);
                }
                $faces .= '<polygon points="' . implode(' ', $points) . '" fill="' . $fill . '" stroke="#6e4724" stroke-opacity="0.55" stroke-width="' . self::n(max($maxX - $minX, 1) / 400) . '" stroke-linejoin="round"/>';
            }
        }

        $pad = max($maxX - $minX, $maxY - $minY) * 0.08;
        $viewBox = implode(' ', array_map(static fn (float $n): string => self::n($n), [$minX - $pad, $minY - $pad, $maxX - $minX + 2 * $pad, $maxY - $minY + 2 * $pad]));
        $shadow = '<ellipse cx="' . self::n(($minX + $maxX) / 2) . '" cy="' . self::n($maxY) . '" rx="' . self::n(($maxX - $minX) * 0.48) . '" ry="' . self::n(($maxX - $minX) * 0.07) . '" fill="#1f2a24" opacity="0.12"/>';

        return '<svg class="iso" viewBox="' . $viewBox . '" role="img"' . ($label !== '' ? ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' : ' aria-hidden="true"') . '>' . $shadow . $faces . '</svg>';
    }

    /**
     * One plank centred on (0, 0): its body, grain lines and knots, from a seed (the
     * same seed always draws the same plank).
     */
    public static function plank(float $width, float $height, int $seed, float $tone = 0.4, string $kind = 'plank'): string
    {
        $random = self::seeded($seed);
        $id = 'p' . $seed;
        $fill = $kind === 'metal' ? "url(#{$id}s)" : self::mixTone($tone);
        $svg = '<defs><clipPath id="' . $id . '"><rect x="' . self::n(-$width / 2) . '" y="' . self::n(-$height / 2) . '" width="' . self::n($width) . '" height="' . self::n($height) . '" rx="1.5"/></clipPath>';
        if ($kind === 'metal') {
            $svg .= '<linearGradient id="' . $id . 's" x1="0" x2="1" gradientTransform="scale(' . self::n(11 / $width, 5) . ' 1)" spreadMethod="repeat"><stop offset="0" stop-color="#6f7a7c"/><stop offset="0.45" stop-color="#a9b1b2"/><stop offset="0.55" stop-color="#d9dfe0"/><stop offset="1" stop-color="#6f7a7c"/></linearGradient>';
        }
        $svg .= '</defs>';
        $svg .= '<rect x="' . self::n(-$width / 2 + 2) . '" y="' . self::n(-$height / 2 + 3) . '" width="' . self::n($width) . '" height="' . self::n($height) . '" rx="1.5" fill="#1f2a24" opacity="0.14"/>';
        $svg .= '<g clip-path="url(#' . $id . ')"><rect x="' . self::n(-$width / 2) . '" y="' . self::n(-$height / 2) . '" width="' . self::n($width) . '" height="' . self::n($height) . '" fill="' . $fill . '"/>';

        if ($kind === 'metal') {
            for ($x = -$width / 2 + 5.5; $x < $width / 2; $x += 11) {
                $svg .= '<line x1="' . self::n($x) . '" x2="' . self::n($x) . '" y1="' . self::n(-$height / 2) . '" y2="' . self::n($height / 2) . '" stroke="#1f2a24" stroke-width="0.4" opacity="0.18"/>';
            }
            foreach ([[-0.28, -0.2, 0.06], [0.18, 0.28, 0.04], [0.36, -0.32, 0.03]] as [$rx, $ry, $rr]) {
                $svg .= '<circle cx="' . self::n($width * $rx) . '" cy="' . self::n($height * $ry) . '" r="' . self::n($height * $rr) . '" fill="#c4622d" opacity="0.4"/>';
            }
        } else {
            $knots = [];
            $knotCount = $kind === 'plank' && $width > $height * 3 ? 1 + (int) round($random()) : 0;
            for ($k = 0; $k < $knotCount; ++$k) {
                $knots[] = [($random() - 0.5) * $width * 0.7, ($random() - 0.5) * $height * 0.4, 3 + $random() * min(9, $height / 5), 1.6 + $random() * min(4, $height / 9)];
            }
            $count = max(2, (int) round($height / 7));
            for ($line = 0; $line < $count; ++$line) {
                $base = -$height / 2 + ($line + 0.5) * $height / $count + ($random() - 0.5) * 2;
                $wave = 0.6 + $random() * 1.4;
                $phase = $random() * M_PI * 2;
                $points = [];
                for ($step = 0; $step <= 8; ++$step) {
                    $x = -$width / 2 + $step * $width / 8;
                    $y = $base + sin($phase + $step * 0.9) * $wave;
                    foreach ($knots as [$kx, $ky, $krx, $kry]) {
                        $dx = ($x - $kx) / ($krx * 4);
                        $push = exp(-$dx * $dx) * $kry * 2.4;
                        $y += $y < $ky ? -$push : $push;
                    }
                    $points[] = [$x, max(-$height / 2 + 0.8, min($height / 2 - 0.8, $y))];
                }
                $d = 'M' . self::n($points[0][0]) . ' ' . self::n($points[0][1]);
                for ($i = 1; $i < count($points) - 1; ++$i) {
                    $d .= ' Q' . self::n($points[$i][0]) . ' ' . self::n($points[$i][1]) . ' ' . self::n(($points[$i][0] + $points[$i + 1][0]) / 2) . ' ' . self::n(($points[$i][1] + $points[$i + 1][1]) / 2);
                }
                $last = end($points);
                $d .= ' L' . self::n($last[0]) . ' ' . self::n($last[1]);
                $svg .= '<path d="' . $d . '" fill="none" stroke="#7b4f2a" stroke-width="0.7" opacity="' . self::n(0.28 + ($line % 3) * 0.1, 2) . '"/>';
            }
            foreach ($knots as [$kx, $ky, $krx, $kry]) {
                $svg .= '<ellipse cx="' . self::n($kx) . '" cy="' . self::n($ky) . '" rx="' . self::n($krx) . '" ry="' . self::n($kry) . '" fill="#7b4f2a" opacity="0.55"/>';
            }
            if ($kind === 'plywood') {
                $svg .= '<rect x="' . self::n(-$width / 2) . '" y="' . self::n($height / 2 - 5) . '" width="' . self::n($width) . '" height="5" fill="#9c6a3c" opacity="0.5"/>';
            }
        }
        $svg .= '<rect x="' . self::n(-$width / 2) . '" y="' . self::n(-$height / 2) . '" width="' . self::n($width) . '" height="' . self::n($height) . '" fill="none" stroke="#1f2a24" stroke-opacity="0.35"/></g>';

        return $svg;
    }

    /** Growth rings of a log end, as SVG path data (the home page's last section). */
    public static function rings(float $radius, int $count, int $seed): array
    {
        $random = self::seeded($seed);
        $harmonics = [];
        for ($i = 0; $i < 4; ++$i) {
            $harmonics[] = [$random() * 0.035 / ($i + 1), $random() * M_PI * 2, $i + 2];
        }
        $paths = [];
        for ($ring = 1; $ring <= $count; ++$ring) {
            $r = $radius * $ring / $count;
            $d = '';
            for ($step = 0; $step <= 48; ++$step) {
                $angle = $step / 48 * M_PI * 2;
                $wobble = 0.0;
                foreach ($harmonics as [$amplitude, $phase, $order]) {
                    $wobble += $amplitude * sin($order * $angle + $phase + $ring * 0.2);
                }
                $d .= ($step === 0 ? 'M' : 'L') . self::n(cos($angle) * $r * (1 + $wobble)) . ' ' . self::n(sin($angle) * $r * (1 + $wobble)) . ' ';
            }
            $paths[] = $d . 'Z';
        }

        return $paths;
    }

    /** A number for SVG markup: rounded, dot as decimal separator, whatever the locale. */
    public static function n(float $value, int $decimals = 1): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Small seeded generator (mulberry32), the same as the drawings of the app. */
    private static function seeded(int $seed): \Closure
    {
        $state = $seed & 0xFFFFFFFF;

        return static function () use (&$state): float {
            $state = ($state + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = $state;
            $t = self::imul($t ^ ($t >> 15), $t | 1);
            $t ^= ($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    /** 32-bit multiplication, as JavaScript's Math.imul. */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        $low = (($a & 0xFFFF) * $b) & 0xFFFFFFFF;
        $high = (((($a >> 16) & 0xFFFF) * $b) & 0xFFFF) << 16;

        return ($low + $high) & 0xFFFFFFFF;
    }

    /** Wood colour between pale and deep. */
    private static function mixTone(float $tone): string
    {
        $pale = [0xE6, 0xCB, 0xA4];
        $deep = [0x9C, 0x6A, 0x3C];
        $share = max(0, min(1, $tone)) * 0.7;
        $rgb = array_map(static fn (int $p, int $d): int => (int) round($p + ($d - $p) * $share), $pale, $deep);

        return sprintf('#%02x%02x%02x', ...$rgb);
    }
}
