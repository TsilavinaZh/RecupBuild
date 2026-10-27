<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * The users table: one row per Google account, created at its first sign-in and
 * refreshed (name, e-mail, picture, last sign-in) at every one after.
 */
final class Users
{
    /**
     * Records a Google sign-in and returns the user as the session keeps it.
     *
     * @param array{sub: string, email: ?string, name: string, picture: ?string} $google
     *
     * @return array{id: int, sub: string, email: ?string, name: string, picture: ?string}
     */
    public static function signedIn(\PDO $pdo, array $google, ?string $now = null): array
    {
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        $statement = $pdo->prepare(
            'INSERT INTO users (google_sub, email, name, picture, created_at, last_login_at)
             VALUES (:sub, :email, :name, :picture, :now, :now)
             ON CONFLICT (google_sub) DO UPDATE SET
                email = excluded.email,
                name = excluded.name,
                picture = excluded.picture,
                last_login_at = excluded.last_login_at'
        );
        $statement->execute([
            'sub' => $google['sub'],
            'email' => $google['email'],
            'name' => $google['name'],
            'picture' => $google['picture'],
            'now' => $now,
        ]);

        $select = $pdo->prepare('SELECT id FROM users WHERE google_sub = :sub');
        $select->execute(['sub' => $google['sub']]);

        return ['id' => (int) $select->fetchColumn()] + $google;
    }

    /** What the free offer gives each new account, of each kind. */
    public const FREE_CREDITS = 5;

    /** @return ?array<string, mixed> */
    public static function find(\PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT id, google_sub, email, name, picture, created_at, last_login_at, build_credits, rebuild_credits FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * What is left of the free offer: builds (cutting plans) and rebuilds (reverse builds).
     *
     * @return array{build: int, rebuild: int}
     */
    public static function credits(\PDO $pdo, int $id): array
    {
        $statement = $pdo->prepare('SELECT build_credits, rebuild_credits FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return ['build' => (int) ($row['build_credits'] ?? 0), 'rebuild' => (int) ($row['rebuild_credits'] ?? 0)];
    }

    /**
     * Uses one credit of a kind ("build" or "rebuild"); false when none is left. One
     * statement, so two requests at once can never spend the same credit twice.
     */
    public static function spend(\PDO $pdo, int $id, string $kind): bool
    {
        $column = match ($kind) {
            'build' => 'build_credits',
            'rebuild' => 'rebuild_credits',
        };
        $statement = $pdo->prepare("UPDATE users SET {$column} = {$column} - 1 WHERE id = :id AND {$column} > 0");
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
