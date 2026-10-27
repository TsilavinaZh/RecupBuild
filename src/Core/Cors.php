<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * CORS for the development server of the frontend (another origin); not needed when
 * the API and the site share one origin. Preflight requests stop here.
 */
final class Cors
{
    public static function apply(Config $config, string $method): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $anyOrigin = in_array('*', $config->allowedOrigins, true);
        if ($origin !== '' && ($anyOrigin || in_array($origin, $config->allowedOrigins, true))) {
            header('Access-Control-Allow-Origin: ' . ($anyOrigin ? '*' : $origin));
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        $requestedHeaders = (string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
        header('Access-Control-Allow-Headers: ' . ($requestedHeaders !== '' ? $requestedHeaders : 'Content-Type, Authorization'));

        if ($method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
