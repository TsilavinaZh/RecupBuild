<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * A cap on what the credits do not count: a scan calls the models and is free, so without
 * it one account could spend the whole API budget. Over a rolling hour, per user.
 */
final class Usage
{
    public const SCANS_PER_HOUR = 20;

    private const WINDOW_S = 3600;

    /** Counts one use and says whether it is allowed; a refused use is not counted. */
    public static function allow(\PDO $pdo, int $userId, string $kind, int $limit, ?int $now = null): bool
    {
        $now ??= time();
        $forget = $pdo->prepare('DELETE FROM usage_events WHERE user_id = :user AND kind = :kind AND created_at <= :before');
        $forget->execute(['user' => $userId, 'kind' => $kind, 'before' => $now - self::WINDOW_S]);

        $count = $pdo->prepare('SELECT COUNT(*) FROM usage_events WHERE user_id = :user AND kind = :kind');
        $count->execute(['user' => $userId, 'kind' => $kind]);
        if ((int) $count->fetchColumn() >= $limit) {
            return false;
        }

        $add = $pdo->prepare('INSERT INTO usage_events (user_id, kind, created_at) VALUES (:user, :kind, :now)');
        $add->execute(['user' => $userId, 'kind' => $kind, 'now' => $now]);

        return true;
    }
}
