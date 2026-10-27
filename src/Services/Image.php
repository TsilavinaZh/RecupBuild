<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Core\Config;
use RecupBuild\Errors\ScanError;

/**
 * The upload side of the scan (port of app/scan.py::prepare_image):
 * decode the upload, apply the EXIF rotation, downsize it, re-encode it as JPEG.
 */
final class Image
{
    /**
     * @return array{0: string, 1: int, 2: int} the JPEG bytes, its width and its height
     *
     * @throws ScanError unsupported_type when the bytes are not an image
     */
    public static function prepare(string $data, Config $config): array
    {
        if (@getimagesizefromstring($data) === false) {
            throw new ScanError('unsupported_type', 415);
        }
        $image = @imagecreatefromstring($data);
        if ($image instanceof \GdImage === false) {
            throw new ScanError('unsupported_type', 415);
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        $image = self::applyOrientation($image, self::orientation($data));

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest > $config::MAX_EDGE_PX) {
            $ratio = $config::MAX_EDGE_PX / $longest;
            $newWidth = max(1, (int) round($width * $ratio));
            $newHeight = max(1, (int) round($height * $ratio));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $image = $resized;
        }

        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = ob_get_clean();

        return [$jpeg === false ? '' : $jpeg, imagesx($image), imagesy($image)];
    }

    /** The EXIF orientation of the photo (1 when there is none), applied like Pillow's exif_transpose. */
    private static function orientation(string $data): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $temporary = tempnam(sys_get_temp_dir(), 'recupbuild-');
        if ($temporary === false) {
            return 1;
        }
        file_put_contents($temporary, $data);
        $exif = @exif_read_data($temporary, 'IFD0', true);
        unlink($temporary);
        if (!is_array($exif)) {
            return 1;
        }

        $value = $exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1;

        return is_numeric($value) ? (int) $value : 1;
    }

    private static function applyOrientation(\GdImage $image, int $orientation): \GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = self::rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $image = self::rotate($image, 90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 6:
                $image = self::rotate($image, 90);
                break;
            case 7:
                $image = self::rotate($image, -90);
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 8:
                $image = self::rotate($image, -90);
                break;
        }

        return $image;
    }

    /** Rotates, degrees clockwise (GD takes counter-clockwise angles). */
    private static function rotate(\GdImage $image, int $degrees): \GdImage
    {
        $rotated = imagerotate($image, -$degrees, 0);

        return $rotated instanceof \GdImage ? $rotated : $image;
    }
}