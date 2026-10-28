<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Request;
use RecupBuild\Core\Session;
use RecupBuild\Core\Upload;
use RecupBuild\Core\View;
use RecupBuild\Errors\ScanError;
use RecupBuild\Errors\VisionError;
use RecupBuild\Models\Photos;
use RecupBuild\Models\Plans;
use RecupBuild\Models\Projects;
use RecupBuild\Models\Users;
use RecupBuild\Models\Workspace;
use RecupBuild\Services\Assembly;
use RecupBuild\Services\Demo;
use RecupBuild\Services\Image;
use RecupBuild\Services\Planner;
use RecupBuild\Services\Scan;
use RecupBuild\Services\Solver;
use RecupBuild\Services\Vision;

/**
 * The app, from the photo to the plan (pages, rendered by the server):
 *
 *   GET  /scan              the photo of the heap (upload, the AI example or the demo)
 *   POST /scan              analyses it, then on to the inventory
 *   GET  /scan/photo        the analysed photo of this session
 *   POST /scan/reset        starts again
 *   GET  /inventory         the measured pieces, to correct
 *   POST /inventory         saves the table (and continues when it is complete)
 *   GET  /projects          four projects for this wood (the model invents them once)
 *   POST /projects/choose   plans the chosen one, then its page (Plans, /plans/{id})
 *
 * The demo uses the sample photo and the four built-in projects planned by the engine
 * alone: no model call, no credit used.
 */
final class WorkspaceController extends Controller
{
    public function scan(): never
    {
        $user = $this->member('/scan');
        View::render('scan', 'scan.title', [
            'step' => 'scan',
            'workspace' => Workspace::get(),
            'credits' => Users::credits(Database::get($this->config), $user['id']),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function upload(): never
    {
        $this->member('/scan');
        $this->checkForm('/scan');

        if (($_POST['source'] ?? '') === 'demo') {
            $scan = Demo::scan();
            Workspace::start(Photos::DEMO, $scan['image'], $scan['pieces'], true);
            self::redirect('/inventory');
        }

        if (($_POST['source'] ?? '') === 'example') {
            $data = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/pile.jpg');
        } else {
            [$data, $error] = Upload::read('photo');
            if ($data === null) {
                self::failed($error ?? 'unknown');
            }
        }

        try {
            [$jpeg, $width, $height] = Image::prepare($data, $this->config);
            $scan = Scan::buildResponse(Vision::analyse($jpeg, $this->config), $width, $height);
            Workspace::start(Photos::store($jpeg), $scan['image'], $scan['pieces'], false);
        } catch (ScanError $error) {
            self::failed($error->errorCode);
        } catch (VisionError $error) {
            error_log('[recupbuild] vision failed: ' . $error->getMessage());
            self::failed($error->errorCode);
        } catch (\Throwable $error) {
            error_log('[recupbuild] ' . $error);
            self::failed('server');
        }
        self::redirect('/inventory');
    }

    public function photo(): never
    {
        $this->member('/scan');
        $workspace = Workspace::get();
        if ($workspace !== null && $workspace['photo'] === Photos::DEMO) {
            self::redirect(Demo::PHOTO_URL);
        }
        Photos::send($workspace['photo'] ?? null);
    }

    public function reset(): never
    {
        $this->member('/scan');
        $this->checkForm('/scan');
        Workspace::clear();
        self::redirect('/scan');
    }

    public function inventory(): never
    {
        $this->member('/inventory');
        $workspace = $this->workspace();
        View::render('inventory', 'inventory.title', [
            'step' => 'inventory',
            'workspace' => $workspace,
            'photoUrl' => self::photoUrl($workspace),
            'issues' => Workspace::issues($workspace['pieces']),
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function saveInventory(): never
    {
        $this->member('/inventory');
        $this->checkForm('/inventory');
        $workspace = $this->workspace();
        $pieces = Workspace::fromForm($workspace['pieces'], $_POST['pieces'] ?? null);
        $ready = $pieces !== [] && Workspace::issues($pieces) === [];
        $continue = ($_POST['action'] ?? '') === 'continue';
        Workspace::setPieces($pieces, $ready && $continue);
        if ($ready && $continue) {
            self::redirect('/projects');
        }
        Session::flash($ready ? 'inventory.saved' : 'inventory.fix');
        self::redirect('/inventory');
    }

    public function projects(): never
    {
        $this->member('/projects');
        $workspace = $this->workspace();
        if (!$workspace['confirmed']) {
            self::redirect('/inventory');
        }

        $source = 'ai';
        $cards = $workspace['cards'];
        if ($cards === null) {
            [$cards, $source] = $this->cards($workspace);
            Workspace::setCards(['source' => $source, 'list' => $cards]);
        } else {
            [$cards, $source] = [$cards['list'], $cards['source']];
        }

        View::render('projects', 'projects.title', [
            'step' => 'projects',
            'cards' => $cards,
            'source' => $source,
            'demo' => $workspace['demo'],
            'pieces' => $workspace['pieces'],
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function choose(): never
    {
        $user = $this->member('/projects');
        $this->checkForm('/projects');
        $workspace = $this->workspace();
        $card = $workspace['cards']['list'][(int) ($_POST['card'] ?? -1)] ?? null;
        if (!is_array($card) || $card['result']['status'] !== 'feasible') {
            self::redirect('/projects');
        }

        $pdo = Database::get($this->config);
        $definition = $card['definition'];
        $pieces = Workspace::wire($workspace['pieces']);
        $params = $card['result']['plan']['params'];

        if ($workspace['demo']) {
            // The demo plans with the engine alone: the steps come from the language files.
            $result = Solver::solve($definition, $pieces, $params) + ['steps' => null];
        } else {
            if (Users::credits($pdo, $user['id'])['build'] <= 0) {
                Session::flash('projects.noCredit');
                self::redirect('/projects');
            }
            try {
                $result = Planner::plan($definition, $pieces, Request::locale(), $params, $this->config);
            } catch (VisionError $error) {
                error_log('[recupbuild] planner failed: ' . $error->getMessage());
                Session::flash('error.' . $error->errorCode);
                self::redirect('/projects');
            }
        }

        if ($result['status'] !== 'feasible') {
            View::render('plan-failed', 'plan.failedTitle', ['step' => 'plan', 'result' => $result]);
        }
        if (!$workspace['demo']) {
            Users::spend($pdo, $user['id'], 'build');
        }

        $outlines = [];
        foreach ($workspace['pieces'] as $piece) {
            $outlines[(int) $piece['id']] = $piece['outline'] ?? [];
        }
        $id = Plans::save($pdo, $user['id'], $definition, $pieces, $result, null, $workspace['photo'], $outlines, $workspace['image']);
        self::redirect('/plans/' . $id);
    }

    /**
     * The four cards: the model's projects for this wood, or the built-in projects
     * planned by the engine (the demo, or when the model cannot answer).
     *
     * @return array{0: list<array<string, mixed>>, 1: string} the cards and where they come from
     */
    private function cards(array $workspace): array
    {
        $pieces = Workspace::wire($workspace['pieces']);
        if (!$workspace['demo']) {
            try {
                $cards = Planner::projects($pieces, Request::locale(), $this->config)['projects'];

                return [self::withPreview($cards), 'ai'];
            } catch (VisionError $error) {
                error_log('[recupbuild] projects failed: ' . $error->getMessage());
            }
        }
        $cards = [];
        foreach (Projects::IDS as $id) {
            $cards[] = ['definition' => Projects::definition($id), 'result' => Solver::solve($id, $pieces)];
        }

        return [self::withPreview($cards), $workspace['demo'] ? 'demo' : 'engine'];
    }

    /** Each buildable card gets the 3D layout of its object, for its picture. */
    private static function withPreview(array $cards): array
    {
        foreach ($cards as $index => $card) {
            $plan = $card['result']['plan'] ?? null;
            $cards[$index]['assembly'] = $plan === null ? null : Assembly::of($plan, $card['result']['assembly'] ?? null);
        }

        return $cards;
    }

    /** The workspace of this session, or back to the photo. */
    private function workspace(): array
    {
        $workspace = Workspace::get();
        if ($workspace === null) {
            self::redirect('/scan');
        }

        return $workspace;
    }

    public static function photoUrl(array $workspace): string
    {
        return $workspace['photo'] === Photos::DEMO ? Demo::PHOTO_URL : '/scan/photo';
    }

    /** Back to the photo, saying what went wrong. */
    private static function failed(string $code): never
    {
        Session::flash('error.' . $code);
        self::redirect('/scan');
    }
}
