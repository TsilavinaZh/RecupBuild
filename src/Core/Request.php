<?php

declare(strict_types=1);

namespace RecupBuild\Core;

use RecupBuild\Models\Projects;

/**
 * Reads and validates what the frontend sends: the JSON body of the planner
 * endpoints, the inventory it is built from and the language it asks in.
 */
final class Request
{
    /**
     * The decoded JSON body, or an empty array for an empty body.
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            Http::fail('invalid_json', 422);
        }

        return $body;
    }

    /**
     * The inventory as the solver wants it: known keys only, so nothing from a
     * request can reach the model except millimetre figures and material kinds.
     *
     * @return list<array{id: int, kind: string, length_mm: ?float, width_mm: ?float, thickness_mm: ?float}>
     */
    public static function pieces(array $body): array
    {
        if (!isset($body['pieces']) || !is_array($body['pieces'])) {
            return [];
        }
        $pieces = [];
        foreach ($body['pieces'] as $item) {
            if (!is_array($item) || !isset($item['id']) || !is_numeric($item['id'])) {
                continue;
            }
            $pieces[] = [
                'id' => (int) $item['id'],
                'kind' => is_string($item['kind'] ?? null) ? $item['kind'] : 'other',
                'length_mm' => self::dimension($item['length_mm'] ?? null),
                'width_mm' => self::dimension($item['width_mm'] ?? null),
                'thickness_mm' => self::dimension($item['thickness_mm'] ?? null),
            ];
        }

        return $pieces;
    }

    /**
     * The chosen project: a built-in id, or the definition of a project the model
     * invented (sent back as the card received it, so checked again here).
     *
     * @return array<string, mixed>
     */
    public static function project(array $body): array
    {
        $project = $body['project'] ?? null;
        if (is_string($project) && in_array($project, Projects::IDS, true)) {
            return Projects::definition($project);
        }

        $definition = Projects::sanitize($body['definition'] ?? null);
        if ($definition === null || (is_string($project) && $project !== $definition['id'])) {
            Http::fail('invalid_json', 422);
        }

        return $definition;
    }

    /** The language the app sent in Accept-Language (the locale files choose fr, en or mg). */
    public static function locale(): string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $first = strtolower(trim(explode(',', $header)[0]));
        $tag = trim(explode('-', $first)[0]);

        return in_array($tag, ['fr', 'en', 'mg'], true) ? $tag : 'fr';
    }

    private static function dimension(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}