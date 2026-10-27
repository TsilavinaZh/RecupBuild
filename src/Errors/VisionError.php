<?php

declare(strict_types=1);

namespace RecupBuild\Errors;

/**
 * The model could not be reached or answered something unusable
 * (port of app/providers/vision.py::VisionError).
 */
final class VisionError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $detail = '',
    ) {
        parent::__construct($detail !== '' ? $detail : $errorCode);
    }
}