<?php

namespace ApiGoat\Utility;

/**
 * Request-scoped cache for the project settings array.
 *
 * Several hot-path classes (Assets ×3 per page, BuilderLayout, RbacMiddleware,
 * the OAuth services) each did `require _BASE_DIR . 'config/settings.php'`
 * directly — and that file re-reads + re-parses .env from disk and rebuilds
 * the whole settings array on every require. settings.php is per-project (not
 * drift-synced), so the caching lives here in the shared runtime instead.
 */
final class Settings
{
    private static ?array $cache = null;

    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // _BASE_DIR is defined by the web front controller, not by the
        // autoloader. Anything running outside a request — the standalone
        // scripts under tests/, bin/ tooling, a `gc build` step — has no
        // _BASE_DIR, and an unguarded `require _BASE_DIR . ...` fatals there
        // with "Undefined constant ApiGoat\Utility\_BASE_DIR" (PHP reports
        // the namespaced name even after the global fallback).
        //
        // That is not hypothetical: AuthyMiddleware::isProjectSelfServiceAction()
        // calls this, so tests/Security/AccountSelfServiceRbacTest.php died
        // partway through in every project — while `gc build` still exited 0,
        // so a security test was silently not running.
        //
        // Guard like PdfManifest / StripeManifest / AiManifest do, and do NOT
        // cache the empty result: a later call from a properly bootstrapped
        // context must still get the real settings.
        if (!\defined('_BASE_DIR')) {
            return [];
        }
        $path = _BASE_DIR . 'config/settings.php';
        if (!\is_file($path)) {
            return [];
        }

        return self::$cache = require $path;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$cache = null;
    }
}
