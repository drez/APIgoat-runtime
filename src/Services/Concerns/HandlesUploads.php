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
     * .htaccess for a PRIVATE upload directory: deny all direct web access.
     *
     * Files there are confidential; the only legitimate read path is the
     * auth-gated controller (getFileContent), which reads from disk and is
     * unaffected by this rule.
     */
    private const GC_UPLOAD_HTACCESS_PRIVATE =
          "# GoatCheese PRIVATE upload directory - no direct web access.\n"
        . "# Files here are confidential; the only legitimate read path is\n"
        . "# the auth-gated controller (getFileContent), which reads from\n"
        . "# disk and is unaffected by this rule. Deny all HTTP access.\n"
        . "Require all denied\n"
        . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n"
        . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|phps)$\">\n"
        . "SetHandler none\nRequire all denied\n</FilesMatch>\n";

    /**
     * .htaccess for a PUBLIC upload directory: no script execution, and
     * inline-renderable types (html/svg/xml) are served as text/plain +
     * attachment. Images/PDF still render inline.
     */
    private const GC_UPLOAD_HTACCESS_PUBLIC =
          "# GoatCheese upload directory - no script execution\n"
        . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n"
        . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|phps)$\">\n"
        . "SetHandler none\nRequire all denied\n</FilesMatch>\n"
        . "<FilesMatch \"\\.(html?|xhtml|svgz?|xml)$\">\n"
        . "ForceType text/plain\n"
        . "<IfModule mod_headers.c>\nHeader set Content-Disposition attachment\nHeader set X-Content-Type-Options nosniff\n</IfModule>\n"
        . "</FilesMatch>\n";

    /**
     * Make sure $dir exists and carries its guard files.
     *
     * @param string $dir       Absolute directory that must exist and be guarded.
     * @param string $indexFile Optional index.php redirect stub to drop (''=skip).
     * @param bool   $private   true => deny-all .htaccess (private:true tables).
     *
     * @return string '' on success, an error message otherwise.
     */
    private function ensureUploadDirGuards(string $dir, string $indexFile = '', bool $private = false): string
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return 'Cannot create directory: ' . $dir;
            }
        }
        if ($indexFile !== '' && !file_exists($indexFile)) {
            $fp = @fopen($indexFile, 'w');
            if ($fp === false) {
                return 'Cannot write guard: ' . $indexFile;
            }
            fwrite($fp, '<?php header(\'Location:' . _SITE_URL . '\'); ');
            fclose($fp);
        }
        $gcHt = rtrim($dir, '/') . '/.htaccess';
        if (!file_exists($gcHt)) {
            $body = $private ? self::GC_UPLOAD_HTACCESS_PRIVATE : self::GC_UPLOAD_HTACCESS_PUBLIC;
            if (@file_put_contents($gcHt, $body) === false) {
                return 'Cannot write guard: ' . $gcHt;
            }
        }

        return '';
    }
}
