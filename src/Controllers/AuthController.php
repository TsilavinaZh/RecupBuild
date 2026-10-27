<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Http;
use RecupBuild\Core\Session;
use RecupBuild\Errors\AuthError;
use RecupBuild\Models\Users;
use RecupBuild\Services\GoogleAuth;

/**
 * "Sign in with Google" done by the server (see Services/GoogleAuth):
 *
 *   GET  /auth/google           redirects to Google (?return=/path to come back to)
 *   GET  /auth/google/callback  where Google sends the user back
 *   GET  /api/me                the signed-in user, or 401
 *   POST /auth/logout           signs out
 */
final class AuthController extends Controller
{
    public function redirect(): never
    {
        if ($this->config->googleClientId === '' || $this->config->googleClientSecret === '') {
            Http::fail('auth_not_configured', 503);
        }
        Session::start();
        $_SESSION['oauth'] = [
            'state' => bin2hex(random_bytes(16)),
            'nonce' => bin2hex(random_bytes(16)),
            'return' => self::localPath($_GET['return'] ?? '/'),
        ];
        header('Location: ' . GoogleAuth::authUrl($this->config, $this->redirectUri(), $_SESSION['oauth']['state'], $_SESSION['oauth']['nonce']), true, 302);
        exit;
    }

    public function callback(): never
    {
        Session::start();
        $pending = $_SESSION['oauth'] ?? null;
        unset($_SESSION['oauth']);
        $back = is_array($pending) ? $pending['return'] : '/';

        // The state proves the answer belongs to the sign-in this browser started.
        $state = $_GET['state'] ?? null;
        if (!is_array($pending) || !is_string($state) || !hash_equals($pending['state'], $state)) {
            self::failed($back, 'state missing or different');
        }
        $code = $_GET['code'] ?? null;
        if (!is_string($code) || $code === '') {
            self::failed($back, 'no code (' . (is_string($_GET['error'] ?? null) ? $_GET['error'] : 'unknown') . ')');
        }

        try {
            $idToken = GoogleAuth::exchange($this->config, $code, $this->redirectUri());
            $google = GoogleAuth::claims($idToken, $this->config->googleClientId, $pending['nonce'], time());
            // The account is kept in the database: created at the first sign-in, refreshed after.
            Session::signIn(Users::signedIn(Database::get($this->config), $google));
        } catch (AuthError $error) {
            self::failed($back, $error->getMessage());
        } catch (\PDOException $error) {
            self::failed($back, 'database: ' . $error->getMessage());
        }
        header('Location: ' . $back, true, 302);
        exit;
    }

    public function me(): never
    {
        header('Cache-Control: no-store');
        $user = Session::user();
        if ($user === null) {
            Http::fail('unauthorized', 401);
        }
        Http::json(200, ['user' => $user]);
    }

    public function logout(): never
    {
        Session::signOut();
        http_response_code(204);
        exit;
    }

    /** Where Google sends the user back: the configured URI, or this very server's callback. */
    private function redirectUri(): string
    {
        if ($this->config->googleRedirectUri !== '') {
            return $this->config->googleRedirectUri;
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/auth/google/callback';
    }

    /** A path of this site to come back to after sign-in: never another site. */
    private static function localPath(mixed $value): string
    {
        return is_string($value) && preg_match('#^/(?!/)[^\\\s]*$#', $value) === 1 ? $value : '/';
    }

    /** Back where the user came from, saying the sign-in did not go through. */
    private static function failed(string $back, string $why): never
    {
        error_log('[recupbuild] Google sign-in failed: ' . $why);
        header('Location: ' . $back . (str_contains($back, '?') ? '&' : '?') . 'auth=failed', true, 302);
        exit;
    }
}
