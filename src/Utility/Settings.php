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
        return self::$cache ??= require _BASE_DIR . 'config/settings.php';
    }
}
