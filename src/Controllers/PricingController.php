<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Session;
use RecupBuild\Core\View;
use RecupBuild\Models\Settings;
use RecupBuild\Models\Users;

/**
 * GET /pricing: the offers. Free is the only one open today (every account gets it
 * at its first sign-in, the offer of the moment kept on /admin/pricing); Pro and
 * Custom are announced as coming soon, the Pro price shown is the admin's setting.
 */
final class PricingController extends Controller
{
    public function show(): never
    {
        $user = Session::user();
        $pdo = Database::get($this->config);
        $credits = $user !== null && is_int($user['id'] ?? null) ? Users::credits($pdo, $user['id']) : null;

        View::render('pricing', 'pricing.title', [
            'credits' => $credits,
            'free' => max(0, (int) Settings::get($pdo, 'free_build_credits')),
            'proPrice' => Settings::get($pdo, 'pro_price'),
        ]);
    }
}
