<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Session;
use RecupBuild\Core\View;
use RecupBuild\Models\Users;

/**
 * GET /pricing: the offers. Free is the only one open today (every account gets it
 * at its first sign-in); Pro and Custom are announced as coming soon.
 */
final class PricingController extends Controller
{
    public function show(): never
    {
        $user = Session::user();
        $credits = $user !== null && is_int($user['id'] ?? null) ? Users::credits(Database::get($this->config), $user['id']) : null;

        View::render('pricing', 'pricing.title', [
            'credits' => $credits,
            'free' => Users::FREE_CREDITS,
        ]);
    }
}
