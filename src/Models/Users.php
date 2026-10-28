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
     * `$isAdmin` comes from ADMIN_EMAILS (Config): it is recomputed at every sign-in,
     * so removing an address there removes its access at the next one. `$free` is the
     * offer of the moment (Models\Settings), granted once, at the first sign-in only.
     *
     * `first` is true only when the row was just created: the one moment a welcome
     * e-mail is sent (Services\Mailer, from the AuthController).
     *
     * @param array{sub: string, email: ?string, name: string, picture: ?string} $google
     * @param ?array{build: int, rebuild: int} $free
     *
     * @return array{id: int, admin: bool, first: bool, sub: string, email: ?string, name: string, picture: ?string}
     */
    public static function signedIn(\PDO $pdo, array $google, ?string $now = null, bool $isAdmin = false, ?array $free = null): array
    {
        $now ??= gmdate('Y-m-d\TH:i:s\Z');
        $exists = $pdo->prepare('SELECT 1 FROM users WHERE google_sub = :sub');
        $exists->execute(['sub' => $google['sub']]);
        $first = $exists->fetchColumn() === false;

        $statement = $pdo->prepare(
            'INSERT INTO users (google_sub, email, name, picture, created_at, last_login_at, is_admin, build_credits, rebuild_credits)
             VALUES (:sub, :email, :name, :picture, :now, :now, :is_admin, :builds, :rebuilds)
             ON CONFLICT (google_sub) DO UPDATE SET
                email = excluded.email,
                name = excluded.name,
                picture = excluded.picture,
                last_login_at = excluded.last_login_at,
                is_admin = excluded.is_admin'
        );
        $statement->execute([
            'sub' => $google['sub'],
            'email' => $google['email'],
            'name' => $google['name'],
            'picture' => $google['picture'],
            'now' => $now,
            'is_admin' => $isAdmin ? 1 : 0,
            // Only the INSERT branch uses these: signing in again never tops the offer up.
            'builds' => max(0, $free['build'] ?? self::FREE_CREDITS),
            'rebuilds' => max(0, $free['rebuild'] ?? self::FREE_CREDITS),
        ]);

        $select = $pdo->prepare('SELECT id FROM users WHERE google_sub = :sub');
        $select->execute(['sub' => $google['sub']]);

        return ['id' => (int) $select->fetchColumn(), 'admin' => $isAdmin, 'first' => $first] + $google;
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

    /** Whether this account opens the admin pages (ADMIN_EMAILS at the last sign-in). */
    public static function isAdmin(\PDO $pdo, int $id): bool
    {
        $statement = $pdo->prepare('SELECT is_admin FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * Every account, most recent sign-in first, with what is left of the free offer
     * and what they had built: the admin's list.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(\PDO $pdo): array
    {
        return $pdo->query(
            'SELECT u.id, u.email, u.name, u.picture, u.is_admin,
                    u.created_at, u.last_login_at, u.build_credits, u.rebuild_credits,
                    (SELECT COUNT(*) FROM plans p WHERE p.user_id = u.id) AS plans,
                    (SELECT COUNT(*) FROM rebuilds r WHERE r.user_id = u.id) AS rebuilds
             FROM users u
             ORDER BY u.last_login_at DESC, u.id DESC'
        )->fetchAll();
    }

    /**
     * The whole site at a glance: how many people, how many plans and rebuilds,
     * and how many credits are still to be spent.
     *
     * @return array{users: int, plans: int, rebuilds: int, builds_left: int, rebuilds_left: int}
     */
    public static function stats(\PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT (SELECT COUNT(*) FROM users) AS users,
                    (SELECT COUNT(*) FROM plans) AS plans,
                    (SELECT COUNT(*) FROM rebuilds) AS rebuilds,
                    (SELECT COALESCE(SUM(build_credits), 0) FROM users) AS builds_left,
                    (SELECT COALESCE(SUM(rebuild_credits), 0) FROM users) AS rebuilds_left'
        )->fetch();

        return [
            'users' => (int) ($row['users'] ?? 0),
            'plans' => (int) ($row['plans'] ?? 0),
            'rebuilds' => (int) ($row['rebuilds'] ?? 0),
            'builds_left' => (int) ($row['builds_left'] ?? 0),
            'rebuilds_left' => (int) ($row['rebuilds_left'] ?? 0),
        ];
    }

    /** The admin sets what is left of the free offer; false when the account is gone. */
    public static function setCredits(\PDO $pdo, int $id, int $build, int $rebuild): bool
    {
        if (self::find($pdo, $id) === null) {
            return false;
        }
        $statement = $pdo->prepare('UPDATE users SET build_credits = :build, rebuild_credits = :rebuild WHERE id = :id');
        $statement->execute(['build' => $build, 'rebuild' => $rebuild, 'id' => $id]);

        return true;
    }

    /**
     * Who built the most, plans and reverse builds together: the chart of the
     * admin's dashboard. Accounts that built nothing are left out.
     *
     * @return list<array{name: string, plans: int, rebuilds: int}>
     */
    public static function topBuilders(\PDO $pdo, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        $statement = $pdo->query(
            "SELECT name, plans, rebuilds FROM (
                SELECT u.name AS name,
                       (SELECT COUNT(*) FROM plans p WHERE p.user_id = u.id) AS plans,
                       (SELECT COUNT(*) FROM rebuilds r WHERE r.user_id = u.id) AS rebuilds
                FROM users u
             ) WHERE plans + rebuilds > 0
             ORDER BY plans + rebuilds DESC, plans DESC, name
             LIMIT {$limit}"
        );

        return array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'plans' => (int) $row['plans'],
            'rebuilds' => (int) $row['rebuilds'],
        ], $statement->fetchAll());
    }
}
