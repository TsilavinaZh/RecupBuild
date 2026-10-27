<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * The signed-in user, kept in PHP's session: an HttpOnly cookie the page's scripts
 * cannot read, sent back only to this site (SameSite=Lax, Secure over HTTPS).
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        session_name('recupbuild');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    /** @return ?array{sub: string, email: ?string, name: string, picture: ?string} */
    public static function user(): ?array
    {
        self::start();
        $user = $_SESSION['user'] ?? null;

        return is_array($user) ? $user : null;
    }

    /** Signs the user in: a new session id, so an id set before sign-in is worthless. */
    public static function signIn(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
    }

    public static function signOut(): void
    {
        self::start();
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy();
    }

    /** The token every form of the server pages carries, so another site cannot post them. */
    public static function csrfToken(): string
    {
        self::start();
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['csrf'];
    }

    /** True when the posted form carries this session's token. */
    public static function csrfValid(mixed $token): bool
    {
        self::start();

        return is_string($token) && is_string($_SESSION['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $token);
    }

    /** A one-time message for the next page ("Plan deleted."); reading it clears it. */
    public static function flash(?string $key = null): ?string
    {
        self::start();
        if ($key !== null) {
            $_SESSION['flash'] = $key;

            return null;
        }
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return is_string($message) ? $message : null;
    }
}
