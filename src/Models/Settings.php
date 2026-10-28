<?php

declare(strict_types=1);

namespace RecupBuild\Models;

/**
 * The settings the admin edits on /admin (the free offer, the Pro price, the legal
 * notice): one row per key in a tiny table. Everything not saved falls back to
 * DEFAULTS, so an empty table still answers; only the known keys are ever written.
 */
final class Settings
{
    /** @var array<string, string> what each setting holds when nobody changed it */
    public const DEFAULTS = [
        // Given to every new account at its first sign-in (Users::signedIn).
        'free_build_credits' => '5',
        'free_rebuild_credits' => '5',
        // Shown on /pricing on the Pro card (a display string: "$10", "10 €"…).
        'pro_price' => '$10',
        // The legal notice of /legal: who hosts it, where, and where to write.
        'legal_host' => '',
        'legal_host_url' => '',
        'legal_contact' => '',
    ];

    /** Every setting, defaults first and what was saved after. @return array<string, string> */
    public static function all(\PDO $pdo): array
    {
        $saved = [];
        foreach ($pdo->query('SELECT key, value FROM settings')->fetchAll() as $row) {
            $saved[(string) $row['key']] = (string) $row['value'];
        }

        return $saved + self::DEFAULTS;
    }

    /** One setting; the default when it was never saved. */
    public static function get(\PDO $pdo, string $key): string
    {
        $statement = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return $value === false ? (self::DEFAULTS[$key] ?? '') : (string) $value;
    }

    /** The free offer, as whole numbers to hand out at the next sign-in. @return array{build: int, rebuild: int} */
    public static function freeCredits(\PDO $pdo): array
    {
        return [
            'build' => max(0, (int) self::get($pdo, 'free_build_credits')),
            'rebuild' => max(0, (int) self::get($pdo, 'free_rebuild_credits')),
        ];
    }

    /**
     * Saves the settings the admin posted: unknown keys are dropped, each value is
     * trimmed and cut short (the table is for small strings, never for HTML).
     */
    public static function save(\PDO $pdo, array $values): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (:key, :value)
             ON CONFLICT (key) DO UPDATE SET value = excluded.value'
        );
        foreach ($values as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::DEFAULTS) || !is_scalar($value)) {
                continue;
            }
            $statement->execute(['key' => $key, 'value' => mb_substr(trim((string) $value), 0, 200)]);
        }
    }
}
