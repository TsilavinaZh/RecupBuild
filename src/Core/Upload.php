<?php

declare(strict_types=1);

namespace RecupBuild\Core;

/**
 * A photo sent in a form field: its bytes once the transfer and the size are known to
 * be fine, or the code of what went wrong (the codes the pages and the API explain).
 */
final class Upload
{
    /** @return array{0: ?string, 1: ?string} the bytes, or null and an error code */
    public static function read(string $field): array
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            return [null, 'unknown'];
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return [null, 'file_too_large'];
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return [null, 'unknown'];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return [null, 'server'];
        }
        if ((int) $file['size'] > Config::MAX_UPLOAD_BYTES) {
            return [null, 'file_too_large'];
        }
        $data = @file_get_contents((string) $file['tmp_name']);

        return $data === false ? [null, 'unsupported_type'] : [$data, null];
    }

    /** The status that goes with an error code, for the API. */
    public static function status(string $code): int
    {
        return match ($code) {
            'file_too_large' => 413,
            'unsupported_type' => 415,
            'server' => 500,
            default => 422,
        };
    }
}
