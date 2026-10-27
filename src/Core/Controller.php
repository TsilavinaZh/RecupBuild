<?php

declare(strict_types=1);

namespace RecupBuild\Core;

use RecupBuild\Errors\VisionError;

/**
 * What every controller shares: the settings, and the signed-in user of the
 * request when the action needs one. The answer is JSON (the "view" of the API),
 * written by Http; every action ends the request.
 */
abstract class Controller
{
    public function __construct(protected readonly Config $config)
    {
    }

    /**
     * The signed-in user of this request (their database id included); 401 otherwise.
     *
     * @return array{id: int, sub: string, email: ?string, name: string, picture: ?string}
     */
    protected function user(): array
    {
        $user = Session::user();
        if ($user === null || !is_int($user['id'] ?? null)) {
            Http::fail('unauthorized', 401);
        }
        // Only this request needs the session: release its lock for the user's other calls.
        session_write_close();

        return $user;
    }

    /**
     * For the pages: the signed-in user, or off to Google and back to `$return`.
     *
     * @return array{id: int, sub: string, email: ?string, name: string, picture: ?string}
     */
    protected function member(string $return): array
    {
        $user = Session::user();
        if ($user === null || !is_int($user['id'] ?? null)) {
            self::redirect('/auth/google?return=' . rawurlencode($return));
        }

        return $user;
    }

    /** A form from another site, or from an expired page, changes nothing. */
    protected function checkForm(string $back): void
    {
        if (!Session::csrfValid($_POST['csrf'] ?? null)) {
            Session::flash('profile.expired');
            self::redirect($back);
        }
    }

    /** After a form, the browser goes to a page (so reloading never posts twice). */
    protected static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    /** The model's failures, said the way the frontend reads them. */
    protected function modelFailed(string $what, VisionError $error): never
    {
        error_log('[recupbuild] ' . $what . ' failed: ' . $error->getMessage());
        Http::fail($error->errorCode, $error->errorCode === 'timeout' ? 504 : 502);
    }

    /** Anything unexpected: logged in full, answered as a plain server error. */
    protected function crashed(\Throwable $error): never
    {
        error_log('[recupbuild] ' . $error);
        Http::fail('server', 500);
    }
}
