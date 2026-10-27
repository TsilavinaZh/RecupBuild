<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * The reverse build: a photo of a finished object (a table, a stool, a shelf...)
 * and the model says how to make the same one: the parts to cut with their sizes,
 * the hardware, the tools, the steps, and where each part sits for the 3D view.
 *
 * Everything the model writes is bounded here before it reaches the browser: short
 * texts, sizes in millimetres within reason, a few dozen parts at most.
 */
final class Reverse
{
    private const MAX_PARTS = 12;
    private const MAX_CUTS = 40;
    private const MAX_MM = 3000;

    /**
     * @return array<string, mixed> the reverse build, as the frontend reads it
     *
     * @throws ScanError empty_segmentation when the photo shows nothing that can be built
     * @throws VisionError
     */
    public static function analyse(string $jpeg, string $locale, Config $config): array
    {
        $answer = Vision::look($jpeg, self::prompt($locale), self::schema(), 'ReverseBuild', $config);

        return self::build($answer);
    }

    /**
     * The model's answer, checked and shaped: one placement per cut copy, numbered,
     * and its 3D spot when the model gave a sensible one.
     *
     * @throws ScanError
     */
    public static function build(array $answer): array
    {
        if (($answer['buildable'] ?? false) !== true || !is_array($answer['parts'] ?? null)) {
            throw new ScanError('empty_segmentation', 422);
        }

        $placements = [];
        $assembly = [];
        $kinds = 0;
        foreach ($answer['parts'] as $part) {
            if (!is_array($part) || ++$kinds > self::MAX_PARTS) {
                continue;
            }
            $label = self::text($part['label'] ?? null, 60) ?? '';
            $sizes = [];
            foreach (['length_mm', 'width_mm', 'thickness_mm'] as $field) {
                $value = is_numeric($part[$field] ?? null) ? (float) $part[$field] : 0.0;
                $sizes[] = round(min(self::MAX_MM, max($field === 'thickness_mm' ? 0.5 : 5, $value)), 1);
            }
            [$length, $width, $thickness] = $sizes;
            $quantity = (int) min(12, max(1, is_numeric($part['quantity'] ?? null) ? (int) $part['quantity'] : 1));
            $material = in_array($part['material'] ?? null, ['wood', 'metal', 'other'], true) ? $part['material'] : 'wood';
            $spots = is_array($part['layout'] ?? null) ? array_values($part['layout']) : [];

            for ($copy = 0; $copy < $quantity && count($placements) < self::MAX_CUTS; ++$copy) {
                $number = count($placements) + 1;
                $placements[] = [
                    'number' => $number,
                    'part' => 'p' . $kinds,
                    'label' => $label,
                    'material' => $material,
                    'length_mm' => max($length, $width),
                    'width_mm' => min($length, $width),
                    'thickness_mm' => $thickness,
                ];
                $spot = self::spot($spots[$copy] ?? null);
                if ($spot !== null) {
                    $assembly[] = ['number' => $number] + $spot;
                }
            }
        }
        if ($placements === []) {
            throw new ScanError('empty_segmentation', 422);
        }

        $dimensions = [];
        foreach (['width_mm', 'depth_mm', 'height_mm'] as $field) {
            $value = $answer['dimensions'][$field] ?? null;
            $dimensions[$field] = is_numeric($value) ? (int) round(min(self::MAX_MM * 2, max(0, (float) $value))) : null;
        }

        return [
            'name' => self::text($answer['name'] ?? null, 80) ?? '',
            'description' => self::text($answer['description'] ?? null, 300) ?? '',
            'dimensions' => $dimensions,
            'difficulty' => in_array($answer['difficulty'] ?? null, ['easy', 'medium', 'hard'], true) ? $answer['difficulty'] : 'medium',
            'hours' => is_numeric($answer['hours'] ?? null) ? round(min(200, max(0.5, (float) $answer['hours'])), 1) : null,
            'placements' => $placements,
            // The 3D view needs most of the parts placed, or it would show a ruin.
            'assembly' => count($assembly) * 2 >= count($placements) ? $assembly : null,
            'hardware' => self::items($answer['hardware'] ?? null, 'name', 'quantity', 15),
            'tools' => self::items($answer['tools'] ?? null, 'name', 'why', 15),
            'steps' => self::texts($answer['steps'] ?? null, 300, 12),
            'tips' => self::texts($answer['tips'] ?? null, 200, 5),
        ];
    }

    private static function prompt(string $locale): string
    {
        $language = match ($locale) {
            'en' => 'English',
            'mg' => 'Malagasy',
            default => 'French',
        };

        return 'You are a carpenter. The photo shows a finished object someone wants to build again (furniture, a box, '
            . 'a shelf, a planter, a stool...). Work out how to make the same object at the same size from wood '
            . '(and sheet metal only where the object clearly has metal). If the photo shows nothing that can be '
            . 'built this way (a person, an animal, food, a landscape, a screen...), answer buildable=false with '
            . 'empty lists. Otherwise answer buildable=true and give: its name, one sentence of description, its '
            . 'overall dimensions in millimetres (estimate them from usual sizes: a dining table is about 750 mm '
            . 'high, a chair seat about 450 mm), the difficulty, the hours of work for one person, every distinct '
            . 'part to cut (label, material, quantity, length, width and thickness in millimetres; legs, rails, '
            . 'slats, top, shelves, sides, back...), the hardware (screws, bolts, hinges, glue... with quantities), '
            . 'the tools needed (each with why it is needed), 5 to 10 fabrication steps in order (one complete '
            . 'sentence each, no numbering) and up to 3 tips. '
            . 'Each part also gives its layout: one entry per copy (quantity entries), where that copy sits in the '
            . 'finished object, so it can be drawn in 3D. Axes in millimetres: x to the right, y up, z towards the '
            . 'viewer, the object standing on y = 0; each copy is a box, give the corner with the smallest x, y and '
            . 'z, the axis its length lies along and the axis its width lies along (its thickness takes the third '
            . 'axis). Parts touch where they are fixed together and never overlap. '
            . 'Write every text in ' . $language . '. Answer only with the JSON asked.';
    }

    private static function schema(): array
    {
        $object = static fn (array $properties): array => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
        $axis = ['type' => 'string', 'enum' => ['x', 'y', 'z']];
        $number = ['type' => 'number'];
        $string = ['type' => 'string'];

        return $object([
            'buildable' => ['type' => 'boolean'],
            'name' => $string,
            'description' => $string,
            'dimensions' => $object(['width_mm' => $number, 'depth_mm' => $number, 'height_mm' => $number]),
            'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
            'hours' => $number,
            'parts' => ['type' => 'array', 'items' => $object([
                'label' => $string,
                'material' => ['type' => 'string', 'enum' => ['wood', 'metal', 'other']],
                'quantity' => ['type' => 'integer'],
                'length_mm' => $number,
                'width_mm' => $number,
                'thickness_mm' => $number,
                'layout' => ['type' => 'array', 'items' => $object(['x' => $number, 'y' => $number, 'z' => $number, 'length_axis' => $axis, 'width_axis' => $axis])],
            ])],
            'hardware' => ['type' => 'array', 'items' => $object(['name' => $string, 'quantity' => $string])],
            'tools' => ['type' => 'array', 'items' => $object(['name' => $string, 'why' => $string])],
            'steps' => ['type' => 'array', 'items' => $string],
            'tips' => ['type' => 'array', 'items' => $string],
        ]);
    }

    /** @return ?array{x: float, y: float, z: float, length_axis: string, width_axis: string} */
    private static function spot(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $lengthAxis = $raw['length_axis'] ?? null;
        $widthAxis = $raw['width_axis'] ?? null;
        if (!in_array($lengthAxis, ['x', 'y', 'z'], true) || !in_array($widthAxis, ['x', 'y', 'z'], true) || $lengthAxis === $widthAxis) {
            return null;
        }
        $spot = [];
        foreach (['x', 'y', 'z'] as $axis) {
            if (!is_numeric($raw[$axis] ?? null) || abs((float) $raw[$axis]) > self::MAX_MM * 2) {
                return null;
            }
            $spot[$axis] = round((float) $raw[$axis], 1);
        }

        return $spot + ['length_axis' => $lengthAxis, 'width_axis' => $widthAxis];
    }

    /** @return list<array<string, string>> */
    private static function items(mixed $raw, string $first, string $second, int $max): array
    {
        $items = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            $name = is_array($item) ? self::text($item[$first] ?? null, 80) : null;
            if ($name !== null && count($items) < $max) {
                $items[] = [$first => $name, $second => self::text($item[$second] ?? null, 160) ?? ''];
            }
        }

        return $items;
    }

    /** @return list<string> */
    private static function texts(mixed $raw, int $length, int $max): array
    {
        $texts = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            $text = self::text($item, $length);
            if ($text !== null && count($texts) < $max) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return $text === '' ? null : (function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max));
    }
}
