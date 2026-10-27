<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * A failure the user can act on, with a code the frontend knows how to explain
 * (port of app/scan.py::ScanError). The default status is 422, like FastAPI's.
 */
final class ScanError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($errorCode);
    }
}