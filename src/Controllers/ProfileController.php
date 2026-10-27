<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Session;
use RecupBuild\Core\View;
use RecupBuild\Models\Plans;
use RecupBuild\Models\Rebuilds;
use RecupBuild\Models\Users;

/**
 * The signed-in user's own page:
 *
 *   GET  /profile                       who they are, what is left of the free offer, their plans
 *   POST /profile/plans/{id}/delete     removes one plan (confirmed in a dialog first)
 *   POST /profile/rebuilds/{id}/delete  removes one reverse build (confirmed first)
 *   POST /profile/logout                signs out (confirmed in a dialog first)
 *
 * Without a session, the page sends the visitor to Google and back here. The forms
 * carry the session's CSRF token; `back` says which page to return to.
 */
final class ProfileController extends Controller
{
    public function show(): never
    {
        $user = $this->member('/profile');
        $pdo = Database::get($this->config);

        View::render('profile', 'profile.title', [
            'account' => Users::find($pdo, $user['id']) ?? [],
            'credits' => Users::credits($pdo, $user['id']),
            'free' => Users::FREE_CREDITS,
            'plans' => Plans::list($pdo, $user['id']),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function deletePlan(int $id): never
    {
        $user = $this->member('/profile');
        $back = self::back();
        $this->checkForm($back);
        $deleted = Plans::delete(Database::get($this->config), $user['id'], $id);
        Session::flash($deleted ? 'profile.deleted' : 'profile.notFound');
        self::redirect($back);
    }

    public function deleteRebuild(int $id): never
    {
        $user = $this->member('/history');
        $back = self::back();
        $this->checkForm($back);
        $deleted = Rebuilds::delete(Database::get($this->config), $user['id'], $id);
        Session::flash($deleted ? 'history.rebuildDeleted' : 'profile.notFound');
        self::redirect($back);
    }

    public function logout(): never
    {
        $this->member('/profile');
        $this->checkForm('/profile');
        Session::signOut();
        self::redirect('/');
    }

    /** Where the form came from: one of the pages that list plans, never anywhere else. */
    private static function back(): string
    {
        return in_array($_POST['back'] ?? null, ['/profile', '/history'], true) ? $_POST['back'] : '/profile';
    }
}
