<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Session;
use RecupBuild\Core\View;
use RecupBuild\Models\Photos;
use RecupBuild\Models\Plans;
use RecupBuild\Models\Rebuilds;
use RecupBuild\Services\Assembly;
use RecupBuild\Services\Demo;

/**
 * The plans of the signed-in user:
 *
 *   GET /plans/{id}        the result: 3D view, cutting plan on the real wood, parts, steps
 *   GET /plans/{id}/photo  the photo of the heap it was cut from
 *   GET /history           every plan and reverse build, newest first
 */
final class PlanPageController extends Controller
{
    public function show(int $id): never
    {
        $user = $this->member('/plans/' . $id);
        $plan = Plans::find(Database::get($this->config), $user['id'], $id);
        if ($plan === null) {
            http_response_code(404);
            View::render('notfound', 'notfound.title');
        }

        $result = $plan['result'];
        $photo = null;
        $outlines = $plan['outlines']['pieces'] ?? [];
        $image = $plan['outlines']['image'] ?? null;
        if ($image !== null && $outlines !== []) {
            $url = $plan['photo'] === Photos::DEMO ? Demo::PHOTO_URL : (Photos::path($plan['photo']) !== null ? '/plans/' . $id . '/photo' : null);
            $photo = $url === null ? null : ['url' => $url, 'width' => (int) $image['width'], 'height' => (int) $image['height']];
        }

        View::render('plan', 'plan.title', [
            'step' => 'plan',
            'wide' => true,
            'plan' => $plan,
            'assembly' => Assembly::of($result['plan'], $result['assembly'] ?? null),
            'photo' => $photo,
            'outlines' => $outlines,
        ]);
    }

    public function photo(int $id): never
    {
        $user = $this->member('/history');
        $plan = Plans::find(Database::get($this->config), $user['id'], $id);
        Photos::send($plan['photo'] ?? null);
    }

    public function history(): never
    {
        $user = $this->member('/history');
        $pdo = Database::get($this->config);
        View::render('history', 'history.title', [
            'plans' => Plans::list($pdo, $user['id']),
            'rebuilds' => Rebuilds::list($pdo, $user['id']),
            'csrf' => Session::csrfToken(),
        ]);
    }
}
