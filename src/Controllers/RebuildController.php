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
use RecupBuild\Models\Rebuilds;
use RecupBuild\Models\Users;
use RecupBuild\Services\Image;
use RecupBuild\Services\Reverse;

/**
 * The reverse build, as pages: from the photo of a finished object to what it takes
 * to make the same one. Each one is kept (Models\Rebuilds) and uses a rebuild credit.
 *
 *   GET  /rebuild              the photo (upload or one of the examples)
 *   POST /rebuild              analyses it, then its page
 *   GET  /rebuilds/{id}        the result: 3D, parts, hardware, tools, steps
 *   GET  /rebuilds/{id}/photo  the photo of the object
 */
final class RebuildController extends Controller
{
    /** The example objects in examples/, real photos for those who have none at hand. */
    public const EXAMPLES = ['table'];

    public function form(): never
    {
        $user = $this->member('/rebuild');
        View::render('rebuild', 'rebuild.title', [
            'credits' => Users::credits(Database::get($this->config), $user['id']),
            'examples' => self::EXAMPLES,
            'csrf' => Session::csrfToken(),
        ]);
    }

    public function analyse(): never
    {
        $user = $this->member('/rebuild');
        $this->checkForm('/rebuild');
        $pdo = Database::get($this->config);
        if (Users::credits($pdo, $user['id'])['rebuild'] <= 0) {
            Session::flash('rebuild.noCredit');
            self::redirect('/rebuild');
        }

        $example = $_POST['example'] ?? null;
        if (is_string($example) && in_array($example, self::EXAMPLES, true)) {
            $data = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/' . $example . '.jpg');
        } else {
            [$data, $error] = Upload::read('photo');
            if ($data === null) {
                self::failed($error ?? 'unknown');
            }
        }

        try {
            [$jpeg] = Image::prepare($data, $this->config);
            $result = Reverse::analyse($jpeg, Request::locale(), $this->config);
            Users::spend($pdo, $user['id'], 'rebuild');
            $id = Rebuilds::save($pdo, $user['id'], $result, Photos::store($jpeg));
        } catch (ScanError $error) {
            self::failed($error->errorCode === 'empty_segmentation' ? 'not_buildable' : $error->errorCode);
        } catch (VisionError $error) {
            error_log('[recupbuild] reverse failed: ' . $error->getMessage());
            self::failed($error->errorCode);
        } catch (\Throwable $error) {
            error_log('[recupbuild] ' . $error);
            self::failed('server');
        }
        self::redirect('/rebuilds/' . $id);
    }

    public function show(int $id): never
    {
        $user = $this->member('/rebuilds/' . $id);
        $rebuild = Rebuilds::find(Database::get($this->config), $user['id'], $id);
        if ($rebuild === null) {
            http_response_code(404);
            View::render('notfound', 'notfound.title');
        }
        View::render('rebuild-show', 'rebuild.title', ['rebuild' => $rebuild, 'wide' => true]);
    }

    public function photo(int $id): never
    {
        $user = $this->member('/history');
        $rebuild = Rebuilds::find(Database::get($this->config), $user['id'], $id);
        Photos::send($rebuild['photo'] ?? null);
    }

    private static function failed(string $code): never
    {
        Session::flash('error.' . $code);
        self::redirect('/rebuild');
    }
}
