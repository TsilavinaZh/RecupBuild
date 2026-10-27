<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Http;
use RecupBuild\Models\Plans;

/**
 * The signed-in user's history (Models/Plans), never anyone else's:
 *
 *   GET    /api/history       the plans, newest first
 *   GET    /api/history/{id}  one plan, whole (to show it again)
 *   DELETE /api/history/{id}  removes it
 */
final class HistoryController extends Controller
{
    public function index(): never
    {
        $user = $this->user();
        header('Cache-Control: no-store');
        Http::json(200, ['items' => Plans::list(Database::get($this->config), $user['id'])]);
    }

    public function show(int $id): never
    {
        $user = $this->user();
        $plan = Plans::find(Database::get($this->config), $user['id'], $id);
        if ($plan === null) {
            Http::fail('not_found', 404);
        }
        header('Cache-Control: no-store');
        Http::json(200, $plan);
    }

    public function destroy(int $id): never
    {
        $user = $this->user();
        if (!Plans::delete(Database::get($this->config), $user['id'], $id)) {
            Http::fail('not_found', 404);
        }
        http_response_code(204);
        exit;
    }
}
