<?php

declare(strict_types=1);

namespace ApiGoat\Storage;

/**
 * Upload-directory hardening, shared by every generated upload service
 * (HandlesUploads) and by with_pdf's saved copies (Pdf\PdfGenerator).
 *
 * Directories are PRIVATE by default: files under public/file/ are reachable
 * only through the auth-gated controllers (<Model>/open, /file, /pdfdownload),
 * which read from disk and are unaffected by a deny-all rule. They used to be
 * world-readable at a filename of md5(<row pk>) — a sequential integer — so the
 * whole store enumerated (security audit 2026-09-13, finding 1).
 *
 * The sentinel comment on the first line is what gc upgrade's migration reads
 * to tell a generated guard from a hand-written one, and a deliberate public
 * directory from one that simply predates this change.
 */
final class UploadGuards
{
    public const SENTINEL_PRIVATE = 'gc-upload-private';
    public const SENTINEL_PUBLIC  = 'gc-upload-public';

    /** Deny all direct web access. */
    private const BODY_PRIVATE =
          "# GoatCheese PRIVATE upload directory (gc-upload-private) - no direct web access.\n"
        . "# Files here are confidential; the only legitimate read path is\n"
        . "# the auth-gated controller (getFileContent), which reads from\n"
        . "# disk and is unaffected by this rule. Deny all HTTP access.\n"
        . "Require all denied\n"
        . "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
        . "<IfModule mod_php7.c>\nphp_flag engine off\n</IfModule>\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar .phps\n";

    /** Opt-in public directory: no script execution, html/svg forced to download. */
    private const BODY_PUBLIC =
          "# GoatCheese PUBLIC upload directory (gc-upload-public) - no script execution\n"
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

    public static function htaccessBody(bool $private): string
    {
        return $private ? self::BODY_PRIVATE : self::BODY_PUBLIC;
    }

    /**
     * Make sure $dir exists and carries its guard files.
     *
     * @return string '' on success, an error message otherwise.
     */
    public static function ensureDir(string $dir, bool $private = true, string $indexFile = ''): string
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return 'Cannot create directory: ' . $dir;
            }
        }
        if ($indexFile !== '' && !file_exists($indexFile)) {
            $site = defined('_SITE_URL') ? (string) _SITE_URL : '/';
            if (@file_put_contents($indexFile, '<?php header(\'Location:' . $site . '\'); ') === false) {
                return 'Cannot write guard: ' . $indexFile;
            }
        }
        $ht = rtrim($dir, '/') . '/.htaccess';
        if (!file_exists($ht)) {
            if (@file_put_contents($ht, self::htaccessBody($private)) === false) {
                return 'Cannot write guard: ' . $ht;
            }
        }

        return '';
    }
}
