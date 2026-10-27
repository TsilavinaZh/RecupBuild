<?php

declare(strict_types=1);

namespace RecupBuild;

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

    /** @return ?array<string, mixed> */
    public static function find(\PDO $pdo, int $id): ?array
    {
        $statement = $pdo->prepare('SELECT id, google_sub, email, name, picture, created_at, last_login_at FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }
}
