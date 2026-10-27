<?php

declare(strict_types=1);

namespace RecupBuild\Controllers;

use RecupBuild\Core\Controller;
use RecupBuild\Core\Database;
use RecupBuild\Core\Http;
use RecupBuild\Core\Request;
use RecupBuild\Errors\ScanError;
use RecupBuild\Errors\VisionError;
use RecupBuild\Models\Users;
use RecupBuild\Services\Image;
use RecupBuild\Services\Reverse;
use RecupBuild\Services\Scan;
use RecupBuild\Services\Vision;

/**
 * The two photo routes (multipart field "file"), sharing the upload checks:
 *
 *   POST /api/scan     a heap of materials -> the A4 sheet and the measured pieces
 *   POST /api/reverse  a finished object -> the parts, tools and steps to build it
 *
 * A reverse build is a "rebuild": it uses one credit of the free offer, once it succeeded.
 */
final class ScanController extends Controller
{
    public function scan(): never
    {
        $this->user();
        $data = $this->upload();
        try {
            [$jpeg, $width, $height] = Image::prepare($data, $this->config);
            Http::json(200, Scan::buildResponse(Vision::analyse($jpeg, $this->config), $width, $height));
        } catch (ScanError $error) {
            Http::fail($error->errorCode, $error->status);
        } catch (VisionError $error) {
            $this->modelFailed('vision', $error);
        } catch (\Throwable $error) {
            $this->crashed($error);
        }
    }

    public function reverse(): never
    {
        $user = $this->user();
        $pdo = Database::get($this->config);
        // Refused before the model is paid for, not after.
        if (Users::credits($pdo, $user['id'])['rebuild'] <= 0) {
            Http::fail('quota_exhausted', 402);
        }
        $data = $this->upload();
        try {
            [$jpeg] = Image::prepare($data, $this->config);
            $result = Reverse::analyse($jpeg, Request::locale(), $this->config);
            Users::spend($pdo, $user['id'], 'rebuild');
            Http::json(200, $result + ['credits' => Users::credits($pdo, $user['id'])]);
        } catch (ScanError $error) {
            Http::fail($error->errorCode, $error->status);
        } catch (VisionError $error) {
            $this->modelFailed('vision', $error);
        } catch (\Throwable $error) {
            $this->crashed($error);
        }
    }

    /** The bytes of the uploaded photo, once its size and transfer are known to be fine. */
    private function upload(): string
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            Http::fail('unknown', 422);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            Http::fail('file_too_large', 413);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            Http::fail('unknown', 422);
        }
        if ($error !== UPLOAD_ERR_OK) {
            Http::fail('server', 500);
        }
        if ((int) $file['size'] > $this->config::MAX_UPLOAD_BYTES) {
            Http::fail('file_too_large', 413);
        }

        $data = @file_get_contents((string) $file['tmp_name']);
        if ($data === false) {
            Http::fail('unsupported_type', 415);
        }

        return $data;
    }
}
