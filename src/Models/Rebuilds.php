<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * The reverse builds of a user (Services\Reverse): the photo of the object and what it
 * takes to make it, kept so the page can be opened again without paying the model.
 */
final class Rebuilds
{
    public static function save(\PDO $pdo, int $userId, array $result, ?string $photo, ?string $now = null): int
    {
        $statement = $pdo->prepare('INSERT INTO rebuilds (user_id, name, photo, result, created_at) VALUES (:user, :name, :photo, :result, :now)');
        $statement->execute([
            'user' => $userId,
            'name' => (string) ($result['name'] ?? ''),
            'photo' => $photo,
            'result' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'now' => $now ?? gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return list<array{id: int, name: string, photo: ?string, created_at: string}> */
    public static function list(\PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare('SELECT id, name, photo, created_at FROM rebuilds WHERE user_id = :user ORDER BY id DESC LIMIT 50');
        $statement->execute(['user' => $userId]);

        return array_map(static fn (array $row): array => ['id' => (int) $row['id']] + $row, $statement->fetchAll());
    }

    /** @return ?array{id: int, name: string, photo: ?string, result: array<string, mixed>, created_at: string} */
    public static function find(\PDO $pdo, int $userId, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT id, name, photo, result, created_at FROM rebuilds WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'result' => (array) json_decode((string) $row['result'], true)] + $row;
    }

    public static function delete(\PDO $pdo, int $userId, int $id): bool
    {
        $statement = $pdo->prepare('DELETE FROM rebuilds WHERE id = :id AND user_id = :user');
        $statement->execute(['id' => $id, 'user' => $userId]);

        return $statement->rowCount() > 0;
    }
}
