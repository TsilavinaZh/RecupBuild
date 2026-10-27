<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Http;
use RecupBuild\Core\Request;
use RecupBuild\Errors\VisionError;
use RecupBuild\Models\Plans;
use RecupBuild\Models\Users;
use RecupBuild\Services\Planner;

/**
 * The AI planner:
 *
 *   POST /api/projects  the project cards: projects the model invents for these pieces
 *   POST /api/plan      sizes, cutting plan and fabrication steps of one project (kept in the history)
 *
 * A plan is a "build": it uses one credit of the free offer, only once it succeeded.
 */
final class PlanController extends Controller
{
    public function projects(): never
    {
        $this->user();
        $pieces = $this->pieces(Request::json());
        try {
            Http::json(200, Planner::projects($pieces, Request::locale(), $this->config));
        } catch (VisionError $error) {
            $this->modelFailed('planner', $error);
        } catch (\Throwable $error) {
            $this->crashed($error);
        }
    }

    public function plan(): never
    {
        $user = $this->user();
        $body = Request::json();
        $pieces = $this->pieces($body);
        $pdo = Database::get($this->config);
        // Refused before the model is paid for, not after.
        if (Users::credits($pdo, $user['id'])['build'] <= 0) {
            Http::fail('quota_exhausted', 402);
        }
        try {
            // Parameters chosen by the card are re-checked, and the model writes the steps for them.
            $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : null;
            $definition = Request::project($body);
            $result = Planner::plan($definition, $pieces, Request::locale(), $params, $this->config);
            if ($result['status'] === 'feasible') {
                Users::spend($pdo, $user['id'], 'build');
                // The history keeps it, so the user finds it again without paying the model twice.
                try {
                    $result['history_id'] = Plans::save($pdo, $user['id'], $definition, $pieces, $result);
                } catch (\Throwable $error) {
                    error_log('[recupbuild] history not saved: ' . $error->getMessage());
                }
            }
            $result['credits'] = Users::credits($pdo, $user['id']);
            Http::json(200, $result);
        } catch (VisionError $error) {
            $this->modelFailed('planner', $error);
        } catch (\Throwable $error) {
            $this->crashed($error);
        }
    }

    /** The inventory of the request; a plan needs at least one piece. */
    private function pieces(array $body): array
    {
        $pieces = Request::pieces($body);
        if ($pieces === []) {
            Http::fail('invalid_json', 422);
        }

        return $pieces;
    }
}
