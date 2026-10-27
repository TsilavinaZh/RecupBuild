<?php

declare(strict_types=1);

/**
 * RecupBuild API, PHP edition (the port of backend/app/main.py).
 *
 *   GET  /api/health
 *   POST /api/scan   photo (multipart field "file") -> A4 sheet and measured pieces
 *   POST /api/plan   sizes, cutting plan and fabrication steps of one project
 *   POST /api/projects  the project cards: projects the model invents for these pieces
 *   POST /api/reverse   photo of a finished object (field "file") -> parts, tools, steps to build it
 *
 *   GET  /auth/google           sign in with Google (redirects to Google)
 *   GET  /auth/google/callback  where Google sends the user back
 *   GET  /api/me                the signed-in user, or 401
 *   POST /auth/logout           signs out
 *
 *   GET    /api/history         the user's plans, newest first
 *   GET    /api/history/{id}    one plan, whole (to show it again)
 *   DELETE /api/history/{id}    removes it
 *
 * Every /api/ route but /api/health needs a signed-in user: the model is paid for.
 * Everything else falls back to public/index.html (the built Vue app) when it exists.
 * Routes are matched by hand: the API has four of them and no framework is needed.
 */

use RecupBuild\AuthError;
use RecupBuild\Config;
use RecupBuild\Database;
use RecupBuild\GoogleAuth;
use RecupBuild\Http;
use RecupBuild\Image;
use RecupBuild\Planner;
use RecupBuild\Plans;
use RecupBuild\Request;
use RecupBuild\Reverse;
use RecupBuild\Scan;
use RecupBuild\Session;
use RecupBuild\Users;
use RecupBuild\ScanError;
use RecupBuild\Vision;
use RecupBuild\VisionError;

require __DIR__ . '/../src/bootstrap.php';

// Two model calls follow each other (sizes, then steps): give them the time,
// however strict the php.ini of the host is.
@set_time_limit(300);

$config = Config::load(dirname(__DIR__) . '/.env');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

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

// ------------------------------------------------------------------ Google sign-in

/** Where Google sends the user back: the configured URI, or this very server's callback. */
$redirectUri = static function () use ($config): string {
    if ($config->googleRedirectUri !== '') {
        return $config->googleRedirectUri;
    }
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/google/callback';
};

/** A path of this site to come back to after sign-in: never another site. */
$localPath = static function (mixed $value): string {
    return is_string($value) && preg_match('#^/(?!/)[^\\\s]*$#', $value) === 1 ? $value : '/';
};

if ($path === '/auth/google' && $method === 'GET') {
    if ($config->googleClientId === '' || $config->googleClientSecret === '') {
        Http::fail('auth_not_configured', 503);
    }
    Session::start();
    $_SESSION['oauth'] = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'return' => $localPath($_GET['return'] ?? '/'),
    ];
    header('Location: ' . GoogleAuth::authUrl($config, $redirectUri(), $_SESSION['oauth']['state'], $_SESSION['oauth']['nonce']), true, 302);
    exit;
}

if ($path === '/auth/google/callback' && $method === 'GET') {
    Session::start();
    $pending = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    $back = is_array($pending) ? $pending['return'] : '/';
    $failed = static function (string $why) use ($back): never {
        error_log('[recupbuild] Google sign-in failed: ' . $why);
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'auth=failed', true, 302);
        exit;
    };

    // The state proves the answer belongs to the sign-in this browser started.
    $state = $_GET['state'] ?? null;
    if (!is_array($pending) || !is_string($state) || !hash_equals($pending['state'], $state)) {
        $failed('state missing or different');
    }
    $code = $_GET['code'] ?? null;
    if (!is_string($code) || $code === '') {
        $failed('no code (' . (is_string($_GET['error'] ?? null) ? $_GET['error'] : 'unknown') . ')');
    }

    try {
        $idToken = GoogleAuth::exchange($config, $code, $redirectUri());
        $google = GoogleAuth::claims($idToken, $config->googleClientId, $pending['nonce'], time());
        // The account is kept in the database: created at the first sign-in, refreshed after.
        Session::signIn(Users::signedIn(Database::get($config), $google));
    } catch (AuthError $error) {
        $failed($error->getMessage());
    } catch (PDOException $error) {
        $failed('database: ' . $error->getMessage());
    }
    header('Location: ' . $back, true, 302);
    exit;
}

if ($path === '/api/me' && $method === 'GET') {
    header('Cache-Control: no-store');
    $user = Session::user();
    if ($user === null) {
        Http::fail('unauthorized', 401);
    }
    Http::json(200, ['user' => $user]);
}

if ($path === '/auth/logout' && $method === 'POST') {
    Session::signOut();
    http_response_code(204);
    exit;
}

if (!str_starts_with($path, '/api/')) {
    $page = __DIR__ . '/index.html';
    if (is_file($page)) {
        header('Content-Type: text/html; charset=utf-8');
        // Always the latest build: it names the current asset files, which are cached forever.
        header('Cache-Control: no-cache');
        readfile($page);
        exit;
    }
    Http::fail('unknown', 404);
}

if ($path === '/api/health' && $method === 'GET') {
    Http::json(200, [
        'status' => 'ok',
        'vision_model' => $config->visionModel,
        'key_configured' => $config->metaApiKey !== '',
    ]);
}

/**
 * The signed-in user of this request (their database id included); 401 otherwise.
 *
 * @return array{id: int, sub: string, email: ?string, name: string, picture: ?string}
 */
$requireUser = static function (): array {
    $user = Session::user();
    if ($user === null || !is_int($user['id'] ?? null)) {
        Http::fail('unauthorized', 401);
    }
    // Only this request needs the session: release its lock for the user's other calls.
    session_write_close();

    return $user;
};

if ($path === '/api/history' && $method === 'GET') {
    $user = $requireUser();
    header('Cache-Control: no-store');
    Http::json(200, ['items' => Plans::list(Database::get($config), $user['id'])]);
}

if (preg_match('#^/api/history/(\d+)$#', $path, $match) === 1 && in_array($method, ['GET', 'DELETE'], true)) {
    $user = $requireUser();
    $pdo = Database::get($config);
    if ($method === 'DELETE') {
        if (!Plans::delete($pdo, $user['id'], (int) $match[1])) {
            Http::fail('not_found', 404);
        }
        http_response_code(204);
        exit;
    }
    $plan = Plans::find($pdo, $user['id'], (int) $match[1]);
    if ($plan === null) {
        Http::fail('not_found', 404);
    }
    header('Cache-Control: no-store');
    Http::json(200, $plan);
}

// The two photo routes share the upload checks: a heap to scan, or a finished object to rebuild.
if (($path === '/api/scan' || $path === '/api/reverse') && $method === 'POST') {
    $requireUser();
    $file = $_FILES['file'] ?? null;
    if (!is_array($file)) {
        Http::fail('unknown', 422);
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        Http::fail('file_too_large', 413);
    }
    if ($error === UPLOAD_ERR_NO_FILE) {
        Http::fail('unknown', 422);
    }
    if ($error !== UPLOAD_ERR_OK) {
        Http::fail('server', 500);
    }
    if ((int) $file['size'] > $config::MAX_UPLOAD_BYTES) {
        Http::fail('file_too_large', 413);
    }

    $data = @file_get_contents((string) $file['tmp_name']);
    if ($data === false) {
        Http::fail('unsupported_type', 415);
    }

    try {
        [$jpeg, $width, $height] = Image::prepare($data, $config);
        if ($path === '/api/reverse') {
            Http::json(200, Reverse::analyse($jpeg, Request::locale(), $config));
        }
        $model = Vision::analyse($jpeg, $config);
        Http::json(200, Scan::buildResponse($model, $width, $height));
    } catch (ScanError $error) {
        Http::fail($error->errorCode, $error->status);
    } catch (VisionError $error) {
        error_log('[recupbuild] vision failed: ' . $error->getMessage());
        Http::fail($error->errorCode, $error->errorCode === 'timeout' ? 504 : 502);
    } catch (Throwable $error) {
        error_log('[recupbuild] ' . $error);
        Http::fail('server', 500);
    }
}

// The AI planner: the project sizes, the calculation log and the fabrication steps.
if (($path === '/api/plan' || $path === '/api/projects') && $method === 'POST') {
    $user = $requireUser();
    $body = Request::json();
    $pieces = Request::pieces($body);
    if ($pieces === []) {
        Http::fail('invalid_json', 422);
    }

    try {
        if ($path === '/api/projects') {
            // One call for the project cards of the Project screen.
            Http::json(200, Planner::projects($pieces, Request::locale(), $config));
        }

        // Parameters chosen by the card are re-checked, and the model writes the steps for them.
        $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : null;
        $definition = Request::project($body);
        $result = Planner::plan($definition, $pieces, Request::locale(), $params, $config);
        if ($result['status'] === 'feasible') {
            // The history keeps it, so the user finds it again without paying the model twice.
            try {
                $result['history_id'] = Plans::save(Database::get($config), $user['id'], $definition, $pieces, $result);
            } catch (Throwable $error) {
                error_log('[recupbuild] history not saved: ' . $error->getMessage());
            }
        }
        Http::json(200, $result);
    } catch (VisionError $error) {
        error_log('[recupbuild] planner failed: ' . $error->getMessage());
        Http::fail($error->errorCode, $error->errorCode === 'timeout' ? 504 : 502);
    } catch (Throwable $error) {
        error_log('[recupbuild] ' . $error);
        Http::fail('server', 500);
    }
}

Http::fail('unknown', 404);