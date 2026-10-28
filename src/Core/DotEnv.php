<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * Minimal .env reader: KEY=VALUE lines, comments and empty lines skipped (so is the
 * "<?php exit; ?>" line that opens .env.php), existing environment wins.
 */
final class DotEnv
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}