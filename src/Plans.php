<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * The history: every plan a signed-in user obtained (POST /api/plan), with what is
 * needed to show it again without the model: the project, the pieces it was cut
 * from and the full answer (cutting plan, steps, 3D layout). JSON columns, read
 * back as they were written.
 */
final class Plans
{
    /** How many plans the history lists at most, newest first. */
    public const LIMIT = 50;

    /** Keeps a plan in the user's history and returns its id. */
    public static function save(\PDO $pdo, int $userId, array $definition, array $pieces, array $result, ?string $now = null): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO plans (user_id, project_id, project_name, definition, pieces, result, created_at)
             VALUES (:user, :project, :name, :definition, :pieces, :result, :now)'
        );
        $statement->execute([
            'user' => $userId,
            'project' => $definition['id'],
            'name' => $definition['name'],
            'definition' => self::json($definition),
            'pieces' => self::json($pieces),
            'result' => self::json($result),
            'now' => $now ?? gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * The user's plans, newest first: enough for a list (no plan body).
     *
     * @return list<array{id: int, project_id: string, project_name: ?string, params: array<string, int>, labels: array<string, string>, parts: int, created_at: string}>
     */
    public static function list(\PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, project_id, project_name, definition, result, created_at FROM plans
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
            $items[] = [
                'id' => (int) $row['id'],
                'project_id' => $row['project_id'],
                'project_name' => $row['project_name'],
                'params' => is_array($result['plan']['params'] ?? null) ? $result['plan']['params'] : [],
                'labels' => (object) $labels,
                'parts' => is_array($result['plan']['placements'] ?? null) ? count($result['plan']['placements']) : 0,
                'created_at' => $row['created_at'],
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
        $statement = $pdo->prepare('SELECT id, definition, pieces, result, created_at FROM plans WHERE id = :id AND user_id = :user');
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
        ];
    }

    /** Removes one plan of this user; false when there was none. */
    public static function delete(\PDO $pdo, int $userId, int $id): bool
    {
        $statement = $pdo->prepare('DELETE FROM plans WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);

        return $statement->rowCount() > 0;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
