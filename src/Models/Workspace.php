<?php

declare(strict_types=1);

namespace RecupBuild\Models;

use RecupBuild\Core\Session;

/**
 * Where the user is in the app, kept in their session: the scanned photo and its
 * pieces (the inventory, as they corrected it), whether they confirmed it, and the
 * project cards the model invented for it (so going back costs nothing).
 *
 *   scan -> inventory -> projects -> plan (saved in the history, Models\Plans)
 */
final class Workspace
{
    /** Plausible bounds for reclaimed pieces, in mm: outside them, it is most likely a typo. */
    public const LIMITS = [
        'length_mm' => [10, 6000],
        'width_mm' => [10, 3000],
        'thickness_mm' => [0.3, 200],
    ];

    public const KINDS = ['plank', 'batten', 'plywood', 'sheet_metal', 'pallet_board', 'other'];

    /** @return ?array{demo: bool, photo: string, image: array{width: int, height: int}, pieces: list<array<string, mixed>>, confirmed: bool, cards: ?array} */
    public static function get(): ?array
    {
        Session::start();
        $workspace = $_SESSION['workspace'] ?? null;

        return is_array($workspace) ? $workspace : null;
    }

    /** A new scan: everything after it (confirmation, cards) is forgotten. */
    public static function start(string $photo, array $image, array $pieces, bool $demo): void
    {
        Session::start();
        $_SESSION['workspace'] = [
            'demo' => $demo,
            'photo' => $photo,
            'image' => ['width' => (int) $image['width'], 'height' => (int) $image['height']],
            'pieces' => array_values($pieces),
            'confirmed' => false,
            'cards' => null,
        ];
    }

    /**
     * The inventory as the user corrected it; the cards are recomputed after any change.
     *
     * @param list<array<string, mixed>> $pieces
     */
    public static function setPieces(array $pieces, bool $confirmed): void
    {
        Session::start();
        if (!isset($_SESSION['workspace'])) {
            return;
        }
        if ($pieces !== $_SESSION['workspace']['pieces']) {
            $_SESSION['workspace']['cards'] = null;
        }
        $_SESSION['workspace']['pieces'] = array_values($pieces);
        $_SESSION['workspace']['confirmed'] = $confirmed;
    }

    public static function setCards(array $cards): void
    {
        Session::start();
        if (isset($_SESSION['workspace'])) {
            $_SESSION['workspace']['cards'] = $cards;
        }
    }

    public static function clear(): void
    {
        Session::start();
        unset($_SESSION['workspace']);
    }

    /**
     * The problems of the inventory, per piece and field ("missing", "too_small",
     * "too_large"). Empty: the table can be used.
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return array<int, array<string, string>>
     */
    public static function issues(array $pieces): array
    {
        $issues = [];
        foreach ($pieces as $piece) {
            foreach (self::LIMITS as $field => [$min, $max]) {
                $value = $piece[$field] ?? null;
                $problem = match (true) {
                    !is_int($value) && !is_float($value) => 'missing',
                    $value < $min => 'too_small',
                    $value > $max => 'too_large',
                    default => null,
                };
                if ($problem !== null) {
                    $issues[(int) $piece['id']][$field] = $problem;
                }
            }
        }

        return $issues;
    }

    /**
     * The table posted by the inventory form, read over the pieces of the scan (only
     * their ids, kinds and figures; the outlines stay those of the scan).
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return list<array<string, mixed>>
     */
    public static function fromForm(array $pieces, mixed $form): array
    {
        $form = is_array($form) ? $form : [];
        $result = [];
        foreach ($pieces as $piece) {
            $row = $form[$piece['id']] ?? null;
            if (!is_array($row)) {
                $result[] = $piece;
                continue;
            }
            if (!empty($row['remove'])) {
                continue;
            }
            $kind = in_array($row['kind'] ?? null, self::KINDS, true) ? $row['kind'] : $piece['kind'];
            $updated = ['kind' => $kind] + $piece;
            foreach (array_keys(self::LIMITS) as $field) {
                $value = str_replace(',', '.', trim((string) ($row[$field] ?? '')));
                $updated[$field] = is_numeric($value) ? round((float) $value, 1) : null;
            }
            $result[] = $updated;
        }

        return $result;
    }

    /**
     * The pieces in the wire format of the planner (no outlines).
     *
     * @param list<array<string, mixed>> $pieces
     *
     * @return list<array{id: int, kind: string, length_mm: ?float, width_mm: ?float, thickness_mm: ?float}>
     */
    public static function wire(array $pieces): array
    {
        return array_map(static fn (array $piece): array => [
            'id' => (int) $piece['id'],
            'kind' => (string) $piece['kind'],
            'length_mm' => $piece['length_mm'] === null ? null : (float) $piece['length_mm'],
            'width_mm' => $piece['width_mm'] === null ? null : (float) $piece['width_mm'],
            'thickness_mm' => $piece['thickness_mm'] === null ? null : (float) $piece['thickness_mm'],
        ], $pieces);
    }
}
