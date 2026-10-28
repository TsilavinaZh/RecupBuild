<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Every suite, in one command: `php tests/run.php`
 */

require_once __DIR__ . '/scan_test.php';
require_once __DIR__ . '/plan_test.php';
require_once __DIR__ . '/auth_test.php';

finished();