<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * The SQLite database (PDO, no ORM): one file in backend-php/storage/, outside
 * public/, so the web server never serves it.
 *
 * The schema is created and upgraded on first use: each entry of MIGRATIONS runs
 * once, in order, and SQLite's user_version remembers how many already ran.
 * Add a new entry at the end to change the schema; never edit one that shipped.
 */
final class Database
{
    /** @var list<string> */
    private const MIGRATIONS = [
        // 1: the people who signed in with Google.
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            google_sub TEXT NOT NULL UNIQUE,
            email TEXT,
            name TEXT NOT NULL,
            picture TEXT,
            created_at TEXT NOT NULL,
            last_login_at TEXT NOT NULL
        )',
        // 2: the history, every plan a user obtained.
        'CREATE TABLE plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            project_id TEXT NOT NULL,
            project_name TEXT,
            definition TEXT NOT NULL,
            pieces TEXT NOT NULL,
            result TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX plans_by_user ON plans (user_id, id DESC)',
        // 3: the free offer, 5 builds (plans) and 5 rebuilds (reverse builds) per account.
        'ALTER TABLE users ADD COLUMN build_credits INTEGER NOT NULL DEFAULT 5;
        ALTER TABLE users ADD COLUMN rebuild_credits INTEGER NOT NULL DEFAULT 5',
        // 4: the photo of the heap kept with each plan (the real wood on the cutting plan),
        // and the history of reverse builds.
        'ALTER TABLE plans ADD COLUMN photo TEXT;
        ALTER TABLE plans ADD COLUMN outlines TEXT;
        CREATE TABLE rebuilds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            photo TEXT,
            result TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX rebuilds_by_user ON rebuilds (user_id, id DESC)',
    ];

    private static ?\PDO $shared = null;

    /** The application's database, opened (and brought up to date) once per request. */
    public static function get(Config $config): \PDO
    {
        return self::$shared ??= self::open($config->databasePath);
    }

    /** Opens a database file (or ':memory:' for the tests) with the schema up to date. */
    public static function open(string $path): \PDO
    {
        if ($path !== ':memory:') {
            $folder = dirname($path);
            if (!is_dir($folder) && !mkdir($folder, 0770, true) && !is_dir($folder)) {
                throw new \RuntimeException('cannot create the database folder ' . $folder);
            }
        }

        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Two requests at once (the planner is slow) wait for each other instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 5000');
        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }
        self::migrate($pdo);

        return $pdo;
    }

    private static function migrate(\PDO $pdo): void
    {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (array_slice(self::MIGRATIONS, $version, null, true) as $index => $sql) {
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                // PRAGMA takes no bound parameter: the number comes from this file only.
                $pdo->exec('PRAGMA user_version = ' . ($index + 1));
                $pdo->commit();
            } catch (\Throwable $error) {
                $pdo->rollBack();
                throw $error;
            }
        }
    }
}
