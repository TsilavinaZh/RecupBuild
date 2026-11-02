<?php

declare(strict_types=1);

namespace RecupBuild\Services;

use RecupBuild\Core\Config;
use RecupBuild\Errors\VisionError;

/**
 * Muse Image (Meta Model API) pictures the finished object: the photo of the heap goes in
 * as the reference, so the object comes out in the user's own wood. It is an illustration
 * of the plan, never a measurement: the sizes and the cuts stay those of the engine.
 *
 * Docs: https://dev.meta.ai/docs/image-generation.
 */
final class Preview
{
    public const MODEL = 'muse-image-1.0';

    /** A landscape picture; the model takes the ratio and chooses the pixels. */
    private const SIZE = '1536x1024';

    private const MAX_PARTS = 10;

    /**
     * A JPEG of the finished object, ready to store.
     *
     * @param array<string, mixed> $plan as Models\Plans::find returns it
     *
     * @throws VisionError timeout / server / invalid_json
     * @throws \RecupBuild\Errors\ScanError the model did not answer with an image
     */
    public static function of(string $photo, array $plan, Config $config): string
    {
        $answer = Vision::post('/images/edits', [
            'model' => self::MODEL,
            'prompt' => self::prompt($plan),
            'images' => [['image_url' => 'data:image/jpeg;base64,' . base64_encode($photo)]],
            'n' => 1,
            'size' => self::SIZE,
            'output_format' => 'jpeg',
            'response_format' => 'b64_json',
        ], $config);

        $encoded = $answer['data'][0]['b64_json'] ?? null;
        $image = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($image === false || $image === '') {
            throw new VisionError('invalid_json', 'the image model answered without an image');
        }

        return Image::prepare($image, $config)[0];
    }

    /**
     * What to draw: the object, its sizes and its parts, in the wood of the reference.
     *
     * @param array<string, mixed> $plan
     */
    public static function prompt(array $plan): string
    {
        $definition = is_array($plan['definition'] ?? null) ? $plan['definition'] : [];
        $cut = is_array($plan['result']['plan'] ?? null) ? $plan['result']['plan'] : [];
        $name = is_string($definition['name'] ?? null) ? $definition['name'] : (string) ($definition['id'] ?? 'object');

        $sizes = [];
        foreach (is_array($cut['params'] ?? null) ? $cut['params'] : [] as $key => $value) {
            if (is_numeric($value)) {
                $sizes[] = $key . ' ' . round((float) $value) . ' mm';
            }
        }

        $labels = [];
        foreach (is_array($definition['parts'] ?? null) ? $definition['parts'] : [] as $spec) {
            if (is_array($spec) && isset($spec['name'], $spec['label'])) {
                $labels[$spec['name']] = $spec['label'];
            }
        }
        $parts = [];
        foreach (is_array($cut['placements'] ?? null) ? $cut['placements'] : [] as $placement) {
            if (!is_array($placement) || !isset($placement['part'], $placement['length_mm'], $placement['width_mm'])) {
                continue;
            }
            $part = sprintf('%s %d x %d mm', $labels[$placement['part']] ?? $placement['part'], $placement['length_mm'], $placement['width_mm']);
            $parts[$part] = ($parts[$part] ?? 0) + 1;
        }
        $list = [];
        foreach (array_slice($parts, 0, self::MAX_PARTS, true) as $part => $count) {
            $list[] = $count . ' x ' . $part;
        }

        return 'Photorealistic photo of a finished ' . $name . '. '
            . (is_string($definition['description'] ?? null) ? $definition['description'] . ' ' : '')
            . ($sizes === [] ? '' : 'Overall size: ' . implode(', ', $sizes) . '. ')
            . ($list === [] ? '' : 'Made of these parts: ' . implode('; ', $list) . '. ')
            . 'It is built only from the reclaimed materials in the reference photo: keep their real colours, '
            . 'grain, knots, rust and weathering. Solidly assembled, in a tidy workshop, soft natural light, '
            . 'three-quarter view. The sheet of paper is not part of the object. No people, no text.';
    }
}
