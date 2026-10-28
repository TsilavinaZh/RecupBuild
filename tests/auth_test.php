<?php

declare(strict_types=1);

/**
 * The checks on Google's ID token, without calling Google.
 */

use RecupBuild\Errors\AuthError;
use RecupBuild\Services\GoogleAuth;

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../src/bootstrap.php';

const CLIENT = 'client-123.apps.googleusercontent.com';
const NOW = 1_800_000_000;

/** An unsigned JWT with these claims over a valid default set. */
function idToken(array $overrides = []): string
{
    $claims = $overrides + [
        'iss' => 'https://accounts.google.com',
        'aud' => CLIENT,
        'exp' => NOW + 3600,
        'nonce' => 'nonce-abc',
        'sub' => '1098765',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'name' => 'Ada',
        'picture' => 'https://example.com/ada.png',
    ];
    $part = static fn (array $data): string => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');

    return $part(['alg' => 'RS256']) . '.' . $part($claims) . '.signature';
}

function refused(string $token, string $nonce = 'nonce-abc'): bool
{
    try {
        GoogleAuth::claims($token, CLIENT, $nonce, NOW);
    } catch (AuthError) {
        return true;
    }

    return false;
}

echo "google sign-in\n";

check('reads the user of a valid ID token', same(
    ['sub' => '1098765', 'email' => 'ada@example.com', 'name' => 'Ada', 'picture' => 'https://example.com/ada.png'],
    GoogleAuth::claims(idToken(), CLIENT, 'nonce-abc', NOW),
));
check('refuses a token issued by someone else', refused(idToken(['iss' => 'https://evil.example'])));
check('refuses a token meant for another app', refused(idToken(['aud' => 'other.apps.googleusercontent.com'])));
check('refuses an expired token', refused(idToken(['exp' => NOW - 3600])));
check('refuses a token from another sign-in (nonce)', refused(idToken(), 'nonce-other'));
check('refuses what is not a JWT', refused('not-a-token'));
check('ignores an e-mail Google did not verify', GoogleAuth::claims(idToken(['email_verified' => false]), CLIENT, 'nonce-abc', NOW)['email'] === null);
check('the sign-in link carries the state, the nonce and the scopes', (static function (): bool {
    $config = new RecupBuild\Core\Config();
    $config->googleClientId = CLIENT;
    parse_str((string) parse_url(GoogleAuth::authUrl($config, 'http://localhost:8080/auth/google/callback', 's1', 'n1'), PHP_URL_QUERY), $query);

    return $query['state'] === 's1' && $query['nonce'] === 'n1' && $query['scope'] === 'openid email profile'
        && $query['response_type'] === 'code' && $query['redirect_uri'] === 'http://localhost:8080/auth/google/callback';
})());

echo "database\n";

$pdo = RecupBuild\Core\Database::open(':memory:');
check('creates the schema on first use', (int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 1);
$first = RecupBuild\Models\Users::signedIn($pdo, ['sub' => '42', 'email' => 'ada@example.com', 'name' => 'Ada', 'picture' => null], '2026-10-27T10:00:00Z');
$again = RecupBuild\Models\Users::signedIn($pdo, ['sub' => '42', 'email' => 'ada@new.example', 'name' => 'Ada L.', 'picture' => null], '2026-10-28T10:00:00Z');
$row = RecupBuild\Models\Users::find($pdo, $again['id']);
check('one row per Google account, signed in twice', $first['id'] === $again['id'] && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
check('refreshes the name, e-mail and last sign-in, keeps the creation date', $row !== null
    && $row['name'] === 'Ada L.' && $row['email'] === 'ada@new.example'
    && $row['created_at'] === '2026-10-27T10:00:00Z' && $row['last_login_at'] === '2026-10-28T10:00:00Z');

echo "history\n";

$pdo = RecupBuild\Core\Database::open(':memory:');
check('upgrades the schema to the history', (int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 2);
$ada = RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'a', 'email' => null, 'name' => 'Ada', 'picture' => null]);
$bob = RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'b', 'email' => null, 'name' => 'Bob', 'picture' => null]);
$result = ['status' => 'feasible', 'plan' => ['project' => 'shelf', 'params' => ['width' => 410], 'placements' => [['number' => 1], ['number' => 2]]], 'steps' => ['Poncez.']];
$first = RecupBuild\Models\Plans::save($pdo, $ada['id'], RecupBuild\Models\Projects::definition('shelf'), [['id' => 1, 'kind' => 'plank']], $result, '2026-10-27T10:00:00Z');
$second = RecupBuild\Models\Plans::save($pdo, $ada['id'], ['id' => 'ai-1', 'name' => 'Tabouret'] + RecupBuild\Models\Projects::definition('crate'), [], $result, '2026-10-28T10:00:00Z');
$list = RecupBuild\Models\Plans::list($pdo, $ada['id']);
check('lists the plans of the user, newest first, with their size and part count', count($list) === 2 && $list[0]['id'] === $second
    && $list[0]['project_name'] === 'Tabouret' && $list[1]['params'] === ['width' => 410] && $list[1]['parts'] === 2);
check('gives a plan back as it was saved', RecupBuild\Models\Plans::find($pdo, $ada['id'], $first)['result'] === $result);
check('never shows a plan to another user', RecupBuild\Models\Plans::find($pdo, $bob['id'], $first) === null && RecupBuild\Models\Plans::list($pdo, $bob['id']) === []);
check('another user cannot delete it either', !RecupBuild\Models\Plans::delete($pdo, $bob['id'], $first) && RecupBuild\Models\Plans::delete($pdo, $ada['id'], $first));

echo "layout\n";

$config = RecupBuild\Core\Config::load(dirname(__DIR__) . '/.env.php');
$project = realpath(dirname(__DIR__));
check('the database and the photos live outside the project folder, which PHP may serve',
    !str_starts_with(RecupBuild\Core\Config::storageDir(), $project)
    && (getenv('DATABASE_PATH') !== false || dirname($config->databasePath) === RecupBuild\Core\Config::storageDir()));
check('the site files sit next to index.php', is_file(dirname(__DIR__) . '/index.php') && is_file(dirname(__DIR__) . '/css/pages.css')
    && is_file(dirname(__DIR__) . '/js/pages.js') && !is_dir(dirname(__DIR__) . '/public'));
check('every test refuses to run from a web request', array_filter(glob(__DIR__ . '/*.php') ?: [],
    static fn (string $file): bool => !str_contains((string) file_get_contents($file), "PHP_SAPI !== 'cli'")) === []);
$views = ['layout', 'home', 'about', 'notfound', 'pricing', 'profile', 'scan', 'inventory', 'projects', 'plan', 'plan-failed', 'history', 'rebuild', 'rebuild-show'];
check('every page has its view', array_filter($views, static fn (string $view): bool => !is_file(dirname(__DIR__) . '/src/Views/' . $view . '.php')) === []);
$fr = require dirname(__DIR__) . '/src/Views/lang/fr.php';
$en = require dirname(__DIR__) . '/src/Views/lang/en.php';
check('English has every text French has', array_diff_key($fr, $en) === []);
$used = [];
foreach (glob(dirname(__DIR__) . '/src/Views/*.php') as $file) {
    preg_match_all('/\$t\(\'([a-zA-Z0-9_.]+)\'/', (string) file_get_contents($file), $found);
    // "kind." . $kind and the like are built at run time: only whole keys are checked.
    $used = array_merge($used, array_filter($found[1], static fn (string $key): bool => !str_ends_with($key, '.')));
}
check('every text a view asks for exists', array_diff(array_unique($used), array_keys($fr)) === [], implode(', ', array_diff(array_unique($used), array_keys($fr))));

echo "free offer\n";

$pdo = RecupBuild\Core\Database::open(':memory:');
$ada = RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'ada', 'email' => null, 'name' => 'Ada', 'picture' => null]);
check('a new account gets 5 builds and 5 rebuilds', RecupBuild\Models\Users::credits($pdo, $ada['id']) === ['build' => 5, 'rebuild' => 5]);
$spent = 0;
while (RecupBuild\Models\Users::spend($pdo, $ada['id'], 'build')) {
    ++$spent;
}
check('spends exactly 5 builds, then refuses', $spent === 5 && RecupBuild\Models\Users::credits($pdo, $ada['id']) === ['build' => 0, 'rebuild' => 5]);
RecupBuild\Models\Users::signedIn($pdo, ['sub' => 'ada', 'email' => null, 'name' => 'Ada', 'picture' => null]);
check('signing in again never gives the credits back', RecupBuild\Models\Users::credits($pdo, $ada['id'])['build'] === 0);
