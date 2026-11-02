<?php

declare(strict_types=1);

// Run from the command line only: PHP's built-in server would otherwise run it as a page.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * The instant demo for a visitor, over HTTP, on a server of its own that has no key, no
 * Google and a temporary folder: it must work, store nothing and reach nothing paid for.
 */

use RecupBuild\Services\Demo;
use RecupBuild\Services\Seo;

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../src/bootstrap.php';

echo "instant demo for a visitor\n";

const DEMO_PORT = 8095;
$folder = sys_get_temp_dir() . '/recupbuild-demo-' . bin2hex(random_bytes(4));
mkdir($folder);
$database = $folder . '/test.sqlite';
$quiet = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . DEMO_PORT, __DIR__ . '/fixtures/app_no_env.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $quiet, 'w'], 2 => ['file', $quiet, 'w']],
    $pipes,
    null,
    ['RECUPBUILD_TEST_DATABASE' => $database, 'STORAGE_PATH' => $folder] + getenv(),
);
// Whatever happens below, the server and its folder go with the tests.
register_shutdown_function(static function () use ($server, $folder): void {
    proc_terminate($server);
    usleep(200000);
    array_map('unlink', glob($folder . '/*') ?: []);
    @rmdir($folder);
});
for ($wait = 0; $wait < 100 && @fsockopen('127.0.0.1', DEMO_PORT, $code, $message, 0.1) === false; ++$wait) {
    usleep(50000);
}

/** One browser: its session cookie is kept from one request to the next. */
$browser = static function (): Closure {
    $cookie = '';

    return static function (string $method, string $path, array $form = []) use (&$cookie): array {
        $headers = $form === [] ? [] : ['Content-Type: application/x-www-form-urlencoded'];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie . '; recupbuild_lang=en';
        }
        $body = file_get_contents('http://127.0.0.1:' . DEMO_PORT . $path, false, stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => http_build_query($form),
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 20,
        ]]));
        $status = 0;
        $location = '';
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (preg_match('/^Location: (\S+)/i', $line, $match) === 1) {
                $location = $match[1];
            } elseif (preg_match('/^Set-Cookie: (recupbuild=[^;]+)/i', $line, $match) === 1) {
                $cookie = $match[1];
            }
        }

        return [$status, $location, (string) $body];
    };
};

$visit = $browser();
$csrf = static fn (string $page): string => preg_match('/name="csrf" value="([^"]+)"/', $page, $match) === 1 ? $match[1] : '';

[$status, $location] = $visit('GET', '/try');
check('one click starts the demo', $status === 303 && $location === '/inventory', "{$status} {$location}");

[$status, , $page] = $visit('GET', '/inventory');
$token = $csrf($page);
check('the visitor sees the measured pieces, marked as a demo', $status === 200 && $token !== '' && str_contains($page, 'Demo mode'), (string) $status);

[$status, $location] = $visit('POST', '/inventory', ['csrf' => $token, 'action' => 'continue']);
check('they confirm the inventory', $status === 303 && $location === '/projects', "{$status} {$location}");

[$status, , $page] = $visit('GET', '/projects');
$card = preg_match('/name="card" value="(\d+)"/', $page, $match) === 1 ? $match[1] : '';
check('the saved projects are offered', $status === 200 && $card !== '', (string) $status);

[$status, $location] = $visit('POST', '/projects/choose', ['csrf' => $csrf($page), 'card' => $card]);
check('choosing one plans it in the session', $status === 303 && $location === '/try/plan', "{$status} {$location}");

[$status, , $page] = $visit('GET', '/try/plan');
check('the plan shows its 3D view and the way to a real scan', $status === 200 && str_contains($page, 'data-scene3d') && str_contains($page, 'Scan your own wood'), (string) $status);
check('a demo plan offers no paid picture', !str_contains($page, 'action="/plans/'));
check('a visitor leaves nothing on disk', glob($folder . '/*') === [], implode(' ', glob($folder . '/*') ?: []));

$pieces = [];
foreach (Demo::scan()['pieces'] as $piece) {
    $pieces[$piece['id']] = ['kind' => $piece['kind'], 'length_mm' => $piece['length_mm'] / 2, 'width_mm' => $piece['width_mm'], 'thickness_mm' => $piece['thickness_mm']];
}
[, , $page] = $visit('GET', '/inventory');
$visit('POST', '/inventory', ['csrf' => $csrf($page), 'action' => 'continue', 'pieces' => $pieces]);
[$status, , $page] = $visit('GET', '/projects');
check('changed sizes are planned by the engine alone', $status === 200 && str_contains($page, 'Demo mode'), (string) $status);

$guarded = static fn (array $answer): bool => $answer[0] === 303 && str_starts_with($answer[1], '/auth/google');
$token = $csrf($page);
check('a visitor cannot send the example to the model', $guarded($visit('POST', '/scan', ['csrf' => $token, 'source' => 'example'])));
check('nor start a reverse build', $guarded($visit('POST', '/rebuild', ['csrf' => $token])));
check('nor pay for a picture', $guarded($visit('POST', '/plans/1/preview', ['csrf' => $token])));
check('nor open the history', $guarded($visit('GET', '/history')));
check('the API stays closed', $visit('POST', '/api/plan', ['project' => 'shelf'])[0] === 401 && $visit('POST', '/api/projects', ['pieces' => '[]'])[0] === 401);

$stranger = $browser();
check('without the demo, the app still asks to sign in', $guarded($stranger('GET', '/inventory')) && $guarded($stranger('GET', '/projects')));
[$status, $location] = $stranger('GET', '/try/plan');
check('a plan page with no plan starts the demo', $status === 303 && $location === '/try', "{$status} {$location}");
[$status, $location] = $stranger('GET', '/auth/google');
check('a copy with no Google credentials falls back on the demo', $status === 303 && $location === '/try', "{$status} {$location}");

$robots = Seo::robots('https://example.test');
check('search engines stay out of the demo', str_contains($robots, 'Disallow: /try'));
