<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * The history: every plan a signed-in user obtained (POST /api/plan), with what is
 * needed to show it again without the model: the project, the pieces it was cut
 * from and the full answer (cutting plan, steps, 3D layout). JSON columns, read
 * back as they were written.
 */
final class Plans
{
    /** How long a picture being made holds its reservation, in seconds. */
    private const CLAIM_S = 300;

    /** How many plans the history lists at most, newest first. */
    public const LIMIT = 50;

    /**
     * Keeps a plan in the user's history and returns its id. `$photo` names the heap's
     * photo (Models\Photos) and `$outlines` where each piece is on it (id => corners):
     * with them the cutting plan shows the real wood.
     *
     * @param ?array<int, list<array{0: float, 1: float}>> $outlines
     * @param ?array{width: int, height: int} $image size of the photo the outlines refer to
     */
    public static function save(\PDO $pdo, int $userId, array $definition, array $pieces, array $result, ?string $now = null, ?string $photo = null, ?array $outlines = null, ?array $image = null): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO plans (user_id, project_id, project_name, definition, pieces, result, created_at, photo, outlines)
             VALUES (:user, :project, :name, :definition, :pieces, :result, :now, :photo, :outlines)'
        );
        $statement->execute([
            'user' => $userId,
            'project' => $definition['id'],
            'name' => $definition['name'],
            'definition' => self::json($definition),
            'pieces' => self::json($pieces),
            'result' => self::json($result),
            'now' => $now ?? gmdate('Y-m-d\TH:i:s\Z'),
            'photo' => $photo,
            'outlines' => $outlines === null ? null : self::json(['image' => $image, 'pieces' => $outlines]),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * The user's plans, newest first: enough for a list (no plan body), plus what a
     * small three-quarter picture of the finished object needs (Drawing::isometric).
     *
     * @return list<array{id: int, project_id: string, project_name: ?string, params: array<string, int>, labels: array<string, string>, parts: int, created_at: string, assembly: ?list<array<string, mixed>>, sizes: array<int, array{length_mm: float, width_mm: float, thickness_mm: float, metal: bool}>}>
     */
    public static function list(\PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, project_id, project_name, definition, pieces, result, created_at FROM plans
             WHERE user_id = :user ORDER BY id DESC LIMIT ' . self::LIMIT
        );
        $statement->execute(['user' => $userId]);

        $items = [];
        foreach ($statement->fetchAll() as $row) {
            $result = json_decode((string) $row['result'], true);
            $definition = json_decode((string) $row['definition'], true);
            // The words of an invented project's sizes ("Hauteur"...); built-in ones are in the locale files.
            $labels = [];
            foreach (is_array($definition['params'] ?? null) ? $definition['params'] : [] as $key => $spec) {
                if (is_array($spec) && is_string($spec['label'] ?? null)) {
                    $labels[$key] = $spec['label'];
                }
            }
            // The still 3D picture of the list: the assembled boxes and each part's size.
            $kinds = [];
            foreach (is_array($row['pieces'] ?? null) ? (json_decode((string) $row['pieces'], true) ?: []) : [] as $piece) {
                $kinds[(int) ($piece['id'] ?? 0)] = (string) ($piece['kind'] ?? '');
            }
            $sizes = [];
            foreach (is_array($result['plan']['placements'] ?? null) ? $result['plan']['placements'] : [] as $placement) {
                $number = (int) ($placement['number'] ?? 0);
                if ($number <= 0) {
                    continue;
                }
                $sizes[$number] = [
                    'length_mm' => (float) ($placement['length_mm'] ?? 0),
                    'width_mm' => (float) ($placement['width_mm'] ?? 0),
                    'thickness_mm' => (float) ($placement['thickness_mm'] ?? 0),
                    'metal' => ($kinds[(int) ($placement['piece_id'] ?? -1)] ?? '') === 'sheet_metal',
                ];
            }
            $items[] = [
                'id' => (int) $row['id'],
                'project_id' => $row['project_id'],
                'project_name' => $row['project_name'],
                'params' => is_array($result['plan']['params'] ?? null) ? $result['plan']['params'] : [],
                'labels' => (object) $labels,
                'parts' => is_array($result['plan']['placements'] ?? null) ? count($result['plan']['placements']) : 0,
                'created_at' => $row['created_at'],
                'assembly' => is_array($result['assembly'] ?? null) && $result['assembly'] !== [] ? $result['assembly'] : null,
                'sizes' => $sizes,
            ];
        }

        return $items;
    }

    /**
     * One plan of this user, whole; null when it does not exist or is someone else's.
     *
     * @return ?array{id: int, created_at: string, definition: array<string, mixed>, pieces: list<array<string, mixed>>, result: array<string, mixed>}
     */
    public static function find(\PDO $pdo, int $userId, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT id, definition, pieces, result, created_at, photo, outlines, preview FROM plans WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'created_at' => $row['created_at'],
            'definition' => (array) json_decode((string) $row['definition'], true),
            'pieces' => (array) json_decode((string) $row['pieces'], true),
            'result' => (array) json_decode((string) $row['result'], true),
            'photo' => $row['photo'],
            'outlines' => $row['outlines'] === null ? null : json_decode((string) $row['outlines'], true),
            'preview' => $row['preview'],
        ];
    }

    /**
     * Reserves the picture of a plan, so two clicks never pay for two images. False when it
     * exists or another request is making it; a reservation left by a dead request expires.
     */
    public static function claimPreview(\PDO $pdo, int $userId, int $id, ?int $now = null): bool
    {
        $now ??= time();
        $statement = $pdo->prepare(
            "UPDATE plans SET preview = :claim WHERE id = :id AND user_id = :user
             AND (preview IS NULL OR (preview LIKE 'pending:%' AND CAST(substr(preview, 9) AS INTEGER) < :stale))"
        );
        $statement->execute(['claim' => 'pending:' . $now, 'stale' => $now - self::CLAIM_S, 'id' => $id, 'user' => $userId]);

        return $statement->rowCount() > 0;
    }

    /** Gives the reservation back: the picture failed, the user may try again. */
    public static function releasePreview(\PDO $pdo, int $userId, int $id): void
    {
        $statement = $pdo->prepare("UPDATE plans SET preview = NULL WHERE id = :id AND user_id = :user AND preview LIKE 'pending:%'");
        $statement->execute(['id' => $id, 'user' => $userId]);
    }

    /** Keeps the realistic picture of one plan of this user (a name from Models\Photos). */
    public static function savePreview(\PDO $pdo, int $userId, int $id, string $preview): void
    {
        $statement = $pdo->prepare('UPDATE plans SET preview = :preview WHERE id = :id AND user_id = :user');
        $statement->execute(['preview' => $preview, 'id' => $id, 'user' => $userId]);
    }

    /**
     * Removes one plan of this user and the picture generated for it; false when there
     * was none. The photo stays: other plans of the same scan may be cut from it.
     */
    public static function delete(\PDO $pdo, int $userId, int $id): bool
    {
        $preview = $pdo->prepare('SELECT preview FROM plans WHERE id = :id AND user_id = :user');
        $preview->execute(['id' => $id, 'user' => $userId]);
        $picture = $preview->fetchColumn();

        $statement = $pdo->prepare('DELETE FROM plans WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);
        if ($statement->rowCount() === 0) {
            return false;
        }
        Photos::remove(is_string($picture) ? $picture : null);

        return true;
    }

    /**
     * The newest plans of the whole site, for the admin's dashboard: who asked for
     * which project, and when.
     *
     * @return list<array{id: int, user_id: int, user_name: string, project_id: string, project_name: ?string, created_at: string}>
     */
    public static function latest(\PDO $pdo, int $limit = 8): array
    {
        $limit = max(1, min(50, $limit));
        $statement = $pdo->query(
            "SELECT p.id, p.user_id, u.name AS user_name, p.project_id, p.project_name, p.created_at
             FROM plans p JOIN users u ON u.id = p.user_id
             ORDER BY p.id DESC LIMIT {$limit}"
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'user_name' => (string) $row['user_name'],
            'project_id' => (string) $row['project_id'],
            'project_name' => $row['project_name'] === null ? null : (string) $row['project_name'],
            'created_at' => (string) $row['created_at'],
        ], $statement->fetchAll());
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
