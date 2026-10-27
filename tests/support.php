<?php

declare(strict_types=1);

/**
 * Assertions shared by the suites: `php tests/run.php` runs them all.
 */

$failures = 0;
$count = 0;

function same(mixed $expected, mixed $actual): bool
{
    if (is_array($expected) && is_array($actual)) {
        if (array_keys($expected) !== array_keys($actual)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (!same($value, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    if (is_int($expected) || is_float($expected)) {
        return (is_int($actual) || is_float($actual)) && abs((float) $expected - (float) $actual) < 1e-9;
    }

    return $expected == $actual;
}

function check(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $count;
    ++$count;
    if ($condition) {
        echo "  ok    {$name}\n";

        return;
    }
    ++$failures;
    echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function throwsScan(callable $run, string $code, string $name): void
{
    try {
        $run();
        check($name, false, "no ScanError thrown, expected '{$code}'");
    } catch (\RecupBuild\Errors\ScanError $error) {
        check($name, $error->errorCode === $code, "got '{$error->errorCode}', expected '{$code}'");
    }
}

function finished(): never
{
    global $failures, $count;
    echo "\n";
    if ($failures > 0) {
        echo "{$failures} of {$count} checks failed\n";
        exit(1);
    }
    echo "all {$count} checks passed\n";
    exit(0);
}