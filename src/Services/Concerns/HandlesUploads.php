<?php

declare(strict_types=1);

namespace ApiGoat\Services\Concerns;

/**
 * Upload-directory hardening shared by every generated service that declares
 * `is_file_upload_table`.
 *
 * The emitter used to bake this method — plus a ~20-line .htaccess literal —
 * into each upload service. The bodies are identical apart from one boolean
 * (`private: true` / `no_direct_access`), so they live here and the emitter
 * passes the flag.
 *
 * Defense-in-depth behind the extension allowlist: even if a script-extension
 * file ever lands in an upload directory it must not execute, and an uploaded
 * .html/.svg must not render inline (stored XSS).
 */
trait HandlesUploads
{

    /**
     * Make sure $dir exists and carries its guard files. Bodies live in
     * ApiGoat\Storage\UploadGuards, shared with with_pdf's saved copies.
     *
     * $private defaults to TRUE: a generated upload directory is not web-
     * readable unless the table declares `public: true`.
     */
    private function ensureUploadDirGuards(string $dir, string $indexFile = '', bool $private = true): string
    {
        return \ApiGoat\Storage\UploadGuards::ensureDir($dir, $private, $indexFile);
    }
}
