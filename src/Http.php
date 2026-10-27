<?php

declare(strict_types=1);

namespace RecupBuild;

/**
 * JSON answers in the shape the frontend already reads
 * (FastAPI's errors are { "detail": { "code": "..." } }, see frontend/src/api/errors.ts).
 */
final class Http
{
    public static function json(int $status, mixed $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        exit;
    }

    public static function fail(string $code, int $status): never
    {
        self::json($status, ['detail' => ['code' => $code]]);
    }
}